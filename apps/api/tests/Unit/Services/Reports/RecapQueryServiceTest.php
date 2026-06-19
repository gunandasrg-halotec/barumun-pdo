<?php

namespace Tests\Unit\Services\Reports;

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
use App\Services\Report\RecapQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecapQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecapQueryService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private int $year  = 2026;
    private int $month = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new RecapQueryService();
        $company         = Company::factory()->create();
        $this->companyId = $company->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
    }

    // ── 1: hierarkis structure ────────────────────────────────────────────────

    public function test_recap_returns_hierarchical_structure_with_categories_subcategories_items(): void
    {
        $this->seedItem(amount: 1_000_000, transfer: 900_000, realized: 800_000);

        $result = $this->query();

        $this->assertArrayHasKey('categories', $result);
        $this->assertNotEmpty($result['categories']);

        $cat = $result['categories'][0];
        $this->assertArrayHasKey('subcategories', $cat);
        $this->assertNotEmpty($cat['subcategories']);

        $sub = $cat['subcategories'][0];
        $this->assertArrayHasKey('items', $sub);
        $this->assertNotEmpty($sub['items']);
    }

    // ── 2: subtotal category = sum of subcategory subtotals ──────────────────

    public function test_subtotal_category_equals_sum_of_subcategory_subtotals(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);

        // Two sub-categories under the same category
        $sub1 = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $sub2 = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        $item1 = ExpenseItem::factory()->create(['subcategory_id' => $sub1->id]);
        $item2 = ExpenseItem::factory()->create(['subcategory_id' => $sub2->id]);

        $this->seedDetailWithEntries($item1, 1_000_000, 800_000, 700_000);
        $this->seedDetailWithEntries($item2, 500_000,   400_000, 300_000);

        $result = $this->query();

        $catRow   = $result['categories'][0];
        $subTotal = array_sum(array_column($catRow['subcategories'], 'subtotal_amount'));

        $this->assertEquals($subTotal, $catRow['subtotal_amount']);
    }

    // ── 3: subtotal subcategory = sum of item amounts ─────────────────────────

    public function test_subtotal_subcategory_equals_sum_of_item_amounts(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        $item1 = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $item2 = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $this->seedDetailWithEntries($item1, 1_000_000, 800_000, 700_000);
        $this->seedDetailWithEntries($item2, 500_000,   400_000, 300_000);

        $result = $this->query();

        $subRow   = $result['categories'][0]['subcategories'][0];
        $itemsSum = array_sum(array_column($subRow['items'], 'amount'));

        $this->assertEquals($itemsSum, $subRow['subtotal_amount']);
    }

    // ── 4: grand total = sum of category subtotals ───────────────────────────

    public function test_grand_total_equals_sum_of_category_subtotals(): void
    {
        $this->seedItem(amount: 1_000_000, transfer: 900_000, realized: 800_000);
        $this->seedItem(amount: 500_000,   transfer: 400_000, realized: 350_000);

        $result   = $this->query();
        $catTotal = array_sum(array_column($result['categories'], 'subtotal_amount'));

        $this->assertEquals($catTotal, $result['grand_total_amount']);
    }

    // ── 5: saldo = transfer - realization ────────────────────────────────────

    public function test_saldo_equals_transfer_minus_realization(): void
    {
        $this->seedItem(amount: 1_000_000, transfer: 900_000, realized: 750_000);

        $result = $this->query();

        $item  = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertEquals(900_000 - 750_000, $item['saldo']);

        $sub = $result['categories'][0]['subcategories'][0];
        $this->assertEquals(900_000 - 750_000, $sub['subtotal_saldo']);

        $cat = $result['categories'][0];
        $this->assertEquals(900_000 - 750_000, $cat['subtotal_saldo']);

        $this->assertEquals(900_000 - 750_000, $result['grand_total_saldo']);
    }

    // ── 6: empty result for different period ─────────────────────────────────

    public function test_empty_result_when_no_pdo_for_period(): void
    {
        $this->seedItem(amount: 1_000_000, transfer: 800_000, realized: 700_000);

        $result = $this->service->getRecapData([
            'period_year'  => 2025,
            'period_month' => 1,
            'unit_id'      => $this->unit->id,
            'category_id'  => null,
        ]);

        $this->assertEmpty($result['categories']);
        $this->assertEquals(0, $result['grand_total_amount']);
    }

    // ── 7: filter by category_id ─────────────────────────────────────────────

    public function test_filter_by_category_id_returns_only_that_category(): void
    {
        $cat1 = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $cat2 = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);

        $this->seedItemForCategory($cat1, 1_000_000, 800_000, 700_000);
        $this->seedItemForCategory($cat2, 500_000,   400_000, 300_000);

        $result = $this->service->getRecapData([
            'period_year'  => $this->year,
            'period_month' => $this->month,
            'unit_id'      => $this->unit->id,
            'category_id'  => $cat1->id,
        ]);

        $this->assertCount(1, $result['categories']);
        $this->assertEquals($cat1->code, $result['categories'][0]['category_code']);
    }

    // ── 8: kerani unit is enforced by controller (tested via feature) ─────────

    public function test_kerani_unit_filter_enforced_regardless_of_request_param(): void
    {
        // This is a service-level test: the service itself accepts whatever unit_id is given.
        // Row-level enforcement is in RecapController. Here we verify that querying
        // a different unit returns empty when the seeded data is on $this->unit.
        $otherUnit = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        $this->seedItem(amount: 1_000_000, transfer: 800_000, realized: 700_000);

        $result = $this->service->getRecapData([
            'period_year'  => $this->year,
            'period_month' => $this->month,
            'unit_id'      => $otherUnit->id,
            'category_id'  => null,
        ]);

        $this->assertEmpty($result['categories']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function query(): array
    {
        return $this->service->getRecapData([
            'period_year'  => $this->year,
            'period_month' => $this->month,
            'unit_id'      => $this->unit->id,
            'category_id'  => null,
        ]);
    }

    private function seedItem(int $amount, int $transfer, int $realized): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $this->seedDetailWithEntries($item, $amount, $transfer, $realized);
    }

    private function seedItemForCategory($cat, int $amount, int $transfer, int $realized): void
    {
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $this->seedDetailWithEntries($item, $amount, $transfer, $realized);
    }

    /** @var array<string, \App\Models\PdoHeader> */
    private array $pdoCache = [];

    private function seedDetailWithEntries($item, int $amount, int $transfer, int $realized, ?PlantationUnit $unit = null): void
    {
        $unit       ??= $this->unit;
        $cacheKey   = "{$unit->id}_{$this->year}_{$this->month}";

        if (!isset($this->pdoCache[$cacheKey])) {
            $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
            $kerani     = User::factory()->create([
                'role_id'            => $keraniRole->id,
                'plantation_unit_id' => $unit->id,
            ]);

            $this->pdoCache[$cacheKey] = PdoHeader::factory()->create([
                'company_id'         => $this->companyId,
                'plantation_unit_id' => $unit->id,
                'created_by'         => $kerani->id,
                'status'             => PdoHeader::STATUS_FINAL,
                'period_month'       => $this->month,
                'period_year'        => $this->year,
            ]);
        }

        $pdo    = $this->pdoCache[$cacheKey];
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => $amount,
        ]);

        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => $transfer]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => $realized]);
    }
}
