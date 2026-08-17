<?php

namespace App\Services\PettyCash;

use App\Models\AuditLog;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\RealizationEntry;
use App\Models\User;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PettyCashVoucherPostingService
{
    public function __construct(
        private RealizationEntryService $realizationService,
    ) {}

    /**
     * BR-AUTH-001: sama seperti PettyCashVoucherService::unitMismatch().
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
     * Upload scan bertanda tangan → voucher terkunci (posted) → 1 realization_entry
     * per baris dibuat otomatis lewat RealizationEntryService::store() (reuse, lihat
     * rasional arsitektur di plan). 4 fase:
     *  1. Guard murah SEBELUM menyentuh S3 (auth, status, isi voucher).
     *  2. Upload file DI LUAR transaksi DB — supaya lock PdoHeader tidak ditahan
     *     selama PUT S3 multi-detik.
     *  3. DB::transaction: lock PDO + voucher, re-cek draft DI DALAM lock (anti
     *     double-processing), loop baris → store() → link realization_entry_id.
     *  4. catch: hapus file yatim kalau transaksi gagal (BR-REAL-002 jebol dsb).
     */
    public function postWithScan(PettyCashVoucher $voucher, UploadedFile $file, User $actor): PettyCashVoucher
    {
        $pdo = $voucher->pdoHeader;

        // Fase 1 — guard murah
        if (! $pdo || $pdo->company_id !== $actor->company_id || $this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Data tidak ditemukan.'],
            ], 404));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Tidak bisa mengunggah scan setelah PDO ditutup.'],
            ], 409));
        }

        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Voucher hanya bisa diposting saat PDO berstatus final.'],
            ], 409));
        }

        if (! $voucher->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_ALREADY_POSTED', 'message' => 'Voucher ini sudah diposting sebelumnya.'],
            ], 409));
        }

        $lines = $voucher->lines()->orderBy('line_no')->get();
        if ($lines->isEmpty()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_EMPTY', 'message' => 'Voucher tidak punya baris item — tidak bisa diposting.'],
            ], 422));
        }

        // Fase 2 — upload DI LUAR transaksi
        $disk  = config('filesystems.default');
        $year  = now()->year;
        $month = str_pad((string) now()->month, 2, '0', STR_PAD_LEFT);
        $ext   = $file->getClientOriginalExtension();
        $path  = "petty-cash-voucher-scans/{$year}/{$month}/" . Str::uuid() . ".{$ext}";

        Storage::disk($disk)->put($path, $file->getContent(), 'private');

        try {
            return DB::transaction(function () use ($voucher, $pdo, $lines, $actor, $file, $disk, $path) {
                $pdo     = PdoHeader::lockForUpdate()->findOrFail($pdo->id);
                $voucher = PettyCashVoucher::withoutGlobalScopes()->lockForUpdate()->findOrFail($voucher->id);

                // Re-cek DI DALAM lock — anti double-processing (mis. double-click / retry).
                if (! $voucher->isDraft()) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => ['code' => 'VOUCHER_ALREADY_POSTED', 'message' => 'Voucher ini sudah diposting sebelumnya.'],
                    ], 409));
                }

                $total = 0;
                foreach ($lines as $line) {
                    $entry = $this->realizationService->store([
                        'pdo_detail_id'                 => $line->pdo_detail_id,
                        'vehicle_id'                     => $line->vehicle_id,
                        'transaction_date'                => $voucher->voucher_date->toDateString(),
                        'amount'                          => $line->amount,
                        'payment_method'                  => RealizationEntry::PAYMENT_TUNAI,
                        'proof_number'                    => '',
                        'funding_source'                  => RealizationEntry::FUNDING_KAS_KEBUN,
                        'explanation'                      => $line->description,
                        '_from_petty_cash_voucher'         => $voucher->id,
                    ], $actor);

                    $line->update(['realization_entry_id' => $entry->id]);
                    $total += $line->amount;
                }

                $voucher->update([
                    'status'               => PettyCashVoucher::STATUS_POSTED,
                    'total_amount'         => $total,
                    'scan_file_name'       => $file->getClientOriginalName(),
                    'scan_file_path'       => $path,
                    'scan_disk'            => $disk,
                    'scan_mime_type'       => $file->getMimeType(),
                    'scan_file_size_bytes' => $file->getSize(),
                    'scan_uploaded_by'     => $actor->id,
                    'scan_uploaded_at'     => now(),
                    'posted_at'            => now(),
                    'posted_by'            => $actor->id,
                ]);

                AuditLog::record(
                    actor: $actor,
                    entityType: 'petty_cash_vouchers',
                    entityId: $voucher->id,
                    action: 'UPDATE',
                    oldValues: ['status' => PettyCashVoucher::STATUS_DRAFT],
                    newValues: [
                        'status'                 => PettyCashVoucher::STATUS_POSTED,
                        'realization_entry_ids'  => $voucher->lines()->pluck('realization_entry_id'),
                    ]
                );

                return $voucher->fresh()->load(['lines.pdoDetail.expenseItem', 'lines.realizationEntry']);
            });
        } catch (\Throwable $e) {
            rescue(fn () => Storage::disk($disk)->delete($path), report: true);
            throw $e;
        }
    }
}
