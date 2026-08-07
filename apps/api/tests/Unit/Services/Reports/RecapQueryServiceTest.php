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

    // ── 9: duplicate-amount entries must not be undercounted ──────────────────

    public function test_total_realization_sums_multiple_entries_with_same_amount(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
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

        // Three realization entries with the SAME amount — must all be counted, not
        // collapsed by SUM(DISTINCT). Two transfer entries with different amounts too.
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 120_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 120_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 120_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 400_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 400_000]);

        $result = $this->query();
        $item   = $result['categories'][0]['subcategories'][0]['items'][0];

        $this->assertEquals(360_000, $item['total_realization']);
        $this->assertEquals(800_000, $item['total_transfer']);
    }

    // ── 10: KPI saldo_kebun tidak boleh terinflasi oleh potongan yang belum ──
    //         punya realisasi asli (bug: potongan dianggap "sudah direalisasi"
    //         di KPI, padahal itu hanya untuk plafon validasi/tampilan baris).

    public function test_kpi_saldo_kebun_not_inflated_by_unrealized_deduction(): void
    {
        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => $this->month,
            'period_year'        => $this->year,
        ]);

        // Item biasa: transfer 8.894.864, belum ada realisasi.
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 8_894_864]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 8_894_864, 'transfer_destination' => 'rek_kebun']);

        // Item potongan: transfer -4.500.000 (mengurangi total transfer), tanpa realisasi.
        $dedItem = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 4_500_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -4_500_000, 'transfer_destination' => 'rek_kebun',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $result = $this->query();

        $this->assertEquals(4_394_864, $result['transfer_kebun']);
        $this->assertEquals(0, $result['realisasi_kebun']);
        $this->assertEquals(4_394_864, $result['saldo_kebun']);
    }

    /**
     * Regresi PDO Juli: begitu realisasi penyeimbangnya tercatat, potongan HARUS
     * dinetkan penuh. Pernah rusak karena netting dihapus total demi memperbaiki
     * kasus "belum ada realisasi" di atas — akibatnya saldo Juli KP jadi
     * −7.026.778 dari seharusnya 4.073.222, SS −4.499.827 dari seharusnya 173.
     */
    public function test_kpi_saldo_kebun_nets_deduction_fully_once_realized(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        // Item biasa: transfer 4.000.000, kerani realisasi PENUH 5.000.000
        // (1.000.000-nya dibayar dari panjar bulan lalu).
        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 4_000_000, 'transfer_destination' => 'rek_kebun']);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 5_000_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        $this->seedDeduction($pdo, $sub, 1_000_000, 'rek_kebun');

        $result = $this->query();

        // transfer = 4.000.000 − 1.000.000 = 3.000.000
        // realisasi efektif = 5.000.000 − 1.000.000 = 4.000.000
        $this->assertEquals(3_000_000, $result['transfer_kebun']);
        $this->assertEquals(4_000_000, $result['realisasi_kebun']);
        $this->assertEquals(-1_000_000, $result['saldo_kebun']);
    }

    /**
     * Kasus Binanga: dalam SATU kantong (pribadi/vendor) ada dua sub-kategori —
     * yang satu realisasinya lebih kecil dari potongannya, yang satu surplus.
     * Clamp harus di level KANTONG, bukan per sub-kategori; kalau per sub-kategori
     * saldo jadi −145.000 alih-alih 0.
     */
    public function test_deduction_clamped_per_pocket_not_per_subcategory(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);

        // Sub-kategori A: potongan 600.000 tapi realisasi hanya 455.000 (kurang).
        $subA  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemA = ExpenseItem::factory()->create(['subcategory_id' => $subA->id]);
        $detA  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemA->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detA->id, 'amount' => 455_000, 'transfer_destination' => 'pribadi']);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detA->id, 'amount' => 455_000,
            'funding_source' => RealizationEntry::FUNDING_REKENING_UTAMA,
            'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
        ]);
        $this->seedDeduction($pdo, $subA, 600_000, 'pribadi');

        // Sub-kategori B: surplus realisasi, cukup menutup sisa kredit sub-kategori A.
        $subB  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemB = ExpenseItem::factory()->create(['subcategory_id' => $subB->id]);
        $detB  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemB->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detB->id, 'amount' => 3_000_000, 'transfer_destination' => 'vendor']);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detB->id, 'amount' => 3_600_000,
            'funding_source' => RealizationEntry::FUNDING_REKENING_UTAMA,
            'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
        ]);
        $this->seedDeduction($pdo, $subB, 600_000, 'vendor');

        $result = $this->query();

        // transfer  = 455.000 + 3.000.000 − 1.200.000 = 2.255.000
        // realisasi = 4.055.000 − 1.200.000 (kredit penuh, pool cukup) = 2.855.000
        $this->assertEquals(2_255_000, $result['transfer_pribadi']);
        $this->assertEquals(2_855_000, $result['realisasi_pribadi']);
        $this->assertEquals(-600_000, $result['saldo_pribadi']);
    }

    /**
     * TransferEntry auto-generated untuk PDOT funding_option=kas_kebun BUKAN
     * uang baru masuk — tidak boleh menaikkan KPI transfer_kebun/saldo_kebun.
     * Baris tabel per-item TETAP menampilkan nilai aslinya (tidak di-nol-kan).
     * Setelah item ini direalisasikan KERANI, KPI saldo tetap harus turun
     * sesuai realisasi (rawRealisasiKebun tidak ikut di-skip).
     */
    public function test_kpi_transfer_kebun_excludes_kas_kebun_auto_entry_but_row_and_realization_unaffected(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 1_200_000,
            'funding_option'  => 'kas_kebun',
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'         => $detail->id,
            'amount'                => 1_200_000,
            'transfer_destination'  => 'rek_kebun',
            'entry_source'          => 'system',
            'is_auto_generated'     => true,
        ]);

        $result = $this->query();

        // KPI level PDO tidak boleh naik akibat entri kas_kebun.
        $this->assertEquals(0, $result['transfer_kebun']);

        // Baris tabel per-item tetap menampilkan nilai aslinya (informatif).
        $row = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertEquals(1_200_000, $row['total_transfer']);

        // Setelah direalisasikan KERANI, KPI saldo tetap turun sesuai realisasi.
        RealizationEntry::factory()->create([
            'pdo_detail_id'  => $detail->id,
            'amount'         => 1_200_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        $result = $this->query();
        $this->assertEquals(0, $result['transfer_kebun']);
        $this->assertEquals(1_200_000, $result['realisasi_kebun']);
        $this->assertEquals(-1_200_000, $result['saldo_kebun']);
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

    private function makeFinalPdo(): PdoHeader
    {
        $keraniRole = Role::firstOrCreate(['code' => Role::KERANI], ['name' => 'Kerani']);
        $kerani     = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        return PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => $this->month,
            'period_year'        => $this->year,
        ]);
    }

    /** Item potongan: TransferEntry negatif, tidak pernah punya RealizationEntry. */
    private function seedDeduction(PdoHeader $pdo, ExpenseSubcategory $sub, int $amount, string $destination): void
    {
        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_deduction' => true]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => $amount,
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => -$amount,
            'transfer_destination' => $destination,
            'entry_source'         => 'system',
            'is_auto_generated'    => true,
        ]);
    }

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
