<?php

namespace Tests\Feature\MasterData;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseItemMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_auto_external_item_with_valid_payroll_mapping(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-001',
            'name' => 'Upah Panen',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode_input', ExpenseItem::MODE_AUTO_EXTERNAL)
            ->assertJsonPath('data.external_source_system', ExpenseItem::EXTERNAL_SOURCE_PAYROLL)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL);
    }

    public function test_staff_keuangan_cannot_create_auto_external_item_mapping(): void
    {
        $admin = $this->adminUser();
        $staff = $this->staffUser($admin->company_id);
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-002',
            'name' => 'Upah Panen Staff',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mode_input']);
    }

    public function test_invalid_payroll_component_is_rejected(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-003',
            'name' => 'Invalid Component',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => 'invalid_component',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_component']);
    }

    public function test_additional_wage_type_requires_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-004',
            'name' => 'Additional Type',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_component_key']);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-004B',
            'name' => 'Additional Type B',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
            'external_component_key' => 'bonus-id-42',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL)
            ->assertJsonPath('data.external_component_key', 'bonus-id-42');
    }

    public function test_admin_can_update_auto_external_item_mapping(): void
    {
        $admin = $this->adminUser();
        $category = ExpenseCategory::factory()->create(['company_id' => $admin->company_id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_MANUAL,
            'external_source_system' => null,
            'external_component' => null,
            'external_component_key' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/v1/expense-items/{$item->id}", [
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode_input', ExpenseItem::MODE_AUTO_EXTERNAL)
            ->assertJsonPath('data.external_source_system', ExpenseItem::EXTERNAL_SOURCE_PAYROLL)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL);
    }

    public function test_admin_can_update_payroll_estate_mapping_and_finance_cannot_modify(): void
    {
        $admin = $this->adminUser();
        $staff = $this->staffUser($admin->company_id);

        $unit = PlantationUnit::factory()->create([
            'company_id' => $admin->company_id,
            'payroll_estate_external_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/v1/plantation-units/{$unit->id}", [
            'payroll_estate_external_id' => 'P-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payroll_estate_external_id', 'P-001');

        Sanctum::actingAs($staff);

        $response = $this->putJson("/api/v1/plantation-units/{$unit->id}", [
            'payroll_estate_external_id' => 'P-002',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $response = $this->getJson('/api/v1/plantation-units');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.payroll_estate_external_id', 'P-001');
    }

    public function test_finance_cannot_disable_auto_external_mapping_on_expense_item(): void
    {
        $admin = $this->adminUser();
        $staff = $this->staffUser($admin->company_id);
        $category = ExpenseCategory::factory()->create(['company_id' => $admin->company_id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
            'external_component_key' => null,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->putJson("/api/v1/expense-items/{$item->id}", [
            'mode_input' => ExpenseItem::MODE_MANUAL,
            'name' => 'Updated by staff',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mode_input']);
    }

    private function adminUser(): User
    {
        $company = Company::factory()->create();
        $adminRole = Role::factory()->create(['code' => Role::ADMIN]);

        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);
    }

    private function staffUser(string $companyId): User
    {
        $staffRole = Role::factory()->create(['code' => Role::STAFF_KEUANGAN]);

        return User::factory()->create([
            'company_id' => $companyId,
            'role_id' => $staffRole->id,
        ]);
    }

    private function expenseSubcategory(string $companyId): ExpenseSubcategory
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $companyId]);

        return ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
    }
}
