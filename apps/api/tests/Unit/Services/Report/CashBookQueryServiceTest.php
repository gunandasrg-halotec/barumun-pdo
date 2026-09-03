<?php

namespace Tests\Unit\Services\Report;

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
use App\Services\Report\CashBookQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBookQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashBookQueryService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private User $kerani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new CashBookQueryService();
        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role         = Role::factory()->create(['code' => Role::KERANI]);
        $this->kerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
    }

    /**
     * Saldo kumulatif (Saldo Awal, validasi PDOT Kas Kebun, KPI Dashboard) harus
     * memakai skop netting yang sama dengan tabel Buku Kas Harian: per KATEGORI.
     * Dengan skop PDO-wide yang lama, kredit panjar kategori B ikut memakan
     * realisasi kategori A yang tidak punya panjar sama sekali, sehingga
     * closingBalanceForPeriod() berbeda dari saldo akhir Buku Kas Harian.
     */
    public function test_cumulative_balance_nets_deduction_per_category_and_matches_cash_book(): void
    {
        $subA = ExpenseSubcategory::factory()->create(['category_id' => ExpenseCategory::factory()->create(['company_id' => $this->companyId])->id]);
        $subB = ExpenseSubcategory::factory()->create(['category_id' => ExpenseCategory::factory()->create(['company_id' => $this->companyId])->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Kategori A: transfer 5jt, realisasi 5jt, tanpa panjar.
        $itemA   = ExpenseItem::factory()->create(['subcategory_id' => $subA->id]);
        $detailA = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemA->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detailA->id, 'amount' => 5_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detailA->id, 'amount' => 5_000_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-10',
        ]);

        // Kategori B: panjar 1jt, belum ada realisasi apa pun.
        $dedItemB   = ExpenseItem::factory()->create(['subcategory_id' => $subB->id, 'is_deduction' => true]);
        $dedDetailB = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItemB->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetailB->id, 'amount' => -1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        // Penerimaan 5jt − 1jt = 4jt. Pengeluaran efektif: kategori A 5jt,
        // kategori B 0 (kredit panjar tertahan) ⇒ saldo akhir −1.000.000.
        // Skop PDO-wide lama menghasilkan 0 karena panjar B memakan realisasi A.
        $closing  = $this->service->closingBalanceForPeriod($this->unit->id, 2026, 8);
        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id, 'kantong' => 'kebun',
        ]);

        $this->assertEquals(-1_000_000, $closing);
        $this->assertEquals($cashBook['closing_balance'], $closing);
    }

    /** Sama seperti kasus nyata PDO Agustus Sosa: potongan belum direalisasikan. */
    public function test_closing_balance_not_inflated_by_unrealized_deduction(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 8_894_864]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 8_894_864,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);

        $dedItem   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 4_500_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -4_500_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        // Belum ada RealizationEntry sama sekali.
        $closing = $this->service->closingBalanceForPeriod($this->unit->id, 2026, 8);

        $this->assertEquals(4_394_864, $closing);
    }

    /** Setelah realisasi penuh yang mengkompensasi potongan tercatat, saldo harus tetap akurat. */
    public function test_closing_balance_correct_once_compensating_realization_recorded(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Item UPAH PANEN: anggaran 5.000.000, tapi 1.000.000 sudah dipanjar bulan lalu
        // (potongan), jadi transfer baru yang diterima hanya 4.000.000.
        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 4_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);

        $dedItem   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        // Kerani mencatat realisasi PENUH sesuai anggaran (5.000.000) — termasuk
        // bagian yang sudah dipanjar bulan lalu.
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 5_000_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $closing = $this->service->closingBalanceForPeriod($this->unit->id, 2026, 8);

        // 4.000.000 diterima - 5.000.000 direalisasikan = -1.000.000, tapi itu
        // memang benar: 1.000.000 dari realisasi ini dibayar dari kas bulan lalu
        // (panjar), bukan kas bulan ini. Saldo kas kebun bulan ini turun bersih
        // sebesar (4.000.000 diterima - 4.000.000 benar-benar dibelanjakan bulan
        // ini) = 0, DITAMBAH efek panjar yang sudah tercermin di saldo bulan lalu.
        $this->assertEquals(-1_000_000, $closing);
    }

    /**
     * Regresi: sub-kategori dipakai ulang tiap bulan. Realisasi PDO BULAN LALU
     * di sub-kategori yang sama TIDAK BOLEH dianggap mengkompensasi potongan
     * PDO bulan ini — potongan itu harus tetap murni mengurangi $totalReceipts,
     * tanpa kredit balik, karena belum ada realisasi APAPUN untuk PDO ini.
     */
    public function test_deduction_not_offset_by_unrelated_realization_from_prior_pdo_same_subcategory(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);

        // PDO Juli: realisasi normal di sub-kategori yang sama, tidak terkait potongan.
        $julyPdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $this->kerani->id, 'status' => PdoHeader::STATUS_FINAL,
            'period_year' => 2026, 'period_month' => 7,
        ]);
        $julyDetail = PdoDetail::factory()->create(['pdo_header_id' => $julyPdo->id, 'expense_item_id' => $item->id, 'amount' => 2_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $julyDetail->id, 'amount' => 2_000_000, 'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-07-05']);
        RealizationEntry::factory()->create(['pdo_detail_id' => $julyDetail->id, 'amount' => 2_000_000, 'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-07-10']);

        // PDO Agustus: item biasa + potongan di sub-kategori YANG SAMA, belum ada realisasi.
        $augPdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $this->kerani->id, 'status' => PdoHeader::STATUS_FINAL,
            'period_year' => 2026, 'period_month' => 8,
        ]);
        $augDetail = PdoDetail::factory()->create(['pdo_header_id' => $augPdo->id, 'expense_item_id' => $item->id, 'amount' => 3_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $augDetail->id, 'amount' => 3_000_000, 'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01']);

        $dedItem   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $augPdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $closingAugust = $this->service->closingBalanceForPeriod($this->unit->id, 2026, 8);

        // Juli: 2.000.000 masuk - 2.000.000 keluar = 0. Agustus: (3.000.000 - 1.000.000)
        // masuk - 0 keluar (belum ada realisasi Agustus) = 2.000.000. Total = 2.000.000.
        $this->assertEquals(2_000_000, $closingAugust);
    }

    public function test_opening_balance_includes_unit_seed(): void
    {
        \App\Models\UnitOpeningBalance::create([
            'plantation_unit_id' => $this->unit->id,
            'amount'             => 10_000_000,
            'as_of_date'         => '2026-06-30',
        ]);

        $opening = $this->service->openingBalanceForPeriod($this->unit->id, 2026, 7);

        $this->assertEquals(10_000_000, $opening);
    }

    /**
     * TransferEntry auto-generated untuk PDOT funding_option=kas_kebun BUKAN
     * uang baru masuk — tidak boleh menaikkan saldo/penerimaan Buku Kas Kebun.
     * Realisasi terhadap item ini tetap harus mengurangi saldo seperti biasa.
     */
    public function test_kas_kebun_auto_transfer_entry_excluded_from_receipts_and_balance(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

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
            'transfer_date'         => '2026-08-01',
            'entry_source'          => 'system',
            'is_auto_generated'     => true,
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);
        $this->assertEquals(0, $cashBook['total_penerimaan']);
        $this->assertEquals(0, $cashBook['closing_balance']);

        // Realisasi terhadap item kas_kebun ini HARUS tetap mengurangi saldo —
        // dana benar-benar keluar dari kas kebun walau tidak pernah "masuk" di sini.
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 1_200_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $closing = $this->service->closingBalanceForPeriod($this->unit->id, 2026, 8);
        $this->assertEquals(-1_200_000, $closing);
    }

    /**
     * Baris pengeluaran Buku Kas Harian menyertakan pdo_details.notes secara
     * terpisah dari description (untuk item asal PDOT, ini sudah berisi teks
     * Justifikasi — lihat PdoSupplementaryApprovalService::mergeIntoParent()).
     */
    public function test_expense_row_includes_pdo_detail_notes(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 500_000,
            'notes'           => 'Perlu dibeli mendesak untuk proyek replanting.',
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 500_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 500_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenseRow = collect($cashBook['rows'])->firstWhere('type', 'pengeluaran');

        $this->assertNotNull($expenseRow);
        $this->assertEquals('Perlu dibeli mendesak untuk proyek replanting.', $expenseRow['notes']);
    }

    /** Item tanpa notes harus menghasilkan null, bukan string kosong. */
    public function test_expense_row_notes_is_null_when_pdo_detail_has_no_notes(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 300_000,
            'notes'           => null,
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 300_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 300_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenseRow = collect($cashBook['rows'])->firstWhere('type', 'pengeluaran');

        $this->assertNotNull($expenseRow);
        $this->assertNull($expenseRow['notes']);
    }

    /**
     * §3g keterlacakan: baris pengeluaran yang menggabungkan 2 entri realisasi dari
     * DUA voucher berbeda (subkategori & tanggal sama, jadi masuk 1 baris yang sama)
     * menghasilkan field 'vouchers' berisi 2 entri unik {id, voucher_number}.
     */
    public function test_expense_row_includes_unique_vouchers_from_multiple_petty_cash_vouchers(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);

        $entry1 = RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 200_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);
        $entry2 = RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 300_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $voucher1 = \App\Models\PettyCashVoucher::factory()->posted()->create([
            'pdo_header_id' => $pdo->id, 'voucher_number' => 'PCV/TEST/1',
        ]);
        \App\Models\PettyCashVoucherLine::factory()->create([
            'petty_cash_voucher_id' => $voucher1->id,
            'pdo_detail_id'         => $detail->id,
            'realization_entry_id'  => $entry1->id,
        ]);

        $voucher2 = \App\Models\PettyCashVoucher::factory()->posted()->create([
            'pdo_header_id' => $pdo->id, 'voucher_number' => 'PCV/TEST/2',
        ]);
        \App\Models\PettyCashVoucherLine::factory()->create([
            'petty_cash_voucher_id' => $voucher2->id,
            'pdo_detail_id'         => $detail->id,
            'realization_entry_id'  => $entry2->id,
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenseRow = collect($cashBook['rows'])->firstWhere('type', 'pengeluaran');

        $this->assertNotNull($expenseRow);
        $this->assertNotNull($expenseRow['vouchers']);
        $this->assertCount(2, $expenseRow['vouchers']);
        $this->assertEqualsCanonicalizing(
            ['PCV/TEST/1', 'PCV/TEST/2'],
            array_column($expenseRow['vouchers'], 'voucher_number')
        );
    }

    /** Baris tanpa entri dari voucher manapun harus menghasilkan vouchers: null. */
    public function test_expense_row_vouchers_is_null_when_no_petty_cash_voucher(): void
    {
        $cat  = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        $item   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 400_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 400_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 400_000,
            'funding_source'   => RealizationEntry::FUNDING_REKENING_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenseRow = collect($cashBook['rows'])->firstWhere('type', 'pengeluaran');

        $this->assertNotNull($expenseRow);
        $this->assertNull($expenseRow['vouchers']);
    }

    /**
     * Filter kantong untuk HO (role tanpa unit kebun): 'kebun' → hanya transaksi
     * rek_kebun/kas_kebun (default, perilaku existing); 'pribadi' → hanya transaksi
     * pribadi/vendor; 'all' → gabungan keduanya. Ketiganya harus menjumlah secara
     * matematis konsisten (all = kebun + pribadi).
     */
    public function test_kantong_filter_scopes_receipts_and_expenses(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Item kantong kebun: transfer 3.000.000, realisasi 1.500.000.
        $itemKebun   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detailKebun = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemKebun->id, 'amount' => 3_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detailKebun->id, 'amount' => 3_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detailKebun->id,
            'amount'           => 1_500_000,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'transaction_date' => '2026-08-05',
        ]);

        // Item kantong pribadi/vendor: transfer langsung 2.000.000, realisasi 800.000.
        $itemPribadi   = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
        $detailPribadi = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemPribadi->id, 'amount' => 2_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detailPribadi->id, 'amount' => 2_000_000,
            'transfer_destination' => 'vendor', 'transfer_date' => '2026-08-02',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detailPribadi->id,
            'amount'           => 800_000,
            'funding_source'   => RealizationEntry::FUNDING_REKENING_UTAMA,
            'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
            'transaction_date' => '2026-08-06',
        ]);

        $filters = ['period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id];

        $kebun   = $this->service->getCashBookData([...$filters, 'kantong' => 'kebun']);
        $pribadi = $this->service->getCashBookData([...$filters, 'kantong' => 'pribadi']);
        $all     = $this->service->getCashBookData([...$filters, 'kantong' => 'all']);

        $this->assertEquals(3_000_000, $kebun['total_penerimaan']);
        $this->assertEquals(1_500_000, $kebun['total_pengeluaran']);

        $this->assertEquals(2_000_000, $pribadi['total_penerimaan']);
        $this->assertEquals(800_000, $pribadi['total_pengeluaran']);

        $this->assertEquals(5_000_000, $all['total_penerimaan']);
        $this->assertEquals(2_300_000, $all['total_pengeluaran']);

        // Tanpa kantong sama sekali (default) harus identik dengan eksplisit 'kebun' —
        // menjaga kompatibilitas mundur untuk caller lama yang belum kirim param ini.
        $default = $this->service->getCashBookData($filters);
        $this->assertEquals($kebun['total_penerimaan'], $default['total_penerimaan']);
        $this->assertEquals($kebun['total_pengeluaran'], $default['total_pengeluaran']);
    }

    /**
     * UnitOpeningBalance adalah saldo kas FISIK kas kebun hasil hitung tunai saat
     * cutover sistem — tidak pernah relevan untuk kantong Pribadi/Vendor karena dana
     * ke sana tidak pernah disimpan sebagai kas (ditransfer langsung HO ke rekening
     * pribadi/vendor lalu dibelanjakan). Kantong 'kebun' dan 'all' tetap menyertakan
     * seed ini; kantong 'pribadi' murni harus 0.
     */
    public function test_unit_opening_balance_seed_excluded_from_pribadi_only_kantong(): void
    {
        UnitOpeningBalance::create([
            'plantation_unit_id' => $this->unit->id,
            'amount'             => 2_533_178,
            'as_of_date'         => '2026-06-30',
        ]);

        $filters = ['period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id];

        $kebun   = $this->service->getCashBookData([...$filters, 'kantong' => 'kebun']);
        $pribadi = $this->service->getCashBookData([...$filters, 'kantong' => 'pribadi']);
        $all     = $this->service->getCashBookData([...$filters, 'kantong' => 'all']);

        $this->assertEquals(2_533_178, $kebun['opening_balance']);
        $this->assertEquals(0, $pribadi['opening_balance']);
        $this->assertEquals(2_533_178, $all['opening_balance']);
    }

    /**
     * Kasus Binanga Juli 2026 — kredit potongan yang melebihi biaya sub-kategorinya
     * sendiri harus meluber ke sub-kategori TETANGGA dalam KATEGORI yang sama, bukan
     * tertahan. Skopnya WAJIB sama dengan RecapQueryService supaya Buku Kas Harian dan
     * Rekap Buku Kas tidak pernah berbeda angka.
     */
    public function test_deduction_credit_spills_into_sibling_subcategory_of_same_category(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Sub A: transfer 455.000, realisasi 455.000, TAPI panjar 600.000 (lebih besar).
        $subA  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemA = ExpenseItem::factory()->create(['subcategory_id' => $subA->id]);
        $detA  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemA->id, 'amount' => 455_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detA->id, 'amount' => 455_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detA->id, 'amount' => 455_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-05',
        ]);

        $dedItem   = ExpenseItem::factory()->create(['subcategory_id' => $subA->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 600_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -600_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        // Sub B (KATEGORI SAMA): realisasi 5.252.000, tidak punya panjar.
        $subB  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemB = ExpenseItem::factory()->create(['subcategory_id' => $subB->id]);
        $detB  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemB->id, 'amount' => 5_252_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detB->id, 'amount' => 5_252_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detB->id, 'amount' => 5_252_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-06',
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        // Penerimaan = 455.000 + 5.252.000 − 600.000 = 5.107.000
        // Pengeluaran = (455.000 + 5.252.000) − 600.000 = 5.107.000 (kredit terpakai
        // PENUH: 455.000 dari sub A + sisa 145.000 diserap sub B)
        // Saldo akhir = 0 — inilah inti perbaikannya; sebelumnya saldo menggantung
        // −145.000 karena sisa kredit tertahan di sub A.
        $this->assertEquals(5_107_000, $cashBook['total_penerimaan']);
        $this->assertEquals(5_107_000, $cashBook['total_pengeluaran']);
        $this->assertEquals(0, $cashBook['closing_balance']);
    }

    /**
     * Regresi KP Agustus 2026: kredit panjar dulu dikonsumsi dari grup tanggal paling
     * awal di KATEGORI, tanpa melihat sub-kategori — akibatnya panjar Supir Truck
     * Harian menghabiskan Upah Muat Pupuk (sub-kategori Pemuat, tidak dipanjar sama
     * sekali) sehingga barisnya tampil Rp 0 di Buku Kas Harian padahal uangnya keluar.
     *
     * Sekarang panjar diserap sub-kategorinya sendiri lebih dulu, grup TERBESAR dulu.
     */
    public function test_deduction_consumed_from_own_subcategory_largest_group_first(): void
    {
        $cat = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Sub LAIN (tanggal paling awal, TIDAK dipanjar) — dulu inilah yang tergerus.
        $subOther  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemOther = ExpenseItem::factory()->create(['subcategory_id' => $subOther->id]);
        $detOther  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemOther->id, 'amount' => 395_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detOther->id, 'amount' => 395_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detOther->id, 'amount' => 395_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-01',
        ]);

        // Sub yang DIPANJAR: biaya kecil 35.000 (5 Ags) + upah utama 5.485.000 (6 Ags).
        $subPaid  = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);
        $itemPaid = ExpenseItem::factory()->create(['subcategory_id' => $subPaid->id]);
        $detPaid  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemPaid->id, 'amount' => 5_520_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detPaid->id, 'amount' => 5_520_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detPaid->id, 'amount' => 35_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-05',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detPaid->id, 'amount' => 5_485_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-06',
        ]);

        $dedItem   = ExpenseItem::factory()->create(['subcategory_id' => $subPaid->id, 'is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenses = collect($cashBook['rows'])->where('type', 'pengeluaran')->keyBy('date');

        // Sub lain tidak tersentuh sama sekali.
        $this->assertEquals(395_000, $expenses['2026-08-01']['amount']);
        // Biaya kecil di sub yang dipanjar tetap utuh — TIDAK lagi jadi Rp 0.
        $this->assertEquals(35_000, $expenses['2026-08-05']['amount']);
        // Panjar dilunasi dari pembayaran upah utama.
        $this->assertEquals(4_485_000, $expenses['2026-08-06']['amount']);

        // Total tetap: 5.915.000 realisasi − 1.000.000 panjar.
        $this->assertEquals(4_915_000, $cashBook['total_pengeluaran']);
    }

    /** Panjar satu kategori tidak boleh meluber ke kategori lain saat fase spill-over. */
    public function test_deduction_does_not_spill_into_other_category(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Kategori A: panjar 1.000.000 tapi realisasinya cuma 200.000 → 800.000 tertahan.
        $subA  = ExpenseSubcategory::factory()->create(['category_id' => ExpenseCategory::factory()->create(['company_id' => $this->companyId])->id]);
        $itemA = ExpenseItem::factory()->create(['subcategory_id' => $subA->id]);
        $detA  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemA->id, 'amount' => 200_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detA->id, 'amount' => 200_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detA->id, 'amount' => 200_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-05',
        ]);
        $dedItem = ExpenseItem::factory()->create(['subcategory_id' => $subA->id, 'is_deduction' => true]);
        $dedDet  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDet->id, 'amount' => -1_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        // Kategori B: realisasi 3.000.000, tanpa panjar — harus tetap utuh.
        $subB  = ExpenseSubcategory::factory()->create(['category_id' => ExpenseCategory::factory()->create(['company_id' => $this->companyId])->id]);
        $itemB = ExpenseItem::factory()->create(['subcategory_id' => $subB->id]);
        $detB  = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $itemB->id, 'amount' => 3_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detB->id, 'amount' => 3_000_000,
            'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $detB->id, 'amount' => 3_000_000,
            'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-06',
        ]);

        $cashBook = $this->service->getCashBookData([
            'period_year' => 2026, 'period_month' => 8, 'unit_id' => $this->unit->id,
        ]);

        $expenses = collect($cashBook['rows'])->where('type', 'pengeluaran')->keyBy('date');

        $this->assertEquals(0, $expenses['2026-08-05']['amount'], 'kategori A habis diserap panjarnya sendiri');
        $this->assertEquals(3_000_000, $expenses['2026-08-06']['amount'], 'kategori B tidak boleh tergerus');
        // Kredit terpakai hanya 200.000 (clamp kategori A), bukan 1.000.000.
        $this->assertEquals(3_000_000, $cashBook['total_pengeluaran']);
    }
}
