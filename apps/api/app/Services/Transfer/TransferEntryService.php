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
     */
    public function summaryByPdo(PdoHeader $pdo): SupportCollection
    {
        return $pdo->details()
            ->with(['expenseItem', 'transferEntries'])
            ->get()
            ->map(fn ($detail) => [
                'pdo_detail_id'    => $detail->id,
                'expense_item'     => $detail->expenseItem
                    ? array_merge(
                        $detail->expenseItem->only(['id', 'code', 'name']),
                        [
                            'split_transfer'                     => (bool) $detail->expenseItem->split_transfer,
                            'split_transfer_plantation_unit_ids' => $detail->expenseItem->split_transfer_plantation_unit_ids,
                        ]
                    )
                    : null,
                'description'      => $detail->description,
                'amount_approved'  => $detail->amount,
                'total_transferred'=> $detail->total_transferred,
                'remaining'        => $detail->amount - $detail->total_transferred,
                'entries'          => $detail->transferEntries,
            ]);
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
     * Catat transfer untuk banyak item sekaligus (bulk entry).
     * Hanya simpan entry dengan amount > 0.
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
            foreach ($entries as $row) {
                if (($row['amount'] ?? 0) <= 0) continue;

                $detail = PdoDetail::where('pdo_header_id', $pdo->id)
                    ->lockForUpdate()
                    ->findOrFail($row['pdo_detail_id']);

                $currentTotal = $detail->transferEntries()->sum('amount');
                $newTotal     = $currentTotal + $row['amount'];

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

            $totalAmount   = $details->sum('amount');
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
