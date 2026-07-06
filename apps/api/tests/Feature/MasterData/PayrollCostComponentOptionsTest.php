<?php

namespace Tests\Feature\MasterData;

use App\Models\Company;
use App\Models\ExpenseItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollCostComponentOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_user_can_fetch_component_options(): void
    {
        $admin = $this->adminUser();
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

        $component = ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL;
        $response = $this->getJson("/api/v1/payroll-cost-component-options?component={$component}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL)
            ->assertJsonPath('data.options.0.component_key', 'pemanen')
            ->assertJsonPath('data.options.0.label', 'Pemanen')
            ->assertJsonPath('data.options.1.component_key', 'bhl');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-cost-component-options')
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL
                && $request->hasHeader('Authorization', 'Bearer test-payroll-token');
        });
    }

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/payroll-cost-component-options?component='.ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL);

        $response->assertStatus(401);
    }

    public function test_unsupported_component_is_rejected(): void
    {
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $response = $this->getJson('/api/v1/payroll-cost-component-options?component='.ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNSUPPORTED_COMPONENT');
    }

    public function test_payroll_validation_error_is_forwarded_cleanly(): void
    {
        $admin = $this->adminUser();
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'error' => 'Component tidak valid.',
            ], 422),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/payroll-cost-component-options?component='.ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PAYROLL_VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'Component tidak valid.');
    }

    public function test_payroll_unavailable_returns_api_error(): void
    {
        $admin = $this->adminUser();
        $this->setPayrollApiConfig(null, null);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/payroll-cost-component-options?component='.ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL);

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PAYROLL_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Konfigurasi Payroll internal API belum lengkap.');
    }

    public function test_maintenance_block_options_are_forwarded_with_estate_scope(): void
    {
        $admin = $this->adminUser();
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        Http::fake([
            'http://payroll.test/internal/payroll-cost-component-options*' => Http::response([
                'data' => [
                    'options' => [
                        ['component_key' => 'BLK-001', 'label' => 'Alpha'],
                        ['component_key' => 'BLK-003', 'label' => 'Zulu'],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/payroll-cost-component-options?component='
            .ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL
            .'&filter=blocks&estate_external_id=EST-001');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.component', ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL)
            ->assertJsonPath('data.options.0.component_key', 'BLK-001')
            ->assertJsonPath('data.options.1.label', 'Zulu');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-cost-component-options')
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL
                && $request['filter'] === 'blocks'
                && $request['estate_external_id'] === 'EST-001';
        });
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

    private function setPayrollApiConfig(?string $baseUrl, ?string $token): void
    {
        config()->set('services.payroll_internal_api.base_url', $baseUrl);
        config()->set('services.payroll_internal_api.token', $token);
    }
}
