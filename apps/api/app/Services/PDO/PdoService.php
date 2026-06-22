<?php

namespace App\Services\PDO;

use App\Models\AuditLog;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PdoService
{
    // ─────────────────────────────────────────────────────
    // PDO HEADER
    // ─────────────────────────────────────────────────────

    public function listPdo(array $filters = []): LengthAwarePaginator
    {
        return PdoHeader::with(['plantationUnit', 'creator'])
            ->when(!empty($filters['search']), fn ($q) => $q->where('pdo_number', 'ilike', '%' . $filters['search'] . '%'))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['period_year']), fn ($q) => $q->where('period_year', $filters['period_year']))
            ->when(!empty($filters['period_month']), fn ($q) => $q->where('period_month', $filters['period_month']))
            ->when(!empty($filters['plantation_unit_id']), fn ($q) => $q->where('plantation_unit_id', $filters['plantation_unit_id']))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(20);
    }

    public function findPdo(string $id): PdoHeader
    {
        return PdoHeader::with(['plantationUnit', 'creator', 'details.expenseItem'])->findOrFail($id);
    }

    /**
     * [E] Detail PDO dikelompokkan by Kategori → Sub-Kategori → Item.
     * Digunakan oleh show() untuk response hierarkis ke frontend.
     */
    public function findPdoGrouped(string $id): array
    {
        $pdo = PdoHeader::with([
            'plantationUnit',
            'creator',
            'details.expenseItem.subcategory.category',
        ])->findOrFail($id);

        // Group details by kategori → sub-kategori
        $grouped = [];
        foreach ($pdo->details as $detail) {
            $sub = $detail->expenseItem?->subcategory;
            $cat = $sub?->category;

            $catKey = $cat?->id ?? 'uncategorized';
            $subKey = $sub?->id ?? 'uncategorized';

            if (! isset($grouped[$catKey])) {
                $grouped[$catKey] = [
                    'category'       => $cat ? $cat->only(['id', 'code', 'name', 'display_order']) : null,
                    'subcategories'  => [],
                    'subtotal_amount'=> 0,
                ];
            }
            if (! isset($grouped[$catKey]['subcategories'][$subKey])) {
                $grouped[$catKey]['subcategories'][$subKey] = [
                    'subcategory'    => $sub ? $sub->only(['id', 'code', 'name', 'display_order']) : null,
                    'details'        => [],
                    'subtotal_amount'=> 0,
                ];
            }

            $grouped[$catKey]['subcategories'][$subKey]['details'][]        = $detail;
            $grouped[$catKey]['subcategories'][$subKey]['subtotal_amount'] += $detail->amount;
            $grouped[$catKey]['subtotal_amount']                           += $detail->amount;
        }

        // Re-index dan urutkan by display_order
        $categoriesArray = collect(array_values($grouped))
            ->map(fn ($c) => array_merge($c, ['subcategories' => array_values($c['subcategories'])]))
            ->sortBy(fn ($c) => $c['category']['display_order'] ?? 999)
            ->values()
            ->all();

        return [
            'pdo'         => $pdo->makeHidden('details'),
            'categories'  => $categoriesArray,
            'grand_total' => $pdo->details->sum('amount'),
        ];
    }

    /**
     * Buat PDO Bulanan baru + otomatis isi baris dari item rutin.
     * BR-PDO-001: Satu PDO per unit per bulan/tahun.
     * BR-PDO-002: Template otomatis dari expense_items is_routine=true.
     */
    public function createPdo(array $data, User $actor): PdoHeader
    {
        $unit = PlantationUnit::findOrFail($data['plantation_unit_id']);

        // BR-PDO-001: duplikat (unit, bulan, tahun) → error
        $exists = PdoHeader::withoutGlobalScopes()
            ->where('plantation_unit_id', $data['plantation_unit_id'])
            ->where('period_month', $data['period_month'])
            ->where('period_year', $data['period_year'])
            ->exists();

        if ($exists) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_ALREADY_EXISTS', 'message' => 'PDO untuk periode dan unit ini sudah ada.'],
            ], 409));
        }

        return DB::transaction(function () use ($data, $actor, $unit) {
            $pdo = PdoHeader::create([
                'company_id'         => $actor->company_id,
                'plantation_unit_id' => $data['plantation_unit_id'],
                'created_by'         => $actor->id,
                'pdo_number'         => PdoHeader::generateNumber($unit->code, $data['period_year'], $data['period_month']),
                'period_month'       => $data['period_month'],
                'period_year'        => $data['period_year'],
                'status'             => PdoHeader::STATUS_DRAFT,
                'notes'              => $data['notes'] ?? null,
            ]);

            // BR-PDO-002: isi template otomatis dari item rutin aktif
            $this->fillRoutineTemplate($pdo);

            AuditLog::record(
                actor: $actor,
                entityType: 'pdo_headers',
                entityId: $pdo->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $pdo->toArray()
            );

            return $pdo->load(['plantationUnit', 'creator', 'details.expenseItem']);
        });
    }

    public function updatePdo(PdoHeader $pdo, array $data, User $actor): PdoHeader
    {
        // BR-PDO-003: hanya bisa edit saat draft
        $this->assertDraft($pdo);

        $old = $pdo->toArray();
        $pdo->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_headers',
            entityId: $pdo->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $pdo->fresh()->toArray()
        );

        return $pdo->fresh();
    }

    public function deletePdo(PdoHeader $pdo, User $actor): void
    {
        // BR-PDO-003: hanya boleh hapus saat draft
        $this->assertDraft($pdo);

        $old = $pdo->toArray();
        $pdo->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_headers',
            entityId: $pdo->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // PDO DETAILS
    // ─────────────────────────────────────────────────────

    public function listDetails(PdoHeader $pdo)
    {
        return $pdo->details()->with(['expenseItem.subcategory.category', 'transferEntries', 'realizationEntries'])->get();
    }

    public function addDetail(PdoHeader $pdo, array $data, User $actor): PdoDetail
    {
        $this->assertDraft($pdo);

        $item = ExpenseItem::findOrFail($data['expense_item_id']);

        $detail = PdoDetail::create([
            'pdo_header_id'  => $pdo->id,
            'expense_item_id'=> $item->id,
            'account_number' => $item->default_account_number, // snapshot
            'description'    => $data['description'],
            'quantity'       => $data['quantity'] ?? null,
            'unit'           => $data['unit'] ?? $item->default_unit, // snapshot
            'rate'           => $data['rate'] ?? $item->default_rate, // snapshot
            'amount'         => $data['amount'],
            'notes'          => $data['notes'] ?? null,
            'display_order'  => $data['display_order'] ?? $this->nextDisplayOrder($pdo),
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $detail->toArray()
        );

        $this->syncGrandTotal($pdo);

        return $detail->load('expenseItem');
    }

    public function updateDetail(PdoHeader $pdo, PdoDetail $detail, array $data, User $actor): PdoDetail
    {
        $this->assertDraft($pdo);

        $old = $detail->toArray();
        $detail->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $detail->fresh()->toArray()
        );

        $this->syncGrandTotal($pdo);

        return $detail->fresh()->load('expenseItem');
    }

    public function deleteDetail(PdoHeader $pdo, PdoDetail $detail, User $actor): void
    {
        $this->assertDraft($pdo);

        $old = $detail->toArray();
        $detail->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_details',
            entityId: $detail->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );

        $this->syncGrandTotal($pdo);
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────

    /**
     * BR-PDO-002: Isi baris PDO dari semua expense_item is_routine=true, is_active=true.
     * Nilai amount=0, diisi nanti oleh KERANI.
     */
    private function fillRoutineTemplate(PdoHeader $pdo): void
    {
        $routineItems = ExpenseItem::with('subcategory')
            ->where('is_routine', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get();

        foreach ($routineItems as $order => $item) {
            PdoDetail::create([
                'pdo_header_id'  => $pdo->id,
                'expense_item_id'=> $item->id,
                'account_number' => $item->default_account_number,
                'description'    => $item->name,
                'unit'           => $item->default_unit,
                'rate'           => $item->default_rate,
                'amount'         => 0,
                'display_order'  => $order + 1,
            ]);
        }
    }

    /** BR-PDO-003 */
    private function assertDraft(PdoHeader $pdo): void
    {
        if (! $pdo->isDraft()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_EDITABLE', 'message' => 'PDO hanya bisa diubah saat status draft.'],
            ], 409));
        }
    }

    private function nextDisplayOrder(PdoHeader $pdo): int
    {
        return ($pdo->details()->max('display_order') ?? 0) + 1;
    }

    private function syncGrandTotal(PdoHeader $pdo): void
    {
        $pdo->updateQuietly([
            'grand_total_amount' => $pdo->details()->sum('amount'),
        ]);
    }
}
