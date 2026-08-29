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
use App\Models\UnitOpeningBalance;
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

    /** Kolom notes item (untuk item PDOT, ini sudah berisi teks Justifikasi). */
    public function test_item_row_includes_pdo_detail_notes(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $pdo  = $this->makeFinalPdo();
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 500_000,
            'notes'           => 'Justifikasi: kebutuhan mendesak proyek replanting.',
        ]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 500_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 500_000]);

        $result = $this->query();

        $foundItem = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertEquals('Justifikasi: kebutuhan mendesak proyek replanting.', $foundItem['notes']);
    }

    public function test_item_row_notes_is_null_when_pdo_detail_has_no_notes(): void
    {
        $this->seedItem(amount: 500_000, transfer: 500_000, realized: 500_000);

        $result = $this->query();

        $foundItem = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertNull($foundItem['notes']);
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
     * Kasus Binanga Juli 2026 — kredit potongan boleh diserap SUB-KATEGORI TETANGGA
     * dalam KATEGORI yang sama.
     *
     * Kerani memperkirakan beban pekerjaan di muka dan perkiraan itu bisa meleset,
     * sehingga panjar satu sub-kategori melebihi biaya riilnya (Binanga: panjar
     * TANAMAN MENGHASILKAN 600.000 tapi upah babat gawangan cuma 455.000). Kalau
     * kreditnya ditahan di sub-kategori itu saja, sisa 145.000 tidak pernah terpakai
     * dan saldo kantong tampil MINUS padahal tidak ada kas yang benar-benar negatif.
     *
     * Pekerja yang sama umumnya juga mengerjakan sub-kategori lain di kategori yang
     * sama, jadi kelebihan panjar wajar diserap sub-kategori tetangga.
     *
     * Skop ini sengaja identik dengan CashBookQueryService::buildExpenseRows() dan
     * RealizationEntryService::totalRealizedForGroup() supaya Rekap Buku Kas, Buku Kas
     * Harian, dan "Sisa Dana" selalu menampilkan angka yang sama.
     */
    public function test_deduction_credit_spills_into_sibling_subcategory_of_same_category(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);

        // Sub-kategori A: potongan 600.000 tapi realisasi hanya 455.000 (kurang 145.000).
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

        // Sub-kategori B (KATEGORI SAMA): surplus realisasi, menyerap sisa kredit sub A.
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
        //
        // realisasi di-clamp PER KATEGORI (bukan per sub-kategori):
        //   realisasi kategori = 455.000 + 3.600.000 = 4.055.000
        //   potongan kategori  = 1.200.000
        //   efektif            = 4.055.000 − 1.200.000 = 2.855.000
        //
        // Kredit sub A yang kelebihan (145.000) diserap surplus sub B — itulah yang
        // membuat saldo tidak lagi menggantung minus karena kredit tertahan.
        $this->assertEquals(2_255_000, $result['transfer_pribadi']);
        $this->assertEquals(2_855_000, $result['realisasi_pribadi']);
        $this->assertEquals(-600_000, $result['saldo_pribadi']);
    }

    /**
     * Kredit potongan TIDAK boleh melewati batas KATEGORI. Kategori yang sama sekali
     * tidak punya panjar tidak boleh ikut terpotong hanya karena kategori lain punya —
     * itu akan mengecilkan realisasi item yang tidak punya uang muka sama sekali
     * (perilaku PDO-wide lama yang sudah dibuang).
     */
    public function test_deduction_credit_does_not_spill_across_categories(): void
    {
        $pdo = $this->makeFinalPdo();

        // Kategori A: panjar 1.000.000, belum ada realisasi sama sekali.
        $catA = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $subA = ExpenseSubcategory::factory()->create(['category_id' => $catA->id]);
        $this->seedDeduction($pdo, $subA, 1_000_000, 'rek_kebun');

        // Kategori B: tidak punya panjar, realisasi 300.000 — harus tetap utuh.
        $catB  = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $subB  = ExpenseSubcategory::factory()->create(['category_id' => $catB->id]);
        $itemB = ExpenseItem::factory()->create(['subcategory_id' => $subB->id]);
        $detB  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemB->id, 'amount' => 500_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detB->id, 'amount' => 500_000, 'transfer_destination' => 'rek_kebun']);
        RealizationEntry::factory()->create([
            'pdo_detail_id'  => $detB->id,
            'amount'         => 300_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        $result = $this->query();

        // transfer  = 500.000 − 1.000.000 = −500.000
        // realisasi = 0 (kategori A, kredit tertahan) + 300.000 (kategori B, utuh)
        $this->assertEquals(-500_000, $result['transfer_kebun']);
        $this->assertEquals(300_000, $result['realisasi_kebun']);
    }

    /**
     * Kalau realisasi SATU KATEGORI pun masih lebih kecil dari panjarnya, kredit
     * di-clamp sebesar realisasi kategori itu — realisasi efektif kategori jadi 0,
     * TIDAK pernah negatif. Sisa kreditnya tertahan sampai realisasinya masuk.
     *
     * Konsekuensi yang disengaja: selama kerani belum selesai mencatat realisasi
     * sub-kategori yang punya panjar, realisasi sub-kategori TETANGGA di kategori yang
     * sama ikut terserap (di sini 300.000 habis dipakai menutup panjar 1.000.000).
     * Ini self-correcting — begitu realisasi sub berpanjar masuk, angkanya normal
     * kembali. Batasnya tetap kategori, tidak pernah PDO-wide (lihat
     * test_deduction_credit_does_not_spill_across_categories).
     */
    public function test_credit_clamped_at_category_total_when_category_realisasi_insufficient(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);

        // Sub-kategori A: PUNYA panjar 1.000.000, tapi belum ada realisasi sama sekali.
        $subA = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $this->seedDeduction($pdo, $subA, 1_000_000, 'rek_kebun');

        // Sub-kategori B (KATEGORI SAMA): tidak punya panjar, realisasi 300.000.
        $subB  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemB = ExpenseItem::factory()->create(['subcategory_id' => $subB->id]);
        $detB  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemB->id, 'amount' => 500_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detB->id, 'amount' => 500_000, 'transfer_destination' => 'rek_kebun']);
        RealizationEntry::factory()->create([
            'pdo_detail_id'  => $detB->id,
            'amount'         => 300_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        $result = $this->query();

        // transfer  = 500.000 − 1.000.000 = −500.000
        // realisasi = max(0, 300.000 − 1.000.000) = 0  (kredit terpakai 300.000,
        //             sisa 700.000 tertahan sampai realisasi sub A masuk)
        $this->assertEquals(-500_000, $result['transfer_kebun']);
        $this->assertEquals(0, $result['realisasi_kebun']);
    }

    /**
     * TransferEntry auto-generated untuk PDOT funding_option=kas_kebun BUKAN uang baru
     * masuk — dikecualikan dari KPI transfer_kebun DAN dari kolom Transfer baris tabel.
     *
     * Baris WAJIB ikut dinolkan (dulu menampilkan nilai aslinya "sebagai informasi"):
     * kalau tidak, jumlah baris tabel tidak akan pernah sama dengan KPI-nya sendiri.
     * Kasus nyata KP Agustus 2026: tabel 215.400.891 vs KPI 215.275.891, selisih persis
     * 125.000 dari satu item PDOT kas kebun.
     *
     * Saldo memakai rumus normal (Transfer − Realisasi) sehingga MINUS setelah item
     * direalisasi — disengaja, angka minus itu sinyal bahwa realisasinya didanai dari
     * luar transfer item itu sendiri.
     */
    public function test_kas_kebun_auto_entry_excluded_from_both_kpi_and_row_transfer(): void
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

        // Baris tabel per-item IKUT dinolkan supaya bisa dijumlahkan sampai ketemu KPI.
        $row = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertEquals(0, $row['total_transfer']);
        $this->assertEquals(1_200_000, $row['amount']); // pengajuan tetap tampil apa adanya
        $this->assertEquals(0, $result['grand_total_transfer']);

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

        // Saldo baris ikut minus — sinyal bahwa realisasi item ini didanai dari luar
        // transfer item itu sendiri, bukan bug.
        $row = $result['categories'][0]['subcategories'][0]['items'][0];
        $this->assertEquals(0, $row['total_transfer']);
        $this->assertEquals(1_200_000, $row['total_realization']);
        $this->assertEquals(-1_200_000, $row['saldo']);
    }

    /**
     * Item PDOT kas kebun yang BELUM direalisasi tetap harus muncul di tabel walau
     * Transfer & Realisasi sama-sama 0 — filter "sembunyikan baris kosong" pada mode
     * kantong tidak boleh menelannya, karena transfer 0 memang by design.
     */
    public function test_kas_kebun_row_still_listed_when_not_yet_realized_in_kebun_filter(): void
    {
        $pdo = $this->makeFinalPdo();
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId, 'include_in_recap' => true]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 125_000,
            'funding_option'  => 'kas_kebun',
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'         => $detail->id,
            'amount'                => 125_000,
            'transfer_destination'  => 'rek_kebun',
            'entry_source'          => 'system',
            'is_auto_generated'     => true,
        ]);

        $result = $this->service->getRecapData([
            'period_year'  => $this->year,
            'period_month' => $this->month,
            'unit_id'      => $this->unit->id,
            'category_id'  => null,
            'kantong'      => 'kebun',
        ]);

        $rows = $result['categories'][0]['subcategories'][0]['items'];
        $this->assertCount(1, $rows);
        $this->assertEquals(125_000, $rows[0]['amount']);
        $this->assertEquals(0, $rows[0]['total_transfer']);
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

    // ── Saldo awal: KPI "Saldo PDO" vs KPI "Saldo" vs baris Grand Total ───────

    /**
     * Baris Grand Total memakai rumus kantong yang sama dengan KPI "Saldo"
     * (saldo awal + transfer − realisasi) supaya keduanya tidak pernah beda —
     * inilah keluhan aslinya. KPI "Saldo PDO" menyimpan angka murni PDO
     * (transfer − realisasi), yang tetap sama dengan penjumlahan kolom Saldo
     * per item di tabel.
     */
    public function test_grand_total_saldo_includes_opening_balance_while_saldo_pdo_excludes_it(): void
    {
        UnitOpeningBalance::create([
            'plantation_unit_id' => $this->unit->id,
            'amount'             => 2_000_000,
            'as_of_date'         => sprintf('%04d-%02d-01', $this->year, $this->month - 1),
        ]);

        $this->seedItem(amount: 1_000_000, transfer: 900_000, realized: 750_000);

        $result = $this->query();

        $this->assertEquals(2_000_000, $result['saldo_awal']);
        // Murni PDO — sama dengan jumlah kolom Saldo per item.
        $this->assertEquals(150_000, $result['saldo_pdo_kebun']);
        // Posisi kas — KPI "Saldo" dan baris Grand Total wajib sama.
        $this->assertEquals(2_150_000, $result['saldo_kebun']);
        $this->assertEquals(2_150_000, $result['grand_total_saldo']);
        $this->assertEquals($result['saldo_kebun'], $result['grand_total_saldo']);
    }

    /** Filter kantong Pribadi/Vendor tidak memuat transaksi kas kebun, jadi saldo awal tidak boleh ikut. */
    public function test_grand_total_saldo_excludes_opening_balance_on_pribadi_kantong(): void
    {
        UnitOpeningBalance::create([
            'plantation_unit_id' => $this->unit->id,
            'amount'             => 2_000_000,
            'as_of_date'         => sprintf('%04d-%02d-01', $this->year, $this->month - 1),
        ]);

        $this->seedItem(amount: 1_000_000, transfer: 900_000, realized: 750_000);

        $result = $this->service->getRecapData([
            'period_year'  => $this->year,
            'period_month' => $this->month,
            'unit_id'      => $this->unit->id,
            'category_id'  => null,
            'kantong'      => 'pribadi',
        ]);

        $this->assertEquals(0, $result['grand_total_saldo']);
    }

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
