<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PlantationUnit $unit;

    private User $kerani;
    private User $asisten;
    private User $manajer;
    private User $staffKeuangan;

    private int $year  = 2026;
    private int $month = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->unit    = PlantationUnit::factory()->create(['company_id' => $this->company->id]);

        $keraniRole        = Role::factory()->create(['code' => Role::KERANI]);
        $asistenRole       = Role::factory()->create(['code' => Role::ASISTEN_KEBUN]);
        $manajerRole       = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $staffKeuanganRole = Role::factory()->create(['code' => Role::STAFF_KEUANGAN]);

        $this->kerani       = User::factory()->create(['role_id' => $keraniRole->id,        'plantation_unit_id' => $this->unit->id]);
        $this->asisten      = User::factory()->create(['role_id' => $asistenRole->id,       'plantation_unit_id' => $this->unit->id]);
        $this->manajer      = User::factory()->create(['role_id' => $manajerRole->id,       'plantation_unit_id' => $this->unit->id]);
        $this->staffKeuangan= User::factory()->create(['role_id' => $staffKeuanganRole->id, 'plantation_unit_id' => $this->unit->id]);
    }

    // ── 1: all roles can access recap ─────────────────────────────────────────

    public function test_all_roles_can_access_recap(): void
    {
        $params = "?period_year={$this->year}&period_month={$this->month}&unit_id={$this->unit->id}";

        foreach ([$this->kerani, $this->asisten, $this->manajer, $this->staffKeuangan] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/v1/reports/recap{$params}")->assertOk();
        }
    }

    // ── 2: response has required fields ───────────────────────────────────────

    public function test_recap_structure_has_required_fields(): void
    {
        Sanctum::actingAs($this->manajer);
        $this->seedPdo($this->unit);

        $data = $this->getJson("/api/v1/reports/recap?period_year={$this->year}&period_month={$this->month}&unit_id={$this->unit->id}")
                     ->assertOk()
                     ->json('data');

        $this->assertArrayHasKey('period_label', $data);
        $this->assertArrayHasKey('unit', $data);
        $this->assertArrayHasKey('grand_total_amount', $data);
        $this->assertArrayHasKey('grand_total_transfer', $data);
        $this->assertArrayHasKey('grand_total_realization', $data);
        $this->assertArrayHasKey('grand_total_saldo', $data);
        $this->assertArrayHasKey('categories', $data);

        $this->assertNotEmpty($data['categories']);
        $this->assertArrayHasKey('subcategories', $data['categories'][0]);
        $this->assertArrayHasKey('items', $data['categories'][0]['subcategories'][0]);
    }

    // ── 3: kerani cannot see other unit data ─────────────────────────────────

    public function test_kerani_cannot_see_other_unit_data(): void
    {
        $otherUnit = PlantationUnit::factory()->create(['company_id' => $this->company->id]);
        $this->seedPdo($otherUnit); // data on another unit

        Sanctum::actingAs($this->kerani);

        // Even if kerani passes other unit_id, they get their own unit
        $data = $this->getJson("/api/v1/reports/recap?period_year={$this->year}&period_month={$this->month}&unit_id={$otherUnit->id}")
                     ->assertOk()
                     ->json('data');

        // No data for kerani's unit → empty categories
        $this->assertEmpty($data['categories']);
        $this->assertEquals(0, $data['grand_total_amount']);
    }

    // ── 4: manajer can filter by unit ─────────────────────────────────────────

    public function test_manajer_keuangan_can_filter_by_unit(): void
    {
        $otherUnit = PlantationUnit::factory()->create(['company_id' => $this->company->id]);
        $this->seedPdo($otherUnit);

        Sanctum::actingAs($this->manajer);

        $data = $this->getJson("/api/v1/reports/recap?period_year={$this->year}&period_month={$this->month}&unit_id={$otherUnit->id}")
                     ->assertOk()
                     ->json('data');

        $this->assertNotEmpty($data['categories']);
        $this->assertEquals($otherUnit->code, $data['unit']['code']);
    }

    // ── 5: no data → empty categories + zero totals ───────────────────────────

    public function test_recap_with_no_data_returns_empty_categories_and_zero_totals(): void
    {
        Sanctum::actingAs($this->manajer);

        $data = $this->getJson("/api/v1/reports/recap?period_year=2020&period_month=1&unit_id={$this->unit->id}")
                     ->assertOk()
                     ->json('data');

        $this->assertEmpty($data['categories']);
        $this->assertEquals(0, $data['grand_total_amount']);
        $this->assertEquals(0, $data['grand_total_transfer']);
        $this->assertEquals(0, $data['grand_total_realization']);
        $this->assertEquals(0, $data['grand_total_saldo']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function seedPdo(PlantationUnit $unit): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->company->id, 'include_in_recap' => true]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => $this->month,
            'period_year'        => $this->year,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 1_000_000,
        ]);

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 900_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 800_000]);
    }
}
