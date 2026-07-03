<?php

namespace App\Services\Transfer;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class TransferEntryService
{
    /**
     * Daftar semua transfer dalam perusahaan (untuk halaman Transfer Dana).
     */
    public function listAll(User $actor): Collection
    {
        return TransferEntry::with(['pdoDetail.expenseItem', 'pdoDetail.pdoHeader.plantationUnit', 'recorder'])
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->orderByDesc('transfer_date')
            ->get();
    }

    /**
     * Daftar transfer per pdo_detail, diurutkan tanggal.
     */
    public function listByDetail(PdoDetail $detail): Collection
    {
        return $detail->transferEntries()
            ->with('recorder')
            ->orderBy('transfer_date')
            ->get();
    }

    /**
     * Summary transfer seluruh detail dalam satu PDO.
     * Digunakan di endpoint GET /pdo/{pdo}/transfers.
     *
     * Memisahkan transfer committed (final) dan draft, masing-masing dengan
     * breakdown per tujuan, untuk halaman Detail Transfer dan ekspor Excel.
     */
    public function summaryByPdo(PdoHeader $pdo): SupportCollection
    {
        // Relasi transferEntries auto-scoped ke committed (global scope).
        $details = $pdo->details()
            ->with(['expenseItem.subcategory.category', 'transferEntries.recorder'])
            ->get();

        // Muat semua draft PDO ini sekaligus, dikelompokkan per pdo_detail_id.
        $draftsByDetail = TransferEntry::onlyDrafts()
            ->with('recorder')
            ->whereIn('pdo_detail_id', $details->pluck('id'))
            ->get()
            ->groupBy('pdo_detail_id');

        return $details->map(function ($detail) use ($draftsByDetail) {
            $committed = collect($detail->transferEntries);
            $drafts    = collect($draftsByDetail->get($detail->id, collect()));

            $finalTotal = (int) $committed->sum('amount');
            $draftTotal = (int) $drafts->sum('amount');

            return [
                'pdo_detail_id'    => $detail->id,
                'expense_item'     => $detail->expenseItem
                    ? array_merge(
                        $detail->expenseItem->only(['id', 'code', 'name']),
                        [
                            'is_deduction'                       => (bool) $detail->expenseItem->is_deduction,
                            'split_transfer'                     => (bool) $detail->expenseItem->split_transfer,
                            'split_transfer_plantation_unit_ids' => $detail->expenseItem->split_transfer_plantation_unit_ids,
                        ]
                    )
                    : null,
                'category'         => $detail->expenseItem?->subcategory?->category
                    ? $detail->expenseItem->subcategory->category->only(['code', 'name'])
                    : null,
                'subcategory'      => $detail->expenseItem?->subcategory
                    ? $detail->expenseItem->subcategory->only(['code', 'name'])
                    : null,
                'description'      => $detail->description,
                'amount_approved'  => $detail->amount,
                // Committed (final) — nilai yang dihitung di halaman/laporan lain
                'total_transferred'=> $finalTotal,
                'final_by_dest'    => $this->breakdownByDest($committed),
                // Draft — belum permanen
                'draft_total'      => $draftTotal,
                'draft_by_dest'    => $this->breakdownByDest($drafts),
                // Gabungan final + draft
                'combined_total'   => $finalTotal + $draftTotal,
                'remaining'        => $detail->amount - $finalTotal - $draftTotal,
                'entries'          => $committed->values(),
                'draft_entries'    => $drafts->values(),
            ];
        });
    }

    /** Breakdown jumlah per tujuan transfer dari kumpulan entri. */
    private function breakdownByDest(SupportCollection $entries): array
    {
        return [
            TransferEntry::DEST_REK_KEBUN => (int) $entries->where('transfer_destination', TransferEntry::DEST_REK_KEBUN)->sum('amount'),
            TransferEntry::DEST_PRIBADI   => (int) $entries->where('transfer_destination', TransferEntry::DEST_PRIBADI)->sum('amount'),
            TransferEntry::DEST_VENDOR    => (int) $entries->where('transfer_destination', TransferEntry::DEST_VENDOR)->sum('amount'),
        ];
    }

