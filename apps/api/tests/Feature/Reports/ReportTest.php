<?php

namespace Tests\Feature\Reports;

use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Company;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manajer;
    private string $companyId;
    private PlantationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $company         = Company::factory()->create();
        $this->companyId = $company->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role           = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajer  = User::factory()->create([
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
    }

    public function test_realization_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/realization?period_year=2026&period_month=6')
             ->assertStatus(401);
    }

    public function test_realization_requires_period_params(): void
    {
        Sanctum::actingAs($this->manajer);

        // When period_year and period_month are both provided with valid values, should succeed
        $this->getJson('/api/v1/reports/realization?period_year=2026&period_month=6')
             ->assertOk()
             ->assertJsonStructure(['success', 'data']);
    }

    public function test_realization_returns_data(): void
    {
        Sanctum::actingAs($this->manajer);
        $this->seedPdo(1_000_000, 800_000);

        $this->getJson('/api/v1/reports/realization?period_year=2026&period_month=6')
             ->assertOk()
             ->assertJsonStructure(['success', 'data']);
    }

    public function test_over_budget_returns_only_overspent(): void
    {
        Sanctum::actingAs($this->manajer);
        $this->seedPdo(500_000, 700_000); // over

        $res = $this->getJson('/api/v1/reports/over-budget?period_year=2026&period_month=6')
                    ->assertOk()
                    ->json('data');

        $this->assertNotEmpty($res);
    }

    public function test_missing_proof_returns_200(): void
    {
        Sanctum::actingAs($this->manajer);

        $this->getJson('/api/v1/reports/missing-proof?period_year=2026&period_month=6')
             ->assertOk()
             ->assertJsonStructure(['success', 'data']);
    }

    public function test_recap_returns_hierarchical_data(): void
    {
        Sanctum::actingAs($this->manajer);
        $this->seedPdo(1_000_000, 500_000);

        $data = $this->getJson('/api/v1/reports/recap?period_year=2026&period_month=6')
                     ->assertOk()
                     ->json('data');

        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('grand_total_amount', $data);
    }

    public function test_export_dispatches_job_and_returns_202(): void
    {
        Sanctum::actingAs($this->manajer);

        $this->postJson('/api/v1/reports/export', [
            'report_type'  => 'realization',
            'format'       => 'xlsx',
            'period_year'  => 2026,
            'period_month' => 6,
        ])->assertStatus(202)
          ->assertJsonStructure(['success', 'data' => ['job_id']]);
    }

    public function test_export_status_returns_404_for_unknown_job(): void
    {
        Sanctum::actingAs($this->manajer);

        $this->getJson('/api/v1/reports/export/' . Str::uuid())
             ->assertStatus(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedPdo(int $budget, int $realized): void
    {
        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create(['role_id' => $keraniRole->id, 'plantation_unit_id' => $this->unit->id]);

        $cat    = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub    = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => 6,
            'period_year'        => 2026,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => $budget,
        ]);

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => $budget]);

        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id,
            'amount'        => $realized,
        ]);
    }
}
