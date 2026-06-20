<?php

namespace Tests\Unit\Services\MasterData;

use App\Models\Company;
use App\Models\AuditLog;
use App\Models\PlantationUnit;
use App\Models\PdoHeader;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use App\Services\MasterData\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private MasterDataService $service;
    private User $adminUser;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service   = new MasterDataService();
        $this->companyId = Company::factory()->create()->id;
        $this->adminUser = User::factory()->create(['company_id' => $this->companyId]);
    }

    // ─────────────────────────────────────────────────────
    // BR-MASTER-001: Hierarki kategori → sub-kategori → item
    // ─────────────────────────────────────────────────────

    public function test_creates_category_subcategory_item_hierarchy(): void
    {
        $category = $this->service->createCategory([
            'company_id' => $this->companyId,
            'code'       => 'A',
            'name'       => 'Gaji Staff',
        ], $this->adminUser);

        $sub = $this->service->createSubcategory([
            'category_id' => $category->id,
            'code'        => 'A1',
            'name'        => 'Staff Kebun',
        ], $this->adminUser);

        $item = $this->service->createItem([
            'subcategory_id' => $sub->id,
            'code'           => 'A1-001',
            'name'           => 'Gaji Manager',
        ], $this->adminUser);

        $this->assertEquals($category->id, $sub->category_id);
        $this->assertEquals($sub->id, $item->subcategory_id);
        $this->assertEquals('A1', $item->subcategory->code);
        $this->assertEquals('A', $item->subcategory->category->code);
    }

    // ─────────────────────────────────────────────────────
    // BR-MASTER-002: Integritas referensial
    // ─────────────────────────────────────────────────────

    public function test_cannot_delete_category_with_active_subcategories(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        ExpenseSubcategory::factory()->create(['category_id' => $category->id, 'is_active' => true]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->deleteCategory($category, $this->adminUser);
    }

    public function test_cannot_delete_subcategory_with_active_items(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_active' => true]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->deleteSubcategory($sub, $this->adminUser);
    }

    public function test_can_delete_category_without_active_subcategories(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        // sub-kategori sudah di-soft-delete
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $category->id, 'is_active' => false]);
        $sub->delete();

        $this->service->deleteCategory($category, $this->adminUser);

        $this->assertDatabaseMissing('expense_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    // ─────────────────────────────────────────────────────
    // BR-MASTER-003: Kode unik
    // ─────────────────────────────────────────────────────

    public function test_duplicate_category_code_is_rejected(): void
    {
        ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'code' => 'A']);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->createCategory([
            'company_id' => $this->companyId,
            'code'       => 'A',
            'name'       => 'Lainnya',
        ], $this->adminUser);
    }

    public function test_duplicate_subcategory_code_rejected_within_same_category(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        ExpenseSubcategory::factory()->create(['category_id' => $category->id, 'code' => 'A1']);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->createSubcategory([
            'category_id' => $category->id,
            'code'        => 'A1',
            'name'        => 'Duplikat',
        ], $this->adminUser);
    }

    public function test_same_subcategory_code_allowed_in_different_categories(): void
    {
        $cat1 = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'code' => 'A']);
        $cat2 = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'code' => 'B']);
        ExpenseSubcategory::factory()->create(['category_id' => $cat1->id, 'code' => 'X1']);

        $sub = $this->service->createSubcategory([
            'category_id' => $cat2->id,
            'code'        => 'X1', // sama, tapi beda kategori — valid
            'name'        => 'Sub di kategori B',
        ], $this->adminUser);

        $this->assertEquals('X1', $sub->code);
    }

    public function test_duplicate_item_code_rejected_within_same_subcategory(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'code' => 'A1-001']);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->createItem([
            'subcategory_id' => $sub->id,
            'code'           => 'A1-001',
            'name'           => 'Duplikat',
        ], $this->adminUser);
    }

    // ─────────────────────────────────────────────────────
    // BR-MASTER-004: Soft delete vs hard delete
    // ─────────────────────────────────────────────────────

    public function test_hard_delete_item_not_used_in_pdo(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $this->service->deleteItem($item, $this->adminUser);

        $this->assertDatabaseMissing('expense_items', ['id' => $item->id]);
    }

    public function test_soft_delete_item_already_used_in_pdo(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        // Simulasi item sudah pernah dipakai di PDO
        $unit = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        $pdo  = PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $unit->id]);
        DB::table('pdo_details')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'description'     => 'Test detail',
            'amount'          => 0,
            'display_order'   => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->service->deleteItem($item, $this->adminUser);

        // Harus tetap ada di DB (soft delete), tapi is_active = false
        $this->assertSoftDeleted('expense_items', ['id' => $item->id]);
        $this->assertDatabaseHas('expense_items', ['id' => $item->id, 'is_active' => false]);
    }

    // ─────────────────────────────────────────────────────
    // BR-MASTER-005 & BR-MASTER-006
    // ─────────────────────────────────────────────────────

    public function test_routine_items_list_returns_only_active_routine_items(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_routine' => true,  'is_active' => true]);
        ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_routine' => false, 'is_active' => true]);
        ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_routine' => true,  'is_active' => false]);

        $results = $this->service->listRoutineItems();

        $this->assertCount(1, $results);
        $this->assertTrue((bool) $results->first()->is_routine);
        $this->assertTrue((bool) $results->first()->is_active);
    }

    public function test_mode_input_defaults_to_manual(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        $item = $this->service->createItem([
            'subcategory_id' => $sub->id,
            'code'           => 'X-001',
            'name'           => 'Item Test',
        ], $this->adminUser);

        $this->assertEquals(ExpenseItem::MODE_MANUAL, $item->mode_input);
    }

    public function test_mode_input_auto_external_is_stored_correctly(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        $item = $this->service->createItem([
            'subcategory_id' => $sub->id,
            'code'           => 'X-002',
            'name'           => 'Item External',
            'mode_input'     => ExpenseItem::MODE_AUTO_EXTERNAL,
        ], $this->adminUser);

        $this->assertEquals(ExpenseItem::MODE_AUTO_EXTERNAL, $item->mode_input);
    }

    // ─────────────────────────────────────────────────────
    // Audit Log
    // ─────────────────────────────────────────────────────

    public function test_audit_log_written_on_create_category(): void
    {
        $this->service->createCategory([
            'company_id' => $this->companyId,
            'code'       => 'Z',
            'name'       => 'Test Audit',
        ], $this->adminUser);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'expense_categories',
            'action'      => 'INSERT',
            'actor_user_id'    => $this->adminUser->id,
        ]);
    }

    public function test_audit_log_written_on_delete_item(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $this->service->deleteItem($item, $this->adminUser);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'expense_items',
            'action'      => 'DELETE',
            'actor_user_id'    => $this->adminUser->id,
        ]);
    }
}
