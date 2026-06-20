<?php

namespace App\Services\Transfer;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
    public function summaryByPdo(PdoHeader $pdo): Collection
    {
        return $pdo->details()
            ->with(['expenseItem', 'transferEntries'])
            ->get()
            ->map(fn ($detail) => [
                'pdo_detail_id'   => $detail->id,
                'expense_item'    => $detail->expenseItem?->only(['id', 'code', 'name']),
                'description'     => $detail->description,
                'amount_approved' => $detail->amount,
                'total_transferred'=> $detail->total_transferred,
                'remaining'       => $detail->amount - $detail->total_transferred,
                'entries'         => $detail->transferEntries,
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
                'pdo_detail_id'    => $detail->id,
                'recorded_by'      => $actor->id,
                'entry_source'     => TransferEntry::SOURCE_MANUAL,
                'is_auto_generated'=> false,
                'transfer_date'    => $data['transfer_date'],
                'amount'           => $data['amount'],
                'reference_number' => $data['reference_number'],
                'notes'            => $data['notes'] ?? null,
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
     * Koreksi entri transfer manual.
     * Tidak bisa mengedit entri otomatis sistem.
     */
    public function update(TransferEntry $entry, array $data, User $actor): TransferEntry
    {
        // BR-TRANSFER-003: entri auto tidak bisa diedit
        if ($entry->is_auto_generated) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'CANNOT_EDIT_AUTO_ENTRY', 'message' => 'Entri transfer otomatis tidak bisa diubah.'],
            ], 409));
        }

        $detail = $entry->pdoDetail;

        return DB::transaction(function () use ($entry, $data, $actor, $detail) {
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
