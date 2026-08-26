<?php

namespace App\Services\PettyCash;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\PettyCashVoucherLine;
use App\Models\RealizationEntry;
use App\Models\User;
use App\Services\Realization\RealizationEntryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PettyCashVoucherService
{
    public function __construct(
        private RealizationEntryService $realizationService,
    ) {}

    /**
     * BR-AUTH-001: sama seperti RealizationEntryService::unitMismatch() — actor tanpa
     * plantation_unit_id (role cross-unit) selalu lolos; pakai app('current_unit_ids')
     * (unit sendiri + unit yang di-link) kalau tersedia.
     */
    private function unitMismatch(PdoHeader $pdo, User $actor): bool
    {
        if (! $actor->plantation_unit_id) {
            return false;
        }

        $allowedUnitIds = app()->bound('current_unit_ids')
            ? app('current_unit_ids')
            : [$actor->plantation_unit_id];

        return ! in_array($pdo->plantation_unit_id, $allowedUnitIds, true);
    }

    /**
     * Petty Cash Voucher hanya untuk kantong kebun (KERANI). Actor lain (pribadi/vendor,
     * atau role tanpa kantong) tidak berhak membuat/mengubah/menghapus voucher.
     *
     * $pdo nullable: dipanggil dengan hasil lazy-load $voucher->pdoHeader, yang bisa null
     * bukan cuma karena data tidak ada tapi juga karena di-filter keluar oleh global scope
     * 'unit_access' milik PdoHeader sendiri (lihat catatan di find()). Null → 404 langsung,
     * sebelum cek role, supaya tidak membocorkan keberadaan voucher unit lain lewat pesan error.
     */
    private function authorizeActor(?PdoHeader $pdo, User $actor): void
    {
        if (! $pdo) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Data tidak ditemukan.'],
            ], 404));
        }

        if (! $actor->canRecordRealization() || $actor->realizationSettlementGroup() !== RealizationEntry::SETTLEMENT_KEBUN) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak mengelola Petty Cash Voucher.'],
            ], 403));
        }

        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke PDO ini.'],
            ], 403));
        }

        if ($this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Voucher hanya bisa dikelola untuk PDO unit Anda sendiri.'],
            ], 403));
        }
    }

    private function assertDateWithinPeriod(PdoHeader $pdo, string $voucherDate): void
    {
        $date = Carbon::parse($voucherDate);

        if ((int) $date->year !== (int) $pdo->period_year || (int) $date->month !== (int) $pdo->period_month) {
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'VOUCHER_DATE_OUT_OF_PERIOD',
                    'message' => "Tanggal voucher harus dalam periode PDO ({$pdo->period_month}/{$pdo->period_year}).",
                ],
            ], 422));
        }
    }

    /**
     * Validasi 1 baris voucher dan kembalikan data yang siap disimpan.
     *
     * ⚠️ SENGAJA TIDAK memfilter/menolak berdasarkan `saldo` item (lihat §0-bis pada
     * plan). Untuk kantong kebun, realisasi 1 item boleh melebihi anggarannya sendiri
     * selama total kantong PDO masih cukup — validasi itu ada di assertWithinKantong(),
     * bukan di sini.
     */
    private function validateLine(PdoHeader $pdo, array $raw): array
    {
        $detail = PdoDetail::with(['expenseItem', 'transferEntries'])
            ->where('pdo_header_id', $pdo->id)
            ->find($raw['pdo_detail_id'] ?? null);

        if (! $detail) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_ITEM_NOT_IN_PDO', 'message' => 'Item yang dipilih bukan bagian dari PDO ini.'],
            ], 422));
        }

        $item = $detail->expenseItem;

        if ($item?->is_deduction) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DEDUCTION_NOT_REALIZABLE', 'message' => 'Item potongan tidak bisa dimasukkan ke voucher.'],
            ], 403));
        }

        if ($item?->is_fund_return) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FUND_RETURN_NOT_IN_VOUCHER', 'message' => 'Pengembalian sisa dana bulan lalu tidak melalui voucher — catat langsung di form Input Realisasi.'],
            ], 422));
        }

        $hasTransferToKebun = $detail->transferEntries->contains(
            fn ($t) => $t->transfer_destination === 'rek_kebun'
        );

        if (! $hasTransferToKebun) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_ITEM_NOT_AVAILABLE', 'message' => 'Item ini tidak punya transfer ke kantong Kas Kebun.'],
            ], 422));
        }

        $amount = (int) ($raw['amount'] ?? 0);

        if ($amount < 1) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_ITEM_NOT_AVAILABLE', 'message' => 'Jumlah baris voucher harus lebih dari 0.'],
            ], 422));
        }

        return [
            'pdo_detail_id' => $detail->id,
            'vehicle_id'    => $raw['vehicle_id'] ?? null,
            'description'   => (string) ($raw['description'] ?? $detail->description),
            'amount'        => $amount,
        ];
    }

    /**
     * Sisa kantong Kas Kebun yang bisa dipakai voucher baru = sisa dana kantong
     * (saldo awal + transfer − realisasi; BR-REAL-002, lihat
     * RealizationEntryService::remainingKantongForGroup()) − total baris voucher
     * DRAFT lain di PDO ini (reservasi, lihat catatan di §3b plan).
     * $excludeVoucherId dipakai saat update() supaya voucher yang sedang diedit
     * tidak menghitung dirinya sendiri dua kali.
     */
    public function remainingKantongForVoucher(PdoHeader $pdo, ?string $excludeVoucherId = null): int
    {
        $remainingKantong = $this->realizationService->remainingKantongForGroup(
            $pdo, RealizationEntry::SETTLEMENT_KEBUN
        );

        $reservedDraft = (int) PettyCashVoucherLine::whereHas('pettyCashVoucher', function ($q) use ($pdo, $excludeVoucherId) {
            $q->where('pdo_header_id', $pdo->id)->where('status', PettyCashVoucher::STATUS_DRAFT);
            if ($excludeVoucherId) {
                $q->where('id', '!=', $excludeVoucherId);
            }
        })->sum('amount');

        return $remainingKantong - $reservedDraft;
    }

    private function assertWithinKantong(PdoHeader $pdo, int $total, ?string $excludeVoucherId): void
    {
        $remaining = $this->remainingKantongForVoucher($pdo, $excludeVoucherId);

        if ($total > $remaining) {
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'VOUCHER_EXCEEDS_KANTONG',
                    'message' => 'Total voucher (Rp ' . number_format($total, 0, ',', '.')
                        . ') melebihi sisa kantong Kas Kebun (Rp ' . number_format($remaining, 0, ',', '.') . ').',
                ],
            ], 422));
        }
    }

    /**
     * Nomor voucher berikutnya untuk satu PDO header. Format: PCV/{pdo_number}/{seq}.
     * Logika identik RealizationEntryService::nextProofNumber() — WAJIB dipanggil di
     * dalam transaksi setelah PdoHeader::lockForUpdate(). Backstop: UNIQUE index
     * voucher_number.
     */
    public function nextVoucherNumber(PdoHeader $pdo): string
    {
        $existing = PettyCashVoucher::withoutGlobalScopes()
            ->where('pdo_header_id', $pdo->id)
            ->pluck('voucher_number');

        $maxSeq = 0;
        foreach ($existing as $voucherNumber) {
            if (preg_match('/\/(\d+)$/', (string) $voucherNumber, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }

        return "PCV/{$pdo->pdo_number}/" . ($maxSeq + 1);
    }

    /**
     * Ambil 1 voucher untuk ditampilkan (GET show). Route model binding implisit
     * ({voucher}) di-resolve oleh SubstituteBindings SEBELUM EnsureUnitAccess sempat
     * mem-bind app('current_unit_ids') untuk request ini — jadi global scope
     * 'unit_access' pada model TIDAK bisa diandalkan sendirian untuk lookup per-ID
     * seperti ini (beda dengan query list yang dieksekusi belakangan, setelah
     * middleware selesai). Makanya perlu cek eksplisit di sini, sama seperti
     * update()/destroy() — jangan tiru PdoDetailAttachmentController::download()
     * yang tidak punya cek apa pun (lihat catatan IDOR di plan). 404 (bukan 403)
     * supaya keberadaan voucher milik unit lain tidak bocor ke actor yang salah.
     */
    /**
     * PdoHeader punya global scope 'unit_access' sendiri (lihat PdoHeader::booted()),
     * jadi relasi $voucher->pdoHeader bisa null bukan cuma karena data tidak ada, tapi
     * juga karena di-filter keluar oleh scope saat lazy-load (dieksekusi di sini, setelah
     * EnsureUnitAccess selesai untuk request ini — beda dari resolusi {voucher} lewat
     * route-model-binding yang terjadi lebih awal, sebelum current_unit_ids ter-bind).
     * Null berarti actor tidak berhak lihat PDO ini → 404, bukan error 500. Dipakai untuk
     * semua akses baca (show, cetak, lihat scan) — jangan tiru
     * PdoDetailAttachmentController::download() yang tidak punya cek apa pun.
     */
    public function authorizeAccess(PettyCashVoucher $voucher, User $actor): PdoHeader
    {
        $pdo = $voucher->pdoHeader;

        if (! $pdo || $pdo->company_id !== $actor->company_id || $this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Data tidak ditemukan.'],
            ], 404));
        }

        return $pdo;
    }

    public function find(PettyCashVoucher $voucher, User $actor): PettyCashVoucher
    {
        $this->authorizeAccess($voucher, $actor);

        return $voucher->load(['lines.pdoDetail.expenseItem', 'lines.vehicle', 'creator', 'scanUploader', 'poster']);
    }

    public function listForPdo(PdoHeader $pdo, User $actor): Collection
    {
        $this->authorizeActor($pdo, $actor);

        return PettyCashVoucher::where('pdo_header_id', $pdo->id)
            ->with(['lines.pdoDetail.expenseItem', 'creator', 'scanUploader'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(array $data, User $actor): PettyCashVoucher
    {
        $pdo = PdoHeader::findOrFail($data['pdo_header_id']);
        $this->authorizeActor($pdo, $actor);

        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Voucher hanya bisa dibuat saat PDO berstatus final.'],
            ], 409));
        }

        $this->assertDateWithinPeriod($pdo, $data['voucher_date']);

        return DB::transaction(function () use ($pdo, $data, $actor) {
            $pdo = PdoHeader::lockForUpdate()->findOrFail($pdo->id);

            $lines = array_map(fn ($raw) => $this->validateLine($pdo, $raw), $data['lines']);
            $total = array_sum(array_column($lines, 'amount'));

            $this->assertWithinKantong($pdo, $total, null);

            $voucher = PettyCashVoucher::create([
                'pdo_header_id'  => $pdo->id,
                'voucher_number' => $this->nextVoucherNumber($pdo),
                'paid_to'        => $data['paid_to'],
                'voucher_date'   => $data['voucher_date'],
                'status'         => PettyCashVoucher::STATUS_DRAFT,
                'total_amount'   => $total,
                'created_by'     => $actor->id,
            ]);

            foreach ($lines as $i => $line) {
                PettyCashVoucherLine::create([
                    'petty_cash_voucher_id' => $voucher->id,
                    'pdo_detail_id'         => $line['pdo_detail_id'],
                    'vehicle_id'            => $line['vehicle_id'],
                    'line_no'               => $i + 1,
                    'description'           => $line['description'],
                    'amount'                => $line['amount'],
                ]);
            }

            AuditLog::record(
                actor: $actor,
                entityType: 'petty_cash_vouchers',
                entityId: $voucher->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $voucher->load('lines')->toArray()
            );

            return $voucher->load('lines.pdoDetail.expenseItem');
        });
    }

    public function update(PettyCashVoucher $voucher, array $data, User $actor): PettyCashVoucher
    {
        $pdo = $voucher->pdoHeader;
        $this->authorizeActor($pdo, $actor);

        if (! $voucher->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_NOT_DRAFT', 'message' => 'Voucher yang sudah terkunci (posted) tidak bisa diubah.'],
            ], 409));
        }

        if (isset($data['voucher_date'])) {
            $this->assertDateWithinPeriod($pdo, $data['voucher_date']);
        }

        return DB::transaction(function () use ($voucher, $data, $actor, $pdo) {
            $voucher = PettyCashVoucher::withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->id);
            $pdo     = PdoHeader::lockForUpdate()->findOrFail($pdo->id);

            if (! $voucher->isDraft()) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'VOUCHER_NOT_DRAFT', 'message' => 'Voucher yang sudah terkunci (posted) tidak bisa diubah.'],
                ], 409));
            }

            $old = $voucher->load('lines')->toArray();

            if (isset($data['lines'])) {
                $lines = array_map(fn ($raw) => $this->validateLine($pdo, $raw), $data['lines']);
                $total = array_sum(array_column($lines, 'amount'));

                $this->assertWithinKantong($pdo, $total, $voucher->id);

                $voucher->lines()->delete();
                foreach ($lines as $i => $line) {
                    PettyCashVoucherLine::create([
                        'petty_cash_voucher_id' => $voucher->id,
                        'pdo_detail_id'         => $line['pdo_detail_id'],
                        'vehicle_id'            => $line['vehicle_id'],
                        'line_no'               => $i + 1,
                        'description'           => $line['description'],
                        'amount'                => $line['amount'],
                    ]);
                }
                $voucher->total_amount = $total;
            }

            if (isset($data['paid_to'])) {
                $voucher->paid_to = $data['paid_to'];
            }
            if (isset($data['voucher_date'])) {
                $voucher->voucher_date = $data['voucher_date'];
            }
            $voucher->save();

            AuditLog::record(
                actor: $actor,
                entityType: 'petty_cash_vouchers',
                entityId: $voucher->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $voucher->fresh()->load('lines')->toArray()
            );

            return $voucher->fresh()->load('lines.pdoDetail.expenseItem');
        });
    }

    public function destroy(PettyCashVoucher $voucher, User $actor): void
    {
        $pdo = $voucher->pdoHeader;
        $this->authorizeActor($pdo, $actor);

        if (! $voucher->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_NOT_DRAFT', 'message' => 'Voucher yang sudah terkunci (posted) tidak bisa dihapus.'],
            ], 409));
        }

        $old = $voucher->load('lines')->toArray();
        $voucher->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'petty_cash_vouchers',
            entityId: $voucher->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }
}
