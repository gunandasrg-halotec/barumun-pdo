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
}
