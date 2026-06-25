<?php

namespace App\Services\MasterData;

use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MasterDataService
{
    // ─────────────────────────────────────────────────────
    // EXPENSE CATEGORIES
    // ─────────────────────────────────────────────────────

    public function listCategories(string $companyId, array $filters = []): Collection
    {
        return ExpenseCategory::where('company_id', $companyId)
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();
    }

    public function findCategory(string $id, string $companyId): ExpenseCategory
    {
        return ExpenseCategory::where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function createCategory(array $data, User $actor): ExpenseCategory
    {
        // BR-MASTER-003: Kode unik per company (sudah divalidasi di FormRequest,
        // tapi Service juga menjaga jika dipanggil langsung)
        $this->assertNoDuplicateCategoryCode($data['code'], $data['company_id']);

        $category = ExpenseCategory::create($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_categories',
            entityId: $category->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $category->toArray()
        );

        return $category;
    }

    public function updateCategory(ExpenseCategory $category, array $data, User $actor): ExpenseCategory
    {
        // BR-MASTER-003: Kode unik per company (kecuali kode sendiri)
        if (isset($data['code']) && $data['code'] !== $category->code) {
            $this->assertNoDuplicateCategoryCode($data['code'], $category->company_id, $category->id);
        }

        $old = $category->toArray();
        $category->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_categories',
            entityId: $category->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $category->fresh()->toArray()
        );

        return $category->fresh();
    }

    public function deleteCategory(ExpenseCategory $category, User $actor): void
    {
        // BR-MASTER-002: Tolak jika masih ada sub-kategori aktif
        if ($category->activeSubcategories()->exists()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'CATEGORY_HAS_CHILDREN', 'message' => 'Kategori masih memiliki sub-kategori aktif.'],
            ], 409));
        }

        // BR-MASTER-004: Hard delete jika belum pernah dipakai, soft delete jika sudah
        $usedInPdo = DB::table('pdo_details')
            ->join('expense_items', 'pdo_details.expense_item_id', '=', 'expense_items.id')
            ->join('expense_subcategories', 'expense_items.subcategory_id', '=', 'expense_subcategories.id')
            ->where('expense_subcategories.category_id', $category->id)
            ->exists();

        if ($usedInPdo) {
            $category->update(['is_active' => false]);
            $category->delete(); // soft delete
            $action = 'STATUS_CHANGE';
        } else {
            // Force-delete semua subcategories (incl. soft-deleted) sebelum menghapus category
            // agar FK constraint tidak gagal.
            $category->subcategories()->withTrashed()->get()->each(function ($sub) {
                $sub->items()->withTrashed()->forceDelete();
                $sub->forceDelete();
            });
            $category->forceDelete();
            $action = 'DELETE';
        }

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_categories',
            entityId: $category->id,
            action: $action,
            oldValues: $category->toArray(),
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // EXPENSE SUBCATEGORIES
    // ─────────────────────────────────────────────────────

    public function listSubcategories(array $filters = []): Collection
    {
        return ExpenseSubcategory::with('category')
            ->when(isset($filters['category_id']), fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();
    }

    public function findSubcategory(string $id): ExpenseSubcategory
    {
        return ExpenseSubcategory::with('category')->findOrFail($id);
    }

    public function createSubcategory(array $data, User $actor): ExpenseSubcategory
    {
        // BR-MASTER-003: Kode unik per category
        $this->assertNoDuplicateSubcategoryCode($data['code'], $data['category_id']);

        $sub = ExpenseSubcategory::create($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_subcategories',
            entityId: $sub->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $sub->toArray()
        );

        return $sub;
    }

    public function updateSubcategory(ExpenseSubcategory $subcategory, array $data, User $actor): ExpenseSubcategory
    {
        // BR-MASTER-003: Kode unik per category
        $categoryId = $data['category_id'] ?? $subcategory->category_id;
        if (isset($data['code']) && $data['code'] !== $subcategory->code) {
            $this->assertNoDuplicateSubcategoryCode($data['code'], $categoryId, $subcategory->id);
        }

        $old = $subcategory->toArray();
        $subcategory->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_subcategories',
            entityId: $subcategory->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $subcategory->fresh()->toArray()
        );

        return $subcategory->fresh();
    }

    public function deleteSubcategory(ExpenseSubcategory $subcategory, User $actor): void
    {
        // BR-MASTER-002: Tolak jika masih ada item aktif
        if ($subcategory->activeItems()->exists()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'SUBCATEGORY_HAS_CHILDREN', 'message' => 'Sub-kategori masih memiliki item biaya aktif.'],
            ], 409));
        }

        // BR-MASTER-004: Hard delete jika belum dipakai di PDO
        $usedInPdo = DB::table('pdo_details')
            ->join('expense_items', 'pdo_details.expense_item_id', '=', 'expense_items.id')
            ->where('expense_items.subcategory_id', $subcategory->id)
            ->exists();

        if ($usedInPdo) {
            $subcategory->update(['is_active' => false]);
            $subcategory->delete(); // soft delete
            $action = 'STATUS_CHANGE';
        } else {
            $subcategory->forceDelete();
            $action = 'DELETE';
        }

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_subcategories',
            entityId: $subcategory->id,
            action: $action,
            oldValues: $subcategory->toArray(),
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // EXPENSE ITEMS
    // ─────────────────────────────────────────────────────

    public function listItems(array $filters = []): Collection
    {
        return ExpenseItem::with(['subcategory.category'])
            ->when(isset($filters['subcategory_id']), fn ($q) => $q->where('subcategory_id', $filters['subcategory_id']))
            ->when(isset($filters['is_routine']), fn ($q) => $q->where('is_routine', $filters['is_routine']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('code')
            ->get();
    }

    /** BR-MASTER-005: hanya item is_routine = true */
    public function listRoutineItems(): Collection
    {
        return ExpenseItem::with(['subcategory.category'])
            ->where('is_routine', true)
            ->where('is_active', true)
            ->orderByRaw("(SELECT display_order FROM expense_subcategories WHERE id = expense_items.subcategory_id)")
            ->orderBy('code')
            ->get();
    }

    public function findItem(string $id): ExpenseItem
    {
        return ExpenseItem::with(['subcategory.category'])->findOrFail($id);
    }

    public function createItem(array $data, User $actor): ExpenseItem
    {
        // BR-MASTER-003: Kode unik per subcategory
        $this->assertNoDuplicateItemCode($data['code'], $data['subcategory_id']);

        $modeInput = $data['mode_input'] ?? ExpenseItem::MODE_MANUAL;

        if ($modeInput !== ExpenseItem::MODE_AUTO_EXTERNAL) {
            $data['external_source_system'] = null;
            $data['external_component'] = null;
            $data['external_component_key'] = null;
            $data['external_role'] = null;
        } elseif (! ExpenseItem::supportsPayrollRole($data['external_component'] ?? null)) {
            $data['external_role'] = null;
        }

        $item = ExpenseItem::create(array_merge(['mode_input' => ExpenseItem::MODE_MANUAL], $data));

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_items',
            entityId: $item->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $item->toArray()
        );

        return $item->load('subcategory.category');
    }

    public function updateItem(ExpenseItem $item, array $data, User $actor): ExpenseItem
    {
        // BR-MASTER-003: Kode unik per subcategory
        $subcategoryId = $data['subcategory_id'] ?? $item->subcategory_id;
        if (isset($data['code']) && $data['code'] !== $item->code) {
            $this->assertNoDuplicateItemCode($data['code'], $subcategoryId, $item->id);
        }

        $modeInput = $data['mode_input'] ?? $item->mode_input;

        if ($modeInput !== ExpenseItem::MODE_AUTO_EXTERNAL) {
            $data['external_source_system'] = null;
            $data['external_component'] = null;
            $data['external_component_key'] = null;
            $data['external_role'] = null;
        } elseif (! ExpenseItem::supportsPayrollRole($data['external_component'] ?? $item->external_component)) {
            $data['external_role'] = null;
        }

        // BR-MASTER-005: perubahan is_routine hanya berlaku ke depan (tidak ada aksi khusus —
        // PDO yang sudah ada memakai snapshot di pdo_details, jadi cukup update saja)

        $old = $item->toArray();
        $oldModeInput = $item->mode_input;
        $item->update($data);
        $freshItem = $item->fresh();

        $this->syncDraftDetailExternalOwnership($item, $oldModeInput, $freshItem);

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_items',
            entityId: $item->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $freshItem->toArray()
        );

        return $freshItem->load('subcategory.category');
    }

    public function updatePlantationUnitPayrollMapping(PlantationUnit $unit, array $data, User $actor): PlantationUnit
    {
        $old = $unit->toArray();

        $unit->update([
            'payroll_estate_external_id' => $data['payroll_estate_external_id'] ?? null,
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'plantation_units',
            entityId: $unit->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $unit->fresh()->toArray()
        );

        return $unit->fresh();
    }

    private function syncDraftDetailExternalOwnership(ExpenseItem $originalItem, string $oldModeInput, ExpenseItem $freshItem): void
    {
        if ($oldModeInput === $freshItem->mode_input) {
            return;
        }

        $draftDetails = \DB::table('pdo_details')
            ->join('pdo_headers', 'pdo_headers.id', '=', 'pdo_details.pdo_header_id')
            ->where('pdo_details.expense_item_id', $originalItem->id)
            ->where('pdo_headers.status', PdoHeader::STATUS_DRAFT);

        if ($freshItem->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL) {
            $draftDetails->update([
                'external_source_system' => $freshItem->external_source_system,
                'external_component' => $freshItem->external_component,
                'external_component_key' => $freshItem->external_component_key,
                'external_amount_pulled_at' => null,
                'external_payload' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        $draftDetails->update([
            'external_source_system' => null,
            'external_component' => null,
            'external_component_key' => null,
            'external_amount_pulled_at' => null,
            'external_payload' => null,
            'updated_at' => now(),
        ]);
    }

    public function deleteItem(ExpenseItem $item, User $actor): void
    {
        // BR-MASTER-004a: Jika dipakai di PDO aktif/final → dilarang hapus sama sekali
        if ($item->isUsedInActivePdo()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'ITEM_IN_USE', 'message' => 'Item biaya sedang digunakan pada PDO yang aktif atau final. Nonaktifkan item melalui menu edit jika diperlukan.'],
            ], 409));
        }

        // BR-MASTER-004b: Jika hanya dipakai di PDO closed → soft delete + nonaktifkan
        if ($item->isUsedInPdo()) {
            $item->update(['is_active' => false]);
            $item->delete(); // soft delete

            AuditLog::record(
                actor: $actor,
                entityType: 'expense_items',
                entityId: $item->id,
                action: 'STATUS_CHANGE',
                oldValues: $item->toArray(),
                newValues: null
            );

            return;
        }

        // Belum dipakai di PDO mana pun → hard delete
        $old = $item->toArray();
        $item->forceDelete();

        AuditLog::record(
            actor: $actor,
            entityType: 'expense_items',
            entityId: $item->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────

    /** BR-MASTER-003 */
    private function assertNoDuplicateCategoryCode(string $code, string $companyId, ?string $exceptId = null): void
    {
        $exists = ExpenseCategory::where('company_id', $companyId)
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DUPLICATE_CODE', 'message' => "Kode kategori '{$code}' sudah digunakan."],
            ], 409));
        }
    }

    /** BR-MASTER-003 */
    private function assertNoDuplicateSubcategoryCode(string $code, string $categoryId, ?string $exceptId = null): void
    {
        $exists = ExpenseSubcategory::where('category_id', $categoryId)
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DUPLICATE_CODE', 'message' => "Kode sub-kategori '{$code}' sudah digunakan dalam kategori ini."],
            ], 409));
        }
    }

    /** BR-MASTER-003 */
    private function assertNoDuplicateItemCode(string $code, string $subcategoryId, ?string $exceptId = null): void
    {
        $exists = ExpenseItem::where('subcategory_id', $subcategoryId)
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DUPLICATE_CODE', 'message' => "Kode item '{$code}' sudah digunakan dalam sub-kategori ini."],
            ], 409));
        }
    }
}
