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
     * Scoped by company_id; unit-bound roles also scoped by unit.
     */
    public function list(User $actor, array $filters = []): Collection
    {
        return RealizationEntry::with(['pdoDetail.pdoHeader', 'pdoDetail.expenseItem.subcategory.category', 'recorder', 'attachments'])
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->when($actor->plantation_unit_id, fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('plantation_unit_id', $actor->plantation_unit_id)))
            ->when(!empty($filters['unit_ids']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->whereIn('plantation_unit_id', $filters['unit_ids'])))
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
     * BR-REAL-005: daftar item yang boleh direalisasi oleh actor, beserta
     * saldo kantong (bucket - realisasi kelompok tsb). Item potongan
     * dan item yang bucket-nya 0 untuk kelompok actor tidak disertakan.
     * GET /pdo/{pdo}/realizations/available
     */
    public function availableItemsForActor(PdoHeader $pdo, User $actor): array
    {
        $group = $actor->realizationSettlementGroup();
        if (! $group) {
            return [];
        }

        $details = $pdo->details()
            ->with(['expenseItem', 'transferEntries', 'realizationEntries'])
            ->get();

        $result = [];
        foreach ($details as $detail) {
            if ($detail->expenseItem?->is_deduction) {
                continue;
            }

            if ($group === RealizationEntry::SETTLEMENT_KEBUN) {
                $bucket = (int) $detail->transferEntries
                    ->where('transfer_destination', 'rek_kebun')
                    ->sum('amount');
            } else {
                $bucket = (int) $detail->transferEntries
                    ->whereIn('transfer_destination', ['pribadi', 'vendor'])
                    ->sum('amount');
            }

            if ($bucket <= 0) {
                continue;
            }

            $realizedGroup = (int) $detail->realizationEntries
                ->where('settlement_group', $group)
                ->sum('amount');

            $saldo = $bucket - $realizedGroup;
            if ($saldo <= 0) {
                continue;
            }

            $result[] = [
                'pdo_detail_id'  => $detail->id,
                'expense_item'   => $detail->expenseItem?->only(['id', 'code', 'name']),
                'description'    => $detail->description,
                'bucket'         => $bucket,
                'realized_group' => $realizedGroup,
                'saldo'          => $saldo,
            ];
        }

        return $result;
    }

    /**
     * BR-REAL-005: total dana yang ditransfer ke kantong tertentu untuk sebuah
     * detail. Kantong 'kebun' = transfer ke rek_kebun. Kantong 'pribadi_vendor'
     * = transfer ke pribadi + vendor. transferEntries() sudah ter-scope
     * committed (global scope pada TransferEntry).
     */
    private function bucketForGroup(PdoDetail $detail, string $group): int
    {
        if ($group === RealizationEntry::SETTLEMENT_KEBUN) {
            return (int) $detail->transferEntries()->where('transfer_destination', 'rek_kebun')->sum('amount');
        }

        return (int) $detail->transferEntries()->whereIn('transfer_destination', ['pribadi', 'vendor'])->sum('amount');
    }

    /**
     * Catat realisasi baru.
     * BR-REAL-001: hanya saat PDO berstatus final.
     * BR-REAL-002: total realisasi PER KANTONG tidak boleh melebihi transfer ke kantong itu.
     * BR-REAL-003: total realisasi (semua kantong) tidak boleh melebihi amount yang disetujui.
     * BR-REAL-005: KERANI hanya boleh realisasi kantong rek_kebun; STAFF_PURCHASING
     * & MANAJER_KEUANGAN hanya kantong pribadi+vendor. Item potongan tidak bisa direalisasi.
     */
    public function store(array $data, User $actor): RealizationEntry
    {
        $detail = PdoDetail::with('expenseItem')->findOrFail($data['pdo_detail_id']);
        $pdo    = $detail->pdoHeader;

        // BR-AUTH-001: Verify PDO belongs to user's unit (row-level security)
        if ($actor->plantation_unit_id && $pdo->plantation_unit_id !== $actor->plantation_unit_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa dicatat untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        // BR-REAL-001
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Realisasi hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        // BR-REAL-004: STAFF_PURCHASING tidak boleh menggunakan kas_kebun sebagai sumber dana
        if (($actor->role?->code === 'STAFF_PURCHASING') && (($data['funding_source'] ?? '') === 'kas_kebun')) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FUNDING_SOURCE_FORBIDDEN', 'message' => 'Role STAFF_PURCHASING tidak diizinkan menggunakan sumber dana kas_kebun.'],
            ], 403));
        }

        // BR-REAL-005: tentukan kantong role aktor
        $group = $actor->realizationSettlementGroup();
        if (! $group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak mencatat realisasi.'],
            ], 403));
        }

        // Item potongan tidak bisa direalisasi
        if ($detail->expenseItem?->is_deduction) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DEDUCTION_NOT_REALIZABLE', 'message' => 'Item potongan tidak bisa direalisasi.'],
            ], 403));
        }

        return DB::transaction(function () use ($detail, $data, $actor, $group) {
            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            // BR-REAL-005: item harus punya transfer ke kantong role aktor
            $bucket = $this->bucketForGroup($detail, $group);
            if ($bucket <= 0) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'NO_BUCKET_FOR_ROLE',
                        'message' => 'Item ini tidak ditransfer ke kantong yang bisa Anda realisasi.',
                    ],
                ], 403));
            }

            $realizedGroup = (int) $detail->realizationEntries()->where('settlement_group', $group)->sum('amount');
            $newGroupTotal = $realizedGroup + $data['amount'];

            // BR-REAL-002: realisasi kelompok ini tidak boleh melebihi kantong kelompok ini
            if ($newGroupTotal > $bucket) {
                $sisa = $bucket - $realizedGroup;
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_TRANSFER',
                        'message' => "Total realisasi kantong ini ({$newGroupTotal}) melebihi transfer yang diterima kantong ini ({$bucket}). Sisa: {$sisa}.",
                    ],
                ], 422));
            }

            // BR-REAL-003: total realisasi SEMUA kantong tidak boleh melebihi amount yang disetujui
            $totalRealizedAll = (int) $detail->realizationEntries()->sum('amount');
            $newTotalAll      = $totalRealizedAll + $data['amount'];
            if ($newTotalAll > $detail->amount) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_BUDGET',
                        'message' => "Total realisasi ({$newTotalAll}) melebihi jumlah yang disetujui ({$detail->amount}).",
                    ],
                ], 422));
            }

            $entry = RealizationEntry::create([
                'pdo_detail_id'    => $detail->id,
                'recorded_by'      => $actor->id,
                'transaction_date' => $data['transaction_date'],
                'amount'           => $data['amount'],
                'payment_method'   => $data['payment_method'],
                'proof_number'     => $data['proof_number'],
                'funding_source'   => $data['funding_source'],
                'explanation'      => $data['explanation'] ?? null,
                'settlement_group' => $group,
            ]);

            AuditLog::record(
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

        // BR-AUTH-001: Verify PDO belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($actor->plantation_unit_id && $pdo->plantation_unit_id !== $actor->plantation_unit_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa diubah untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa diubah setelah PDO ditutup.'],
            ], 409));
        }

        // BR-REAL-004: STAFF_PURCHASING tidak boleh menggunakan kas_kebun sebagai sumber dana
        if (isset($data['funding_source']) && ($actor->role?->code === 'STAFF_PURCHASING') && $data['funding_source'] === 'kas_kebun') {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FUNDING_SOURCE_FORBIDDEN', 'message' => 'Role STAFF_PURCHASING tidak diizinkan menggunakan sumber dana kas_kebun.'],
            ], 403));
        }

        // BR-REAL-005: hanya role dengan kantong yang sama boleh mengedit entri ini
        if ($actor->realizationSettlementGroup() !== $entry->settlement_group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak mengubah realisasi kantong ini.'],
            ], 403));
        }

        $detail = $entry->pdoDetail;

        return DB::transaction(function () use ($entry, $data, $actor, $detail) {
            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            // BR-REAL-002 & BR-REAL-003: validasi ulang jika amount berubah
            if (isset($data['amount'])) {
                $bucket        = $this->bucketForGroup($detail, $entry->settlement_group);
                $realizedGroup = (int) $detail->realizationEntries()
                    ->where('settlement_group', $entry->settlement_group)
                    ->where('id', '!=', $entry->id)
                    ->sum('amount');
                $newGroupTotal = $realizedGroup + $data['amount'];

                if ($newGroupTotal > $bucket) {
                    $sisa = $bucket - $realizedGroup;
                    abort(response()->json([
                        'success' => false,
                        'error'   => ['code' => 'REALIZATION_EXCEEDS_TRANSFER', 'message' => "Total realisasi kantong ini ({$newGroupTotal}) melebihi transfer kantong ini ({$bucket}). Sisa: {$sisa}."],
                    ], 422));
                }

                $totalRealizedAll = (int) $detail->realizationEntries()->where('id', '!=', $entry->id)->sum('amount');
                $newTotalAll      = $totalRealizedAll + $data['amount'];
                if ($newTotalAll > $detail->amount) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => ['code' => 'REALIZATION_EXCEEDS_BUDGET', 'message' => "Total realisasi ({$newTotalAll}) melebihi anggaran ({$detail->amount})."],
                    ], 422));
                }
            }

            $old = $entry->toArray();
            $entry->update($data);

            AuditLog::record(
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

        // BR-AUTH-001: Verify PDO belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($actor->plantation_unit_id && $pdo->plantation_unit_id !== $actor->plantation_unit_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa dihapus untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa dihapus setelah PDO ditutup.'],
            ], 409));
        }

        // BR-REAL-005: hanya role dengan kantong yang sama boleh menghapus entri ini
        if ($actor->realizationSettlementGroup() !== $entry->settlement_group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak menghapus realisasi kantong ini.'],
            ], 403));
        }

        $old = $entry->toArray();
        $entry->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'realization_entries',
            entityId: $entry->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }
}
