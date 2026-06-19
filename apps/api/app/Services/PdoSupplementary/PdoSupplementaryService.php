<?php

namespace App\Services\PdoSupplementary;

use App\Models\AuditLog;
use App\Models\ExpenseItem;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryDetail;
use App\Models\PdoSupplementaryHeader;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PdoSupplementaryService
{
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        return PdoSupplementaryHeader::with(['parentPdo', 'plantationUnit', 'creator'])
            ->where('company_id', $user->company_id)
            ->when(isset($filters['parent_pdo_header_id']), fn ($q) => $q->where('parent_pdo_header_id', $filters['parent_pdo_header_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['plantation_unit_id']), fn ($q) => $q->where('plantation_unit_id', $filters['plantation_unit_id']))
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function find(string $id): PdoSupplementaryHeader
    {
        return PdoSupplementaryHeader::with(['parentPdo', 'plantationUnit', 'creator', 'details.expenseItem'])
            ->findOrFail($id);
    }

    /**
     * Buat PDO Tambahan baru.
     * BR-SUPPL-001: Parent PDO harus berstatus final (sudah disetujui Direktur).
     * BR-SUPPL-002: Satu unit hanya boleh punya satu PDO Tambahan aktif (bukan final_merged/rejected)
     *              per parent PDO.
     */
    public function create(array $data, User $actor): PdoSupplementaryHeader
    {
        $parentPdo = PdoHeader::withoutGlobalScopes()->findOrFail($data['parent_pdo_header_id']);

        // BR-SUPPL-001
        if (! $parentPdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PARENT_PDO_NOT_FINAL', 'message' => 'PDO Tambahan hanya bisa dibuat saat PDO Bulanan induk berstatus final.'],
            ], 409));
        }

        // BR-SUPPL-002
        $hasActive = PdoSupplementaryHeader::where('parent_pdo_header_id', $parentPdo->id)
            ->where('plantation_unit_id', $parentPdo->plantation_unit_id)
            ->whereNotIn('status', [PdoSupplementaryHeader::STATUS_FINAL_MERGED, PdoSupplementaryHeader::STATUS_REJECTED])
            ->exists();

        if ($hasActive) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'SUPPLEMENTARY_ALREADY_ACTIVE', 'message' => 'Unit ini sudah memiliki PDO Tambahan yang sedang berjalan untuk PDO Bulanan ini.'],
            ], 409));
        }

        return DB::transaction(function () use ($data, $actor, $parentPdo) {
            $unit = $parentPdo->plantationUnit;

            $supp = PdoSupplementaryHeader::create([
                'parent_pdo_header_id' => $parentPdo->id,
                'company_id'           => $actor->company_id,
                'plantation_unit_id'   => $parentPdo->plantation_unit_id,
                'created_by'           => $actor->id,
                'pdo_number'           => PdoSupplementaryHeader::generateNumber(
                    $unit->code,
                    $parentPdo->period_year,
                    $parentPdo->period_month
                ),
                'period_month'         => $parentPdo->period_month,
                'period_year'          => $parentPdo->period_year,
                'status'               => PdoSupplementaryHeader::STATUS_DRAFT,
                'notes'                => $data['notes'] ?? null,
            ]);

            AuditLog::record(
                actor: $actor,
                entityType: 'pdo_supplementary_headers',
                entityId: $supp->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $supp->toArray()
            );

            return $supp->load(['parentPdo', 'plantationUnit', 'creator']);
        });
    }

    public function update(PdoSupplementaryHeader $supp, array $data, User $actor): PdoSupplementaryHeader
    {
        $this->assertDraft($supp);

        $old = $supp->toArray();
        $supp->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_supplementary_headers',
            entityId: $supp->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $supp->fresh()->toArray()
        );

        return $supp->fresh();
    }

    // ─────────────────────────────────────────────────────
    // DETAILS
    // ─────────────────────────────────────────────────────

    public function addDetail(PdoSupplementaryHeader $supp, array $data, User $actor): PdoSupplementaryDetail
    {
        $this->assertDraft($supp);

        $item = ExpenseItem::findOrFail($data['expense_item_id']);

        $detail = PdoSupplementaryDetail::create([
            'pdo_supplementary_header_id' => $supp->id,
            'expense_item_id'             => $item->id,
            'account_number'              => $item->default_account_number, // snapshot
            'description'                 => $data['description'],
            'quantity'                    => $data['quantity'] ?? null,
            'unit'                        => $data['unit'] ?? $item->default_unit,
            'rate'                        => $data['rate'] ?? $item->default_rate,
            'amount'                      => $data['amount'],
            'notes'                       => $data['notes'] ?? null,
            'display_order'               => $data['display_order'] ?? $this->nextOrder($supp),
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_supplementary_details',
            entityId: $detail->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $detail->toArray()
        );

        return $detail->load('expenseItem');
    }

    public function updateDetail(PdoSupplementaryHeader $supp, PdoSupplementaryDetail $detail, array $data, User $actor): PdoSupplementaryDetail
    {
        $this->assertDraft($supp);

        $old = $detail->toArray();
        $detail->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_supplementary_details',
            entityId: $detail->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $detail->fresh()->toArray()
        );

        return $detail->fresh()->load('expenseItem');
    }

    public function deleteDetail(PdoSupplementaryHeader $supp, PdoSupplementaryDetail $detail, User $actor): void
    {
        $this->assertDraft($supp);

        $old = $detail->toArray();
        $detail->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_supplementary_details',
            entityId: $detail->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────

    private function assertDraft(PdoSupplementaryHeader $supp): void
    {
        if (! $supp->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'SUPPLEMENTARY_NOT_EDITABLE', 'message' => 'PDO Tambahan hanya bisa diubah saat status draft.'],
            ], 409));
        }
    }

    private function nextOrder(PdoSupplementaryHeader $supp): int
    {
        return ($supp->details()->max('display_order') ?? 0) + 1;
    }
}
