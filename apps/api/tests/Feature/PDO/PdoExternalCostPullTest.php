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
        $detail = $this->autoExternalDetail($pdo, externalRole: null);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1250000,
                'unit' => 'HK',
                'volume' => 12,
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
            ->assertJsonPath('data.quantity', 12)
            ->assertJsonPath('data.unit', 'HK')
            ->assertJsonPath('data.external_payload.amount', 1250000)
            ->assertJsonPath('data.external_payload.component_label', 'Gaji Pokok')
            ->assertJsonPath('data.external_payload.source_system', ExpenseItem::EXTERNAL_SOURCE_PAYROLL)
            ->assertJsonPath('grand_total', 1250000);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request->hasHeader('Authorization', 'Bearer test-payroll-token')
                && $request['year'] === 2026
                && $request['month'] === 6
                && $request['estate_external_id'] === 'EST-001'
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL
                && ! isset($request['component_key'])
                && ! isset($request['role']);
        });

        $detail->refresh();
        $pdo->refresh();

        $this->assertSame(1250000, $detail->amount);
        $this->assertSame(12.0, $detail->quantity);
        $this->assertSame('HK', $detail->unit);
        $this->assertSame(ExpenseItem::EXTERNAL_SOURCE_PAYROLL, $detail->external_source_system);
        $this->assertSame(ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL, $detail->external_component);
        $this->assertNull($detail->external_component_key);
        $this->assertNotNull($detail->external_amount_pulled_at);
        $this->assertSame(1250000, $pdo->grand_total_amount);
        $this->assertSame(ExpenseItem::EXTERNAL_SOURCE_PAYROLL, $detail->external_payload['source_system']);
        $this->assertNull($detail->external_payload['role'] ?? null);

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

    public function test_pull_external_cost_uses_previous_month_payroll_period(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $pdo->update([
            'period_year' => 2026,
            'period_month' => 7,
        ]);
        $detail = $this->autoExternalDetail($pdo, externalRole: null);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1250000,
                'unit' => 'HK',
                'volume' => 12,
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
            ->assertJsonPath('data.external_payload.period', '2026-06');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['year'] === 2026
                && $request['month'] === 6;
        });
    }

    public function test_pull_external_cost_previous_month_rolls_back_year_for_january_pdo(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $pdo->update([
            'period_year' => 2027,
            'period_month' => 1,
        ]);
        $detail = $this->autoExternalDetail($pdo, externalRole: null);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1250000,
                'unit' => 'HK',
                'volume' => 12,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-12',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-12-23T10:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")
            ->assertOk();

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['year'] === 2026
                && $request['month'] === 12;
        });
    }

    public function test_pull_base_payroll_with_selected_component_key_does_not_send_legacy_role_param(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo, externalComponentKey: 'bhl', externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1800000,
                'unit' => 'HK',
                'volume' => 15,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T10:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")->assertOk()
            ->assertJsonPath('data.external_component_key', 'bhl')
            ->assertJsonPath('data.external_payload.component_key', 'bhl')
            ->assertJsonPath('data.external_payload.role', null);

        $detail->refresh();
        $this->assertSame('bhl', $detail->external_component_key);
        $this->assertSame('bhl', $detail->external_payload['component_key']);
        $this->assertNull($detail->external_payload['role'] ?? null);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request->hasHeader('Authorization', 'Bearer test-payroll-token')
                && $request['year'] === 2026
                && $request['month'] === 6
                && $request['estate_external_id'] === 'EST-001'
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL
                && $request['component_keys'] === ['bhl']
                && ! isset($request['component_key'])
                && ! isset($request['role']);
        });
    }

    public function test_pull_base_payroll_with_legacy_role_maps_to_component_key_request_param(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail(
            $pdo,
            externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN,
            externalComponentKey: null,
        );

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 900000,
                'unit' => 'HK',
                'volume' => 9,
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
            ->assertJsonPath('data.external_component_key', ExpenseItem::PAYROLL_ROLE_PEMANEN)
            ->assertJsonPath('data.external_payload.component_key', ExpenseItem::PAYROLL_ROLE_PEMANEN)
            ->assertJsonPath('data.external_payload.role', null);

        $detail->refresh();

        $this->assertSame(ExpenseItem::PAYROLL_ROLE_PEMANEN, $detail->external_component_key);
        $this->assertSame(ExpenseItem::PAYROLL_ROLE_PEMANEN, $detail->external_payload['component_key']);
        $this->assertNull($detail->external_payload['role'] ?? null);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['year'] === 2026
                && $request['month'] === 6
                && $request['estate_external_id'] === 'EST-001'
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL
                && $request['component_keys'] === [ExpenseItem::PAYROLL_ROLE_PEMANEN]
                && ! isset($request['component_key'])
                && ! isset($request['role']);
        });
    }

    public function test_pull_stores_component_key_in_snapshot_for_option_component(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo, externalRole: null, externalComponentKey: 'bhl');

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 1600000,
                'unit' => 'HK',
                'volume' => 11,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T11:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);
        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")->assertOk();

        $detail->refresh();

        $this->assertSame('bhl', $detail->external_component_key);
        $this->assertSame('bhl', $detail->external_payload['component_key']);
        $this->assertSame(1600000, $detail->external_payload['amount']);
        $this->assertNull($detail->external_payload['role'] ?? null);
    }

    public function test_pull_maintenance_component_passes_component_key_without_role(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail(
            $pdo,
            externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN,
            externalComponentKey: 'pekerjaan-001',
            externalComponent: ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
        );

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 2100000,
                'unit' => 'HK',
                'volume' => 18,
                'component' => ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
                'component_label' => 'Pekerjaan Lain',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T12:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $response = $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost");

        $response->assertOk()
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL)
            ->assertJsonPath('data.external_component_key', 'pekerjaan-001')
            ->assertJsonPath('data.external_payload.component_key', 'pekerjaan-001')
            ->assertJsonPath('data.external_payload.role', null);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL
                && $request['component_keys'] === ['pekerjaan-001']
                && ! isset($request['component_key'])
                && ! isset($request['role']);
        });
    }

    public function test_pull_maintenance_selector_sets_send_component_keys_and_block_keys_and_store_lot_snapshot(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $unitB = PlantationUnit::factory()->create([
            'company_id' => $kerani->company_id,
            'payroll_estate_external_id' => 'EST-002',
        ]);
        $detail = $this->autoExternalDetail(
            $pdo,
            externalRole: null,
            externalComponentKey: null,
            externalComponent: ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
        );
        $detail->expenseItem()->update([
            'external_component_keys' => ['PT-002', 'PT-001'],
            'external_block_scopes' => [
                [
                    'plantation_unit_id' => $pdo->plantation_unit_id,
                    'block_keys' => ['BLK-002', 'BLK-001'],
                ],
                [
                    'plantation_unit_id' => $unitB->id,
                    'block_keys' => ['BLK-101'],
                ],
            ],
        ]);
        $detail->update([
            'external_component_keys' => ['PT-002', 'PT-001'],
            'external_block_keys' => ['BLK-002', 'BLK-001'],
        ]);

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 3100000,
                'unit' => 'HK',
                'volume' => 22,
                'component' => ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
                'component_label' => 'Alat Berat, Zebra Work',
                'block_label' => 'Bravo, Alpha',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T12:30:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $response = $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost");

        $response->assertOk()
            ->assertJsonPath('data.amount', 3100000)
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.unit', 'lot')
            ->assertJsonPath('data.external_component_key', null)
            ->assertJsonPath('data.external_component_keys.0', 'PT-002')
            ->assertJsonPath('data.external_component_keys.1', 'PT-001')
            ->assertJsonPath('data.external_block_keys.0', 'BLK-002')
            ->assertJsonPath('data.external_block_keys.1', 'BLK-001')
            ->assertJsonPath('data.external_payload.component_keys.0', 'PT-002')
            ->assertJsonPath('data.external_payload.block_keys.0', 'BLK-002');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL
                && $request['component_keys'] === ['PT-002', 'PT-001']
                && $request['block_keys'] === ['BLK-002', 'BLK-001']
                && ! isset($request['component_key']);
        });
    }

    public function test_create_pdo_snapshots_only_relevant_maintenance_block_scope_for_unit(): void
    {
        $kerani = $this->keraniUser();
        $unitA = $kerani->plantationUnit;
        $unitB = PlantationUnit::factory()->create([
            'company_id' => $kerani->company_id,
            'payroll_estate_external_id' => 'EST-002',
        ]);
        $subcategory = ExpenseSubcategory::factory()->create([
            'category_id' => ExpenseCategory::factory()->create(['company_id' => $kerani->company_id])->id,
        ]);

        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
            'external_component_keys' => ['PT-001'],
            'external_block_scopes' => [
                [
                    'plantation_unit_id' => $unitA->id,
                    'block_keys' => ['BLK-001', 'BLK-002'],
                ],
                [
                    'plantation_unit_id' => $unitB->id,
                    'block_keys' => ['BLK-101'],
                ],
            ],
            'is_routine' => true,
            'routine_plantation_unit_ids' => [$unitA->id],
            'is_active' => true,
        ]);

        Sanctum::actingAs($kerani);

        $response = $this->postJson('/api/v1/pdo', [
            'plantation_unit_id' => $unitA->id,
            'period_month' => 6,
            'period_year' => 2026,
        ]);

        $response->assertCreated();

        $pdo = PdoHeader::with('details')->findOrFail($response->json('data.id'));
        $detail = $pdo->details->firstWhere('expense_item_id', $item->id);

        $this->assertNotNull($detail);
        $this->assertSame(['BLK-001', 'BLK-002'], $detail->external_block_keys);
    }

    public function test_pull_non_option_component_does_not_send_component_key_or_role(): void
    {
        $this->setPayrollApiConfig('http://payroll.test', 'test-payroll-token');

        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail(
            $pdo,
            externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN,
            externalComponentKey: 'ignored-key',
            externalComponent: ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
        );

        Http::fake([
            'http://payroll.test/internal/payroll-costs*' => Http::response([
                'status' => 'ok',
                'amount' => 990000,
                'unit' => 'HK',
                'volume' => 9,
                'component' => ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
                'component_label' => 'Panen TBS',
                'period' => '2026-06',
                'estate_external_id' => 'EST-001',
                'generated_at' => '2026-06-23T13:00:00+07:00',
            ], 200),
        ]);

        Sanctum::actingAs($kerani);

        $this->postJson("/api/v1/pdo/{$pdo->id}/details/{$detail->id}/pull-external-cost")->assertOk()
            ->assertJsonPath('data.external_component', ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL)
            ->assertJsonPath('data.external_component_key', 'ignored-key');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'http://payroll.test/internal/payroll-costs')
                && $request['component'] === ExpenseItem::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL
                && ! isset($request['component_key'])
                && ! isset($request['role']);
        });
    }

    public function test_non_draft_pdo_detail_snapshot_is_not_modified_when_cost_mapping_changes_for_final_and_closed(): void
    {
        foreach ([PdoHeader::STATUS_FINAL, PdoHeader::STATUS_CLOSED] as $status) {
            $kerani = $this->keraniUser();
            $admin = $this->adminUser($kerani->company_id);

            $pdo = $this->draftPdo($kerani);
            $pdo->update(['status' => $status]);
            $detail = $this->autoExternalDetail($pdo, externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN);
            $detail->update([
                'external_payload' => [
                    'status' => 'ok',
                    'amount' => 700000,
                    'unit' => 'HK',
                    'volume' => 7,
                    'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                    'source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                    'component_key' => 'pemanen',
                    'role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
                ],
                'external_amount_pulled_at' => now()->subDay(),
            ]);

            $this->fakePayrollComponentOptions();

            Sanctum::actingAs($admin);
            $this->putJson("/api/v1/expense-items/{$detail->expense_item_id}", [
                'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
                'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'external_component_key' => 'bhl',
            ])->assertStatus(200);

            $detail->refresh();

            $this->assertNull($detail->external_component_key);
            $this->assertSame(700000, $detail->external_payload['amount']);
            $this->assertSame(ExpenseItem::PAYROLL_ROLE_PEMANEN, $detail->external_payload['role']);
            $this->assertNotNull($detail->external_amount_pulled_at);
            $this->assertSame($status, $pdo->status);

            $detail->refresh();
            $this->assertNull($detail->external_component_key);
            $this->assertSame(ExpenseItem::PAYROLL_ROLE_PEMANEN, $detail->external_payload['role']);
            $this->assertFalse($detail->is_stale_external_snapshot);
        }
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
                'unit' => 'HK',
                'volume' => 0,
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
            ->assertJsonPath('data.quantity', 0)
            ->assertJsonPath('data.unit', 'HK')
            ->assertJsonPath('data.external_payload.status', 'empty')
            ->assertJsonPath('grand_total', 0);

        $detail->refresh();

        $this->assertSame(0, $detail->amount);
        $this->assertSame(0.0, $detail->quantity);
        $this->assertSame('HK', $detail->unit);
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
                'unit' => 'HK',
                'volume' => 14,
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
        $this->assertSame(14.0, $detail->quantity);
        $this->assertSame('HK', $detail->unit);
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
                'unit' => 'HK',
                'volume' => 12,
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

    public function test_detail_flags_show_stale_snapshot_when_mapping_changes(): void
    {
        $kerani = $this->keraniUser();
        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo, externalRole: ExpenseItem::PAYROLL_ROLE_PEMANEN);

        $detail->update([
            'amount' => 700000,
            'quantity' => 7,
            'unit' => 'HK',
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_payload' => [
                'status' => 'ok',
                'amount' => 700000,
                'unit' => 'HK',
                'volume' => 7,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_label' => 'Gaji Pokok',
                'source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                'component_key' => null,
                'role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
            ],
            'external_amount_pulled_at' => now(),
        ]);

        $detail->expenseItem()->update([
            'external_role' => ExpenseItem::PAYROLL_ROLE_BHL,
        ]);

        Sanctum::actingAs($kerani);

        $this->getJson("/api/v1/pdo/{$pdo->id}/details")
            ->assertOk()
            ->assertJsonPath('data.0.is_auto_external_active', true)
            ->assertJsonPath('data.0.needs_pull', true)
            ->assertJsonPath('data.0.is_stale_external_snapshot', true)
            ->assertJsonPath('data.0.is_external_read_only', true);
    }

    public function test_changing_cost_mapping_refreshes_draft_detail_snapshot(): void
    {
        $kerani = $this->keraniUser();
        $admin = $this->adminUser($kerani->company_id);

        $pdo = $this->draftPdo($kerani);
        $detail = $this->autoExternalDetail($pdo, externalComponentKey: 'pemanen');
        $detail->update([
            'external_payload' => [
                'status' => 'ok',
                'amount' => 900000,
                'unit' => 'HK',
                'volume' => 9,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                'component_key' => 'pemanen',
                'role' => null,
            ],
            'external_amount_pulled_at' => now(),
            'amount' => 900000,
            'quantity' => 9,
            'unit' => 'HK',
        ]);

        $this->fakePayrollComponentOptions();

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/expense-items/{$detail->expense_item_id}", [
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_component_key' => 'bhl',
        ])->assertStatus(200)
            ->assertJsonPath('data.external_component_key', 'bhl');

        $detail->refresh();

        $this->assertSame('bhl', $detail->external_component_key);
        $this->assertNull($detail->external_amount_pulled_at);
        $this->assertNull($detail->external_payload);

        Sanctum::actingAs($kerani);
        $this->getJson("/api/v1/pdo/{$pdo->id}/details")->assertOk()
            ->assertJsonPath('data.0.needs_pull', true)
            ->assertJsonPath('data.0.external_component_key', 'bhl');
    }

    public function test_submitted_pdo_detail_snapshot_is_not_modified_when_cost_mapping_changes(): void
    {
        $kerani = $this->keraniUser();
        $admin = $this->adminUser($kerani->company_id);

        $pdo = $this->draftPdo($kerani);
        $pdo->update(['status' => PdoHeader::STATUS_SUBMITTED]);
        $detail = $this->autoExternalDetail($pdo, externalComponentKey: 'pemanen');
        $detail->update([
            'external_payload' => [
                'status' => 'ok',
                'amount' => 700000,
                'unit' => 'HK',
                'volume' => 7,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                'component_key' => 'pemanen',
            ],
            'external_amount_pulled_at' => now()->subDay(),
        ]);

        $this->fakePayrollComponentOptions();

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/expense-items/{$detail->expense_item_id}", [
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_component_key' => 'bhl',
        ])->assertStatus(200);

        $detail->refresh();
        $this->assertNull($detail->external_component_key);
        $this->assertSame(700000, $detail->external_payload['amount']);
        $this->assertNotNull($detail->external_amount_pulled_at);
        Sanctum::actingAs($kerani);
        $this->getJson("/api/v1/pdo/{$pdo->id}/details")->assertOk()
            ->assertJsonPath('data.0.is_stale_external_snapshot', false);
    }

    private function setPayrollApiConfig(?string $baseUrl, ?string $token): void
    {
        config()->set('services.payroll_internal_api.base_url', $baseUrl);
        config()->set('services.payroll_internal_api.token', $token);
    }

    private function fakePayrollComponentOptions(): void
    {
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
    }

    private function adminUser(?string $companyId = null): User
    {
        $company = $companyId ? Company::find($companyId) : Company::factory()->create();

        if (! $company) {
            throw new \RuntimeException('Company not found for admin user.');
        }

        $adminRole = Role::firstOrCreate(['code' => Role::ADMIN], [
            'name' => 'Admin',
            'description' => 'ADMIN',
        ]);

        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);
    }

    private function keraniUser(?string $payrollEstateExternalId = 'EST-001'): User
    {
        $company = Company::factory()->create();
        $role = Role::firstOrCreate(['code' => Role::KERANI], [
            'name' => 'Kerani',
            'description' => 'KERANI',
        ]);
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
            'period_month' => 7,
            'status' => PdoHeader::STATUS_DRAFT,
            'grand_total_amount' => 0,
        ]);
    }

    private function autoExternalDetail(
        PdoHeader $pdo,
        ?string $externalRole = ExpenseItem::PAYROLL_ROLE_PEMANEN,
        ?string $externalComponentKey = null,
        ?string $externalComponent = ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
    ): PdoDetail {
        $category = ExpenseCategory::factory()->create(['company_id' => $pdo->company_id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item = ExpenseItem::factory()->create([
            'subcategory_id' => $subcategory->id,
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => $externalComponent,
            'external_component_key' => $externalComponentKey,
            'external_role' => $externalRole,
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
