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
use Illuminate\Support\Facades\Http;
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

    public function test_legacy_base_payroll_external_role_is_normalized_to_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-003B',
            'name' => 'Gaji Pokok Pemanen',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL)
            ->assertJsonPath('data.external_component_key', ExpenseItem::PAYROLL_ROLE_PEMANEN)
            ->assertJsonPath('data.external_role', null);
    }

    public function test_base_payroll_total_allows_empty_external_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'data' => [
                    'options' => [
                        ['component_key' => 'pemanen', 'label' => 'Pemanen'],
                        ['component_key' => 'bhl', 'label' => 'BHL'],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-004C',
            'name' => 'Gaji Pokok Semua',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_component_key' => '',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL)
            ->assertJsonPath('data.external_component_key', null);
    }

    public function test_maintenance_total_allows_empty_external_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'data' => [
                    'options' => [
                        ['component_key' => 'p1', 'label' => 'Pekerjaan 1'],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-004D',
            'name' => 'Maintenance Semua',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
            'external_component_key' => '',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL)
            ->assertJsonPath('data.external_component_key', null);
    }

    public function test_invalid_external_role_is_rejected(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-003C',
            'name' => 'Role Invalid',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_role' => 'mandor',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_role']);
    }

    public function test_external_role_is_rejected_for_non_base_payroll_component(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-003D',
            'name' => 'Role Invalid Component',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
            'external_role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_role']);
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

        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'data' => [
                    'options' => [
                        ['component_key' => 'bonus-id-42', 'label' => 'Bonus Pemanen'],
                    ],
                ],
            ], 200),
        ]);

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

    public function test_additional_wage_type_rejects_invalid_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'data' => [
                    'options' => [
                        ['component_key' => 'bonus-id-42', 'label' => 'Bonus Pemanen'],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-005',
            'name' => 'Additional Type Invalid',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
            'external_component_key' => 'invalid-key',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_component_key']);
    }

    public function test_payroll_validation_failure_rejects_auto_external_save(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'error' => 'Payroll sedang bermasalah.',
            ], 422),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-006',
            'name' => 'Additional Type Payroll Error',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
            'external_component_key' => 'bonus-id-42',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['external_component_key']);
    }

    public function test_non_option_component_clears_external_component_key(): void
    {
        $admin = $this->adminUser();
        $subcategory = $this->expenseSubcategory($admin->company_id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/expense-items', [
            'subcategory_id' => $subcategory->id,
            'code' => 'EXT-007',
            'name' => 'Non Option With Legacy Key',
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
            'external_component_key' => 'should-be-cleared',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL)
            ->assertJsonPath('data.external_component_key', null);
    }

    public function test_legacy_base_payroll_external_role_is_normalized_to_component_key_on_update(): void
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
            'external_role' => ExpenseItem::PAYROLL_ROLE_BHL,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode_input', ExpenseItem::MODE_AUTO_EXTERNAL)
            ->assertJsonPath('data.external_source_system', ExpenseItem::EXTERNAL_SOURCE_PAYROLL)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL)
            ->assertJsonPath('data.external_component_key', ExpenseItem::PAYROLL_ROLE_BHL)
            ->assertJsonPath('data.external_role', null);
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

    private function setPayrollApiConfig(?string $baseUrl, ?string $token): void
    {
        config()->set('services.payroll_internal_api.base_url', $baseUrl);
        config()->set('services.payroll_internal_api.token', $token);
    }
}
