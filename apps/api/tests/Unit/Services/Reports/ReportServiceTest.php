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
use App\Models\TransferEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Company;
use App\Services\Reports\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportQueryService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new ReportQueryService();
        $company         = Company::factory()->create();
        $this->companyId = $company->id;
        $this->year      = 2026;
        $this->month     = 6;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
    }

    public function test_realization_returns_rows_for_period(): void
    {
        $this->seedPdoWithRealization(budget: 1_000_000, realized: 800_000);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month, 'company_id' => $this->companyId];
        $rows    = $this->service->getRealizationData($filters);

        $this->assertNotEmpty($rows);
        $this->assertEquals(1_000_000, (int) $rows->first()->amount);
        $this->assertEquals(800_000, (int) $rows->first()->total_realization);
    }

    public function test_realization_empty_for_different_period(): void
    {
        $this->seedPdoWithRealization(budget: 1_000_000, realized: 800_000);

        $rows = $this->service->getRealizationData(['period_year' => 2025, 'period_month' => 1]);

        $this->assertEmpty($rows);
    }

    public function test_over_budget_only_returns_overspent_items(): void
    {
        $this->seedPdoWithRealization(budget: 500_000, realized: 700_000);   // over
        $this->seedPdoWithRealization(budget: 1_000_000, realized: 800_000); // within budget

        $filters = ['period_year' => $this->year, 'period_month' => $this->month];
        $rows    = $this->service->getOverBudgetData($filters);

        $this->assertCount(1, $rows);
        $this->assertGreaterThan(
            (int) $rows->first()->total_transfer,
            (int) $rows->first()->total_realization
        );
    }

    public function test_missing_proof_returns_entries_above_threshold_without_attachment(): void
    {
        \DB::table('system_settings')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'key'        => SystemSetting::KEY_THRESHOLD_PROOF,
            'value'      => '500000',
            'updated_at' => now(),
        ]);

        [, $detail] = $this->seedPdoBase();

        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => 600_000,
        ]);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month, 'company_id' => $this->companyId];
        $rows    = $this->service->getMissingProofData($filters);

        $this->assertNotEmpty($rows);
    }

    public function test_missing_proof_excludes_entries_with_attachment(): void
    {
        \DB::table('system_settings')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'key'        => SystemSetting::KEY_THRESHOLD_PROOF,
            'value'      => '500000',
            'updated_at' => now(),
        ]);

        [, $detail] = $this->seedPdoBase();

        $entry = RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => 600_000,
        ]);

        // Attach a file — should be excluded
        \DB::table('realization_attachments')->insert([
            'id'                    => (string) Str::uuid(),
            'realization_entry_id'  => $entry->id,
            'file_name'             => 'proof.jpg',
            'file_path'             => 'proofs/proof.jpg',
            'mime_type'             => 'image/jpeg',
            'file_size_bytes'       => 10240,
            'uploaded_by'           => $entry->recorded_by,
            'created_at'            => now(),
        ]);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month, 'company_id' => $this->companyId];
        $rows    = $this->service->getMissingProofData($filters);

        $this->assertEmpty($rows);
    }

    public function test_recap_returns_hierarchical_structure(): void
    {
        $this->seedPdoWithRealization(budget: 2_000_000, realized: 1_500_000);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month];
        $recap   = $this->service->getRecapData($filters);

        $this->assertArrayHasKey('categories', $recap);
        $this->assertArrayHasKey('grand_total_amount', $recap);
        $this->assertNotEmpty($recap['categories']);
        $this->assertNotEmpty($recap['categories'][0]['subcategories']);
    }

    public function test_status_resolves_to_sesuai_when_realization_equals_transfer(): void
    {
        [, $detail] = $this->seedPdoBase();

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 500_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 500_000]);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month];
        $row     = $this->service->getRealizationData($filters)->first();

        $this->assertEquals('sesuai', $row->status);
    }

    public function test_status_resolves_to_over_budget(): void
    {
        [, $detail] = $this->seedPdoBase();

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 300_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 400_000]);

        $filters = ['period_year' => $this->year, 'period_month' => $this->month];
        $row     = $this->service->getRealizationData($filters)->first();

        $this->assertEquals('over_budget', $row->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedPdoWithRealization(int $budget, int $realized): array
    {
        $unit   = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        [, $detail] = $this->seedPdoBase($unit);

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => $budget]);

        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => $realized,
        ]);

        return [$detail->pdo_header, $detail];
    }

    private function seedPdoBase(?PlantationUnit $unit = null): array
    {
        $unit       ??= $this->unit;
        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create(['role_id' => $keraniRole->id, 'plantation_unit_id' => $unit->id]);

        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => $this->month,
            'period_year'        => $this->year,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 1_000_000,
        ]);

        return [$pdo, $detail];
    }
}
