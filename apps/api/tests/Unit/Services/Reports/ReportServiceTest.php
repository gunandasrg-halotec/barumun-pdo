<?php

namespace Tests\Unit\Services\Reports;

use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private User $manajer;
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new ReportService();
        $this->companyId = (string) Str::uuid();
        $this->year      = 2026;
        $this->month     = 6;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role          = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajer = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $role->id]);
    }

    public function test_realization_report_returns_rows_for_period(): void
    {
        $this->seedPdoWithRealization(budget: 1000000, realized: 800000);

        $rows = $this->service->realization($this->manajer, ['year' => $this->year, 'month' => $this->month]);

        $this->assertNotEmpty($rows);
        $this->assertEquals(1000000, $rows[0]->budget);
        $this->assertEquals(800000, $rows[0]->total_realized);
    }

    public function test_realization_report_empty_for_different_period(): void
    {
        $this->seedPdoWithRealization(budget: 1000000, realized: 800000);

        $rows = $this->service->realization($this->manajer, ['year' => 2025, 'month' => 1]);

        $this->assertEmpty($rows);
    }

    public function test_over_budget_report_only_returns_overspent_items(): void
    {
        $this->seedPdoWithRealization(budget: 500000, realized: 700000); // over
        $this->seedPdoWithRealization(budget: 1000000, realized: 800000, month: 6, unitNew: true); // dalam budget

        $rows = $this->service->overBudget($this->manajer, ['year' => $this->year, 'month' => $this->month]);

        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[0]->over_amount);
    }

    public function test_missing_proof_returns_entries_above_threshold_without_attachment(): void
    {
        // Setup threshold di system_settings
        \DB::table('system_settings')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'key'        => SystemSetting::KEY_THRESHOLD_PROOF,
            'value'      => '500000',
            'updated_at' => now(),
        ]);

        [$pdo, $detail] = $this->seedPdoBase();

        // Realisasi di atas threshold, tanpa attachment
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => 600000, // > threshold 500.000
        ]);

        $rows = $this->service->missingProof($this->manajer, ['year' => $this->year, 'month' => $this->month]);

        $this->assertNotEmpty($rows);
    }

    public function test_recap_sums_budget_and_realized_by_category(): void
    {
        $this->seedPdoWithRealization(budget: 2000000, realized: 1500000);

        $rows = $this->service->recap($this->manajer, ['year' => $this->year, 'month' => $this->month]);

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(0, $rows[0]->total_budget);
        $this->assertGreaterThanOrEqual(0, $rows[0]->absorption_pct);
    }

    // ─────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────

    private function seedPdoWithRealization(int $budget, int $realized, int $month = null, bool $unitNew = false): array
    {
        [$pdo, $detail] = $this->seedPdoBase($month, $unitNew);

        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => $realized,
        ]);

        return [$pdo, $detail];
    }

    private function seedPdoBase(int $month = null, bool $unitNew = false): array
    {
        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $unit       = $unitNew ? PlantationUnit::factory()->create(['company_id' => $this->companyId]) : $this->unit;

        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => $month ?? $this->month,
            'period_year'        => $this->year,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id'  => $pdo->id,
            'expense_item_id'=> $item->id,
            'amount'         => 1000000,
        ]);

        return [$pdo, $detail];
    }
}