    /**
     * Catat transfer manual baru.
     * BR-TRANSFER-001: hanya boleh saat PDO berstatus final.
     * BR-TRANSFER-002: total transfer tidak boleh melebihi amount PDO detail.
     */
    public function store(PdoDetail $detail, array $data, User $actor): TransferEntry
    {
        $pdo = $detail->pdoHeader;

        // BR-TRANSFER-001
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Transfer hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        return DB::transaction(function () use ($detail, $data, $actor) {
            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            // BR-TRANSFER-002: cek agar tidak over-transfer
            $currentTotal = $detail->transferEntries()->sum('amount');
            $newTotal     = $currentTotal + $data['amount'];

            if ($newTotal > $detail->amount) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'TRANSFER_EXCEEDS_BUDGET',
                        'message' => "Total transfer ({$newTotal}) melebihi jumlah yang disetujui ({$detail->amount}).",
                    ],
                ], 422));
            }

            $entry = TransferEntry::create([
                'pdo_detail_id'        => $detail->id,
                'recorded_by'          => $actor->id,
                'entry_source'         => TransferEntry::SOURCE_MANUAL,
                'is_auto_generated'    => false,
                'transfer_date'        => $data['transfer_date'],
                'amount'               => $data['amount'],
                'reference_number'     => $data['reference_number'],
                'notes'                => $data['notes'] ?? null,
                'transfer_destination' => $data['transfer_destination'] ?? TransferEntry::DEST_REK_KEBUN,
            ]);

            AuditLog::record(
                actor: $actor,
                entityType: 'transfer_entries',
                entityId: $entry->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $entry->toArray()
            );

            return $entry->load('recorder');
        });
    }

    /**
     * Sinkronisasi draft transfer seluruh PDO dari form (single source of truth).
     * SEMUA draft PDO yang lama dihapus, lalu diganti dengan entri dari form.
     * Entri committed (permanen) tidak tersentuh.
     * Hanya simpan entry dengan amount > 0.
     *
     * BR-TRANSFER-002: total (committed + draft baru) tidak boleh melebihi amount detail.
     */
    public function storeBulk(PdoHeader $pdo, array $entries, User $actor): array
    {
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Transfer hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        $results = [];

        DB::transaction(function () use ($pdo, $entries, $actor, &$results) {
            // Hapus semua draft lama PDO ini — form akan menggantinya sepenuhnya.
            TransferEntry::onlyDrafts()
                ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->get()
                ->each(function ($old) use ($actor) {
                    $oldArr = $old->toArray();
                    $old->delete();
                    AuditLog::record(
                        actor: $actor,
                        entityType: 'transfer_entries',
                        entityId: $oldArr['id'],
                        action: 'DELETE',
                        oldValues: $oldArr,
                        newValues: null
                    );
                });

            // Akumulasi draft baru per detail untuk validasi budget (mendukung split).
            $draftPerDetail = [];

            foreach ($entries as $row) {
                if (($row['amount'] ?? 0) <= 0) continue;

                $detail = PdoDetail::where('pdo_header_id', $pdo->id)
                    ->lockForUpdate()
                    ->findOrFail($row['pdo_detail_id']);

                $committedTotal = (int) $detail->transferEntries()->sum('amount'); // scoped: committed only
                $draftPerDetail[$detail->id] = ($draftPerDetail[$detail->id] ?? 0) + (int) $row['amount'];
                $newTotal = $committedTotal + $draftPerDetail[$detail->id];

                if ($newTotal > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'TRANSFER_EXCEEDS_BUDGET',
                            'message' => "Item '{$detail->description}': total transfer ({$newTotal}) melebihi jumlah disetujui ({$detail->amount}).",
                        ],
                    ], 422));
                }

                $entry = TransferEntry::create([
                    'pdo_detail_id'        => $detail->id,
                    'recorded_by'          => $actor->id,
                    'entry_source'         => TransferEntry::SOURCE_MANUAL,
                    'is_auto_generated'    => false,
                    'status'               => TransferEntry::STATUS_DRAFT,
                    'transfer_date'        => $row['transfer_date'],
                    'amount'               => $row['amount'],
                    'reference_number'     => $row['reference_number'] ?? null,
                    'notes'                => $row['notes'] ?? null,
                    'transfer_destination' => $row['transfer_destination'] ?? TransferEntry::DEST_REK_KEBUN,
                ]);

                AuditLog::record(
                    actor: $actor,
                    entityType: 'transfer_entries',
                    entityId: $entry->id,
                    action: 'INSERT',
                    oldValues: null,
                    newValues: $entry->toArray()
                );

                $results[] = $entry->load('recorder');
            }
        });

        return $results;
    }

    /**
     * Total dana yang sudah dialokasikan untuk sebuah detail
     * = committed + draft. Dipakai untuk validasi budget.
     */
    private function detailAllocatedTotal(string $detailId): int
    {
        return (int) TransferEntry::withDrafts()
            ->where('pdo_detail_id', $detailId)
            ->sum('amount');
    }

    /**
     * Ubah entri draft (nominal, tujuan, tanggal, dsb).
     * Hanya entri draft yang bisa diubah.
     */
    public function updateDraft(PdoHeader $pdo, string $entryId, array $data, User $actor): TransferEntry
    {
        return DB::transaction(function () use ($pdo, $entryId, $data, $actor) {
            $entry = TransferEntry::onlyDrafts()
                ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->lockForUpdate()
                ->findOrFail($entryId);

            $detail = PdoDetail::lockForUpdate()->findOrFail($entry->pdo_detail_id);

            // Validasi budget: total lain (committed + draft selain entri ini) + nominal baru
            if (isset($data['amount'])) {
                $othersTotal = TransferEntry::withDrafts()
                    ->where('pdo_detail_id', $detail->id)
                    ->where('id', '!=', $entry->id)
                    ->sum('amount');
                $newTotal = $othersTotal + $data['amount'];

                if ($newTotal > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'TRANSFER_EXCEEDS_BUDGET',
                            'message' => "Total transfer ({$newTotal}) melebihi jumlah yang disetujui ({$detail->amount}).",
                        ],
                    ], 422));
                }
            }

            $old = $entry->toArray();
            $entry->update($data);

            AuditLog::record(
                actor: $actor,
                entityType: 'transfer_entries',
                entityId: $entry->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $entry->fresh()->toArray()
            );

            return $entry->fresh()->load('recorder');
        });
    }

    /**
     * Hapus entri draft. Entri committed tidak bisa dihapus lewat sini.
     */
    public function deleteDraft(PdoHeader $pdo, string $entryId, User $actor): void
    {
        DB::transaction(function () use ($pdo, $entryId, $actor) {
            $entry = TransferEntry::onlyDrafts()
                ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->findOrFail($entryId);

            $old = $entry->toArray();
            $entry->delete();

            AuditLog::record(
                actor: $actor,
                entityType: 'transfer_entries',
                entityId: $entryId,
                action: 'DELETE',
                oldValues: $old,
                newValues: null
            );
        });
    }

    /**
     * Simpan permanen: ubah SEMUA draft PDO menjadi committed sekaligus.
     * Re-validasi budget per detail sebelum commit.
     */
    public function commitDrafts(PdoHeader $pdo, User $actor): int
    {
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Transfer hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        return DB::transaction(function () use ($pdo, $actor) {
            $drafts = TransferEntry::onlyDrafts()
                ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->lockForUpdate()
                ->get();

            if ($drafts->isEmpty()) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'NO_DRAFTS', 'message' => 'Tidak ada draft transfer untuk disimpan permanen.'],
                ], 422));
            }

            // Re-validasi budget per detail (committed + draft ≤ amount)
            foreach ($drafts->groupBy('pdo_detail_id') as $detailId => $group) {
                $detail = PdoDetail::findOrFail($detailId);
                $total  = $this->detailAllocatedTotal($detailId);
                if ($total > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'TRANSFER_EXCEEDS_BUDGET',
                            'message' => "Item '{$detail->description}': total transfer ({$total}) melebihi jumlah disetujui ({$detail->amount}).",
                        ],
                    ], 422));
                }
            }

            $now = now();
            foreach ($drafts as $draft) {
                $old = $draft->toArray();
                $draft->update([
                    'status'       => TransferEntry::STATUS_COMMITTED,
                    'committed_at' => $now,
                    'committed_by' => $actor->id,
                ]);

                AuditLog::record(
                    actor: $actor,
                    entityType: 'transfer_entries',
                    entityId: $draft->id,
                    action: 'UPDATE',
                    oldValues: $old,
                    newValues: $draft->fresh()->toArray()
                );
            }

            // Potongan otomatis: kurangi transfer rek_kebun sebesar nilai item potongan,
            // hanya SEKALI per PDO (di commit pertama). Guard exactly-once.
            $this->applyDeductionEntries($pdo, $actor);

            return $drafts->count();
        });
    }

    /**
     * Buat entri transfer negatif ke rek_kebun untuk tiap item potongan (is_deduction),
     * agar total transfer ke rek kebun ter-net (berkurang) sebesar nilai potongan.
     *
     * EXACTLY-ONCE: dilewati bila item potongan sudah punya entri otomatis committed —
     * jadi tidak dobel walau user simpan permanen berkali-kali (transfer bertahap).
     */
    private function applyDeductionEntries(PdoHeader $pdo, User $actor): void
    {
        $deductionDetails = $pdo->details()
            ->whereHas('expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->with('expenseItem')
            ->get();

        $now = now();

        foreach ($deductionDetails as $detail) {
            if (($detail->amount ?? 0) <= 0) {
                continue;
            }

            // Guard: sudah pernah dicatat sebagai potongan otomatis committed?
            $alreadyApplied = TransferEntry::where('pdo_detail_id', $detail->id)
                ->where('is_auto_generated', true)
                ->exists(); // global scope 'committed_only' → hanya committed yang dihitung

            if ($alreadyApplied) {
                continue;
            }

            $entry = TransferEntry::create([
                'pdo_detail_id'        => $detail->id,
                'recorded_by'          => $actor->id,
                'entry_source'         => TransferEntry::SOURCE_SYSTEM,
                'is_auto_generated'    => true,
                'status'               => TransferEntry::STATUS_COMMITTED,
                'committed_at'         => $now,
                'committed_by'         => $actor->id,
                'transfer_date'        => $now->toDateString(),
                'amount'               => -$detail->amount, // negatif: mengurangi rek_kebun
                'reference_number'     => null,
                'notes'                => 'Potongan otomatis (mengurangi transfer Rek. Kebun)',
                'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
            ]);

            AuditLog::record(
                actor: $actor,
                entityType: 'transfer_entries',
                entityId: $entry->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $entry->toArray()
            );
        }
    }

    /**
     * List semua PDO final dengan ringkasan transfer — untuk halaman list Transfer Dana.
     */
    public function pdoSummaryList(User $actor): array
    {
        $pdos = PdoHeader::with(['plantationUnit'])
            ->where('company_id', $actor->company_id)
            ->where('status', PdoHeader::STATUS_FINAL)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();

        return $pdos->map(function (PdoHeader $pdo) {
            $details = $pdo->details()->get();

            // total_amount = grand_total_amount (signed sesuai is_deduction), konsisten
            // dengan Daftar PDO. JANGAN sum('amount') mentah — mengabaikan potongan.
            $totalAmount   = (int) $pdo->grand_total_amount;
            $totalTransfer = $details->sum('total_transferred');

            // Per-destination totals
            $transfersByDest = TransferEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->selectRaw('transfer_destination, COALESCE(SUM(amount), 0) as subtotal')
                ->groupBy('transfer_destination')
                ->pluck('subtotal', 'transfer_destination');

            $lastTransfer = TransferEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->where('entry_source', TransferEntry::SOURCE_MANUAL)
                ->orderByDesc('transfer_date')
                ->orderByDesc('created_at')
                ->first();

            return [
                'pdo_id'                    => $pdo->id,
                'pdo_number'                => $pdo->pdo_number,
                'plantation_unit'           => $pdo->plantationUnit?->only(['id', 'code', 'name']),
                'period_month'              => $pdo->period_month,
                'period_year'              => $pdo->period_year,
                'notes'                     => $pdo->notes,
                'total_amount'              => $totalAmount,
                'transferred_rek_kebun'     => (int) ($transfersByDest[TransferEntry::DEST_REK_KEBUN] ?? 0),
                'transferred_pribadi'       => (int) ($transfersByDest[TransferEntry::DEST_PRIBADI] ?? 0),
                'transferred_vendor'        => (int) ($transfersByDest[TransferEntry::DEST_VENDOR] ?? 0),
                'total_transferred'         => $totalTransfer,
                'remaining'                 => $totalAmount - $totalTransfer,
                'last_transfer_date'        => $lastTransfer?->transfer_date,
            ];
        })->values()->all();
    }

    /**
     * Koreksi entri transfer manual.
     * Tidak bisa mengedit entri otomatis sistem.
     */
    public function update(TransferEntry $entry, array $data, User $actor): TransferEntry
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        // BR-AUTH-001: Verify PDO belongs to user's company
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke transfer ini.'],
            ], 403));
        }

        // BR-CLOSE-003: PDO yang sudah ditutup tidak bisa diubah
        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Transfer tidak bisa diubah setelah PDO ditutup.'],
            ], 409));
        }

        // BR-TRANSFER-003: entri auto tidak bisa diedit
        if ($entry->is_auto_generated) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'CANNOT_EDIT_AUTO_ENTRY', 'message' => 'Entri transfer otomatis tidak bisa diubah.'],
            ], 409));
        }

        $detail = $entry->pdoDetail;

        return DB::transaction(function () use ($entry, $data, $actor, $detail) {
            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            // BR-TRANSFER-002: validasi ulang total setelah koreksi
            if (isset($data['amount'])) {
                $currentTotal = $detail->transferEntries()
                    ->where('id', '!=', $entry->id)
                    ->sum('amount');
                $newTotal = $currentTotal + $data['amount'];

                if ($newTotal > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'TRANSFER_EXCEEDS_BUDGET',
                            'message' => "Total transfer ({$newTotal}) melebihi jumlah yang disetujui ({$detail->amount}).",
                        ],
                    ], 422));
                }
            }

            $old = $entry->toArray();
            $entry->update($data);

            AuditLog::record(
                actor: $actor,
                entityType: 'transfer_entries',
                entityId: $entry->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $entry->fresh()->toArray()
            );

            return $entry->fresh()->load('recorder');
        });
    }
}
