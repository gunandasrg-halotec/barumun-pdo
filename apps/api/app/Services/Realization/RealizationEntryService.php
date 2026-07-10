<?php

namespace App\Services\Realization;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\RealizationEntry;
use App\Models\TransferEntry;
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
            ->when(isset($filters['unit_id']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('plantation_unit_id', $filters['unit_id'])))
            ->when(isset($filters['pdo_detail_id']), fn ($q) => $q->where('pdo_detail_id', $filters['pdo_detail_id']))
            ->when(isset($filters['period_year']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('period_year', $filters['period_year'])))
            ->when(isset($filters['period_month']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('period_month', $filters['period_month'])))
            ->when(!empty($filters['funding_source']), fn ($q) => $q->whereIn('funding_source', $filters['funding_source']))
            ->when(isset($filters['start_date']), fn ($q) => $q->whereDate('transaction_date', '>=', $filters['start_date']))
            ->when(isset($filters['end_date']), fn ($q) => $q->whereDate('transaction_date', '<=', $filters['end_date']))
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
     * Daftar item yang boleh direalisasi oleh actor + sisa kantong PDO-level.
     *
     * Saldo per item = anggaran − total_realized (bisa negatif jika over-budget).
     * Tampilkan semua item non-deduction; item dengan saldo ≤ 0 tetap ditampilkan
     * agar user bisa melihat status over-budget, tapi tidak bisa dipilih (saldo ≤ 0).
     *
     * remaining_kantong = total transfer kantong actor − total realisasi kantong actor (PDO-level).
     * Ini adalah hard ceiling: realisasi baru tidak boleh melebihi remaining_kantong.
     *
     * GET /pdo/{pdo}/realizations/available
     */
    public function availableItemsForActor(PdoHeader $pdo, User $actor): array
    {
        $group = $actor->realizationSettlementGroup();
        if (! $group) {
            return ['items' => [], 'remaining_kantong' => 0];
        }

        $details = $pdo->details()
            ->with(['expenseItem', 'transferEntries', 'realizationEntries'])
            ->get();

        // Hitung kantong PDO-level untuk group ini
        $totalKantong = $this->totalKantongForGroup($pdo, $group);

        // Total realisasi seluruh item untuk group ini (PDO-level)
        $totalRealizedGroup = (int) RealizationEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->where('settlement_group', $group)
            ->sum('amount');

        $remainingKantong = $totalKantong - $totalRealizedGroup;

        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        $result = [];
        foreach ($details as $detail) {
            if ($detail->expenseItem?->is_deduction) {
                continue;
            }

            // Item hanya tersedia untuk actor jika ada transfer ke kantong actor
            // (transfer_destination per item menentukan kantong mana yang mendanai item ini).
            $hasTransferToActorKantong = $detail->transferEntries->contains(
                fn ($t) => in_array($t->transfer_destination, $destinations, true)
            );
            if (! $hasTransferToActorKantong) {
                continue;
            }

            // Saldo per item: anggaran − total_realized untuk item ini (bisa negatif)
            $totalRealized = (int) $detail->realizationEntries->sum('amount');
            $saldo         = $detail->amount - $totalRealized;

            $result[] = [
                'pdo_detail_id'  => $detail->id,
                'expense_item'   => $detail->expenseItem?->only(['id', 'code', 'name']),
                'description'    => $detail->description,
                'bucket'         => $detail->amount,
                'realized_group' => $totalRealized,
                'saldo'          => $saldo,
            ];
        }

        return [
            'items'             => $result,
            'remaining_kantong' => $remainingKantong,
            'total_kantong'     => $totalKantong,
        ];
    }

    /**
     * Total transfer ke kantong milik group ini untuk seluruh PDO.
     * Kebun = rek_kebun; pribadi_vendor = pribadi + vendor.
     */
    private function totalKantongForGroup(PdoHeader $pdo, string $group): int
    {
        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        return (int) TransferEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->whereIn('transfer_destination', $destinations)
            ->sum('amount');
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

        return DB::transaction(function () use ($detail, $data, $actor, $group, $pdo) {
            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            // BR-REAL-002: total realisasi kantong actor (PDO-level) tidak boleh melebihi
            // total transfer ke kantong tersebut (saldo kas kebun / saldo pribadi-vendor).
            $totalKantong       = $this->totalKantongForGroup($pdo, $group);
            $totalRealizedGroup = (int) RealizationEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
                ->where('settlement_group', $group)
                ->sum('amount');
            $newGroupTotal = $totalRealizedGroup + $data['amount'];

            if ($newGroupTotal > $totalKantong) {
                $sisa = $totalKantong - $totalRealizedGroup;
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_KANTONG',
                        'message' => "Total realisasi kantong ini (Rp " . number_format($newGroupTotal, 0, ',', '.') . ") melebihi saldo kantong (Rp " . number_format($totalKantong, 0, ',', '.') . "). Sisa: Rp " . number_format($sisa, 0, ',', '.') . ".",
                    ],
                ], 422));
            }

            $totalRealizedAll = (int) $detail->realizationEntries()->sum('amount');
            $newTotalAll = $totalRealizedAll + $data['amount'];

            if ($newTotalAll > $detail->amount) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'REALIZATION_EXCEEDS_BUDGET', 'message' => "Total realisasi ({$newTotalAll}) melebihi anggaran ({$detail->amount})."],
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

            // BR-REAL-003: validasi ulang jika amount berubah
            if (isset($data['amount'])) {
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
