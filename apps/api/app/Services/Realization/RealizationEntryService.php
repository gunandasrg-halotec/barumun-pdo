<?php

namespace App\Services\Realization;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\RealizationEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RealizationEntryService
{
    /**
     * Daftar semua entri realisasi (dengan filter opsional).
     */
    public function list(array $filters = []): Collection
    {
        return RealizationEntry::with(['pdoDetail.expenseItem', 'recorder', 'attachments'])
            ->when(isset($filters['pdo_detail_id']), fn ($q) => $q->where('pdo_detail_id', $filters['pdo_detail_id']))
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * Summary realisasi per PDO (total per detail).
     * GET /pdo/{pdo}/realizations
     */
    public function summaryByPdo(PdoHeader $pdo): Collection
    {
        return $pdo->details()
            ->with(['expenseItem', 'realizationEntries.attachments'])
            ->get()
            ->map(fn ($detail) => [
                'pdo_detail_id'    => $detail->id,
                'expense_item'     => $detail->expenseItem?->only(['id', 'code', 'name']),
                'description'      => $detail->description,
                'amount_approved'  => $detail->amount,
                'total_transferred'=> $detail->total_transferred,
                'total_realized'   => $detail->total_realized,
                'sisa_realisasi'   => $detail->amount - $detail->total_realized,
            ]);
    }

    /**
     * Daftar item realisasi lengkap per PDO (dengan bukti).
     * GET /pdo/{pdo}/realizations/items
     */
    public function itemsByPdo(PdoHeader $pdo): Collection
    {
        return RealizationEntry::with(['pdoDetail.expenseItem', 'recorder', 'attachments'])
            ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * Catat realisasi baru.
     * BR-REAL-001: hanya saat PDO berstatus final.
     * BR-REAL-002: total realisasi tidak boleh melebihi total transfer yang masuk.
     * BR-REAL-003: total realisasi tidak boleh melebihi amount yang disetujui.
     */
    public function store(array $data, User $actor): RealizationEntry
    {
        $detail = PdoDetail::findOrFail($data['pdo_detail_id']);
        $pdo    = $detail->pdoHeader;

        // BR-REAL-001
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Realisasi hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        return DB::transaction(function () use ($detail, $data, $actor) {
            $totalRealized    = $detail->realizationEntries()->sum('amount');
            $totalTransferred = $detail->transferEntries()->sum('amount');
            $newTotal         = $totalRealized + $data['amount'];

            // BR-REAL-002: realisasi tidak boleh melebihi transfer yang sudah masuk
            if ($newTotal > $totalTransferred) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_TRANSFER',
                        'message' => "Total realisasi ({$newTotal}) melebihi total transfer yang diterima ({$totalTransferred}).",
                    ],
                ], 422));
            }

            // BR-REAL-003: realisasi tidak boleh melebihi amount yang disetujui
            if ($newTotal > $detail->amount) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_BUDGET',
                        'message' => "Total realisasi ({$newTotal}) melebihi jumlah yang disetujui ({$detail->amount}).",
                    ],
                ], 422));
            }

            $entry = RealizationEntry::create([
                'pdo_detail_id'    => $detail->id,
                'recorded_by'      => $actor->id,
                'transaction_date' => $data['transaction_date'],
                'amount'           => $data['amount'],
                'payment_method'   => $data['payment_method'],
                'reference_number' => $data['reference_number'],
                'funding_source'   => $data['funding_source'],
                'explanation'      => $data['explanation'] ?? null,
            ]);

            AuditLog::append(
                actor: $actor,
                entityType: 'realization_entries',
                entityId: $entry->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $entry->toArray()
            );

            return $entry->load(['pdoDetail.expenseItem', 'recorder']);
        });
    }

    /**
     * Koreksi entri realisasi.
     * Hanya bisa ubah saat PDO masih final (belum closed).
     */
    public function update(RealizationEntry $entry, array $data, User $actor): RealizationEntry
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa diubah setelah PDO ditutup.'],
            ], 409));
        }

        $detail = $entry->pdoDetail;

        return DB::transaction(function () use ($entry, $data, $actor, $detail) {
            // BR-REAL-002 & BR-REAL-003: validasi ulang jika amount berubah
            if (isset($data['amount'])) {
                $totalRealized    = $detail->realizationEntries()->where('id', '!=', $entry->id)->sum('amount');
                $totalTransferred = $detail->transferEntries()->sum('amount');
                $newTotal         = $totalRealized + $data['amount'];

                if ($newTotal > $totalTransferred) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => ['code' => 'REALIZATION_EXCEEDS_TRANSFER', 'message' => "Total realisasi ({$newTotal}) melebihi transfer ({$totalTransferred})."],
                    ], 422));
                }

                if ($newTotal > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => ['code' => 'REALIZATION_EXCEEDS_BUDGET', 'message' => "Total realisasi ({$newTotal}) melebihi anggaran ({$detail->amount})."],
                    ], 422));
                }
            }

            $old = $entry->toArray();
            $entry->update($data);

            AuditLog::append(
                actor: $actor,
                entityType: 'realization_entries',
                entityId: $entry->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $entry->fresh()->toArray()
            );

            return $entry->fresh()->load(['pdoDetail.expenseItem', 'recorder']);
        });
    }

    /**
     * Hapus entri realisasi.
     * Hanya bisa saat PDO belum closed.
     */
    public function destroy(RealizationEntry $entry, User $actor): void
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa dihapus setelah PDO ditutup.'],
            ], 409));
        }

        $old = $entry->toArray();
        $entry->delete();

        AuditLog::append(
            actor: $actor,
            entityType: 'realization_entries',
            entityId: $entry->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }
}
