<?php

namespace Tests\Feature\PDO;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PdoExternalCostPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_kerani_can_pull_external_cost_for_draft_auto_external_detail(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Log::spy();

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1250000,
                'employee_count' => 12,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T10:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $response = $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 1250000)
            ->assertJsonPath('data.external_source_system', ExpenseItem::EXTERNAL_SOURCE_PAYROLL)
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL)
            ->assertJsonPath('data.external_component_key', null)
            ->assertJsonPath('data.external_payload.amount', 1250000)
            ->assertJsonPath('data.external_payload.component_label', 'Gaji Pokok')
            ->assertJsonPath('grand_total', 1250000);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request->hasHeader('Authorization', 'Bearer test-payroll-token')
                && $request['year'] === 2026
                && $request['month'] === 6
                && $request['estate_external_id'] === 'EST-001'
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL;
        });

        $detail->refresh();
        $pdo->refresh();

        $this->assertSame(1250000, $detail->amount);
        $this->assertSame(ExpenseItem::EXTERNAL_SOURCE_PAYROLL, $detail->external_source_system);
        $this->assertSame(ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL, $detail->external_component);
        $this->assertNull($detail->external_component_key);
        $this->assertNotNull($detail->external_amount_pulled_at);
        $this->assertSame(1250000, $pdo->grand_total_amount);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'pdo_details',
            'entity_id' => $detail->id,
            'action' => 'EXTERNAL_PULL',
            'actor_user_id' => $kerani->id,
        ]);

        Log::shouldHaveReceived('info')
            ->with('PDO external pull started', \Mockery::on(fn (array $context): bool => $context['pdo_id'] === $pdo->id
                && $context['pdo_detail_id'] === $detail->id
                && $context['actor_user_id'] === $kerani->id
                && $context['payroll_estate_external_id'] === 'EST-001'
                && $context['external_component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('PDO external pull succeeded', \Mockery::on(fn (array $context): bool => $context['pdo_id'] === $pdo->id
                && $context['pdo_detail_id'] === $detail->id
                && $context['amount'] === 1250000
                && $context['payroll_status'] === 'ok'
                && $context['http_status'] === 200))
            ->once();
    }

    public function test_successful_zero_amount_is_stored_as_valid_pull(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake([
            '*' => Http::response([
                'status' => 'empty',
                'amount' => 0,
                'employee_count' => 0,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => null,
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $response = $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost");

        $response->assertOk()
            ->assertJsonPath('data.amount', 0)
            ->assertJsonPath('data.external_payload.status', 'empty')
            ->assertJsonPath('grand_total', 0);

        $detail->refresh();

        $this->assertSame(0, $detail->amount);
        $this->assertNotNull($detail->external_amount_pulled_at);
    }

    public function test_pull_rejects_non_draft_pdo_without_calling_payroll(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $pdo->update(['status' => PdoHeader::STATUS_SUBMITTED]);
        $detail = $this->autoExternalDetail($pdo->fresh());

        Http::fake();

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PDO_NOT_EDITABLE');

        Http::assertNothingSent();
    }

    public function test_pull_rejects_non_auto_external_detail_without_calling_payroll(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->manualDetail($pdo);

        Http::fake();

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonFragment([
                'field' => 'expense_item_id',
                'message' => 'Item ini bukan Auto External sehingga tidak bisa Ambil Data.',
            ]);

        Http::assertNothingSent();
    }

    public function test_pull_rejects_missing_payroll_estate_mapping_without_calling_payroll(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser(payrollEstateExternalId: null);
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake();

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonFragment([
                'field' => 'plantation_unit_id',
                'message' => 'Payroll Estate Mapping belum diatur untuk kebun ini.',
            ]);

        Http::assertNothingSent();
    }

    public function test_pull_rejects_missing_cost_mapping_without_calling_payroll(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);
        $detail->expenseItem()->update([
            'external_source_system' => null,
            'external_component' => null,
        ]);

        Http::fake();

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonFragment([
                'field' => 'expense_item_id',
                'message' => 'Cost Mapping Payroll belum diatur untuk item biaya ini.',
            ]);

        Http::assertNothingSent();
    }

    public function test_pull_rejects_missing_payroll_api_config_without_calling_payroll(): void
    {
        $this->setPayrollApiConfig(null, null);
        Log::spy();

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake();

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYROLL_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Konfigurasi Payroll internal API belum lengkap.');

        Http::assertNothingSent();

        Log::shouldHaveReceived('error')
            ->with('PDO external pull config missing', \Mockery::on(fn (array $context): bool => $context['year'] === 2026
                && $context['month'] === 6
                && $context['estate_external_id'] === 'EST-001'
                && $context['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL
                && array_key_exists('component_key', $context)))
            ->once();
    }

    public function test_payroll_unavailable_does_not_mutate_detail(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');
        Log::spy();

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake([
            '*' => Http::response([
                'error' => 'Payroll sedang tidak tersedia.',
            ], 503),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYROLL_UNAVAILABLE')
            ->assertJsonPath('error.message', 'Payroll sedang tidak tersedia.');

        $detail->refresh();
        $pdo->refresh();

        $this->assertSame(0, $detail->amount);
        $this->assertNull($detail->external_amount_pulled_at);
        $this->assertNull($detail->external_payload);
        $this->assertSame(0, $pdo->grand_total_amount);

        Log::shouldHaveReceived('warning')
            ->with('PDO external pull failed', \Mockery::on(fn (array $context): bool => $context['pdo_id'] === $pdo->id
                && $context['pdo_detail_id'] === $detail->id
                && $context['http_status'] === 503
                && $context['error_message'] === 'Payroll sedang tidak tersedia.'))
            ->once();
    }

    public function test_repull_overwrites_previous_draft_amount_and_metadata(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);
        $detail->update([
            'amount' => 500000,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_payload' => ['amount' => 500000, 'period' => '2026-05'],
            'external_amount_pulled_at' => now()->subDay(),
        ]);
        $pdo->update(['grand_total_amount' => 500000]);

        Http::fake([
            '*' => Http::response([
                'status' => 'ok',
                'amount' => 1750000,
                'employee_count' => 14,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T10:30:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertOk()
            ->assertJsonPath('data.amount', 1750000)
            ->assertJsonPath('grand_total', 1750000);

        $detail->refresh();
        $pdo->refresh();

        $this->assertSame(1750000, $detail->amount);
        $this->assertSame('2026-06', $detail->external_payload['period']);
        $this->assertSame(1750000, $pdo->grand_total_amount);
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'pdo_details')->where('entity_id', $detail->id)->where('action', 'EXTERNAL_PULL')->count());
    }

    public function test_pull_uses_config_values_even_when_process_env_is_empty(): void
    {
        putenv('PAYROLL_INTERNAL_API_BASE_URL');
        putenv('PAYROLL_INTERNAL_API_TOKEN');
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1250000,
                'employee_count' => 12,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T10:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertOk()
            ->assertJsonPath('data.amount', 1250000);

        Http::assertSentCount(1);
    }

    private function setPayrollApiConfig(?string $baseUrl, ?string $token): void
    {
        config()->set('services.payroll_internal_api.base_url', $baseUrl);
        config()->set('services.payroll_internal_api.token', $token);
    }

    private function keraniUser(?string $payrollEstateExternalId = 'EST-001'): User
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['code' => Role::KERANI]);
        $unit = PlantationUnit::factory()->create([
            'company_id' => $company->id,
            'payroll_estate_external_id' => $payrollEstateExternalId,
        ]);

        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $role->id,
            'plantation_unit_id' => $unit->id,
        ]);
    }

    private function draftPdo(User $kerani): PdoHeader
    {
        return PdoHeader::factory()->create([
            'company_id' => $kerani->company_id,
            'plantation_unit_id' => $kerani->plantation_unit_id,
            'created_by' => $kerani->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => PdoHeader::STATUS_DRAFT,
            'grand_total_amount' => 0,
        ]);
    }

    private function autoExternalDetail(PdoHeader $pdo): PdoDetail
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $pdo->company_id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_component_key' => null,
        ]);

        return PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'expense_item_id' => $item->id,
            'amount' => 0,
        ]);
    }

    private function manualDetail(PdoHeader $pdo): PdoDetail
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $pdo->company_id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_MANUAL,
        ]);

        return PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'expense_item_id' => $item->id,
            'amount' => 0,
        ]);
    }
}
