<?php

namespace Tests\Unit\Services\Dashboard;

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
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private User $manajer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new DashboardService();
        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role          = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajer = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $role->id,
        ]);
    }

    public function test_summary_returns_pdo_count_by_status(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_DRAFT]);
        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_SUBMITTED, 'period_month' => 5]);
        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL, 'period_month' => 4]);

        $summary = $this->service->summary($this->manajer);

        $this->assertArrayHasKey('pdo_by_status', $summary);
        $this->assertEquals(1, $summary['pdo_by_status'][PdoHeader::STATUS_DRAFT]);
        $this->assertEquals(1, $summary['pdo_by_status'][PdoHeader::STATUS_SUBMITTED]);
    }

    public function test_summary_includes_monthly_transfer_and_realization_totals(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        $pdo    = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 5000000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 3000000]);

        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(3000000, $summary['total_transferred']);
    }

    public function test_pending_approval_count_correct_for_manajer_keuangan(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        // Status yang menunggu MANAJER_KEUANGAN adalah in_review_manager
        PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_IN_REVIEW_MANAGER,
        ]);
        PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_DRAFT,
            'period_month'       => 5,
        ]);

        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(1, $summary['pending_pdo_count']);
    }

    public function test_summary_period_is_current_month(): void
    {
        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(now()->month, $summary['period']['month']);
        $this->assertEquals(now()->year,  $summary['period']['year']);
    }

    public function test_kpi_total_amount_subtracts_deduction_items(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $item       = ExpenseItem::factory()->create(['is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1_000_000]);
        PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 300_000]);

        $summary = $this->service->summary($this->manajer);

        // 1.000.000 - 300.000 (deduction), bukan 1.300.000
        $this->assertEquals(700_000, $summary['total_amount']);
    }

    public function test_by_unit_breakdown_subtracts_deduction_and_is_not_multiplied_by_realizations(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $item       = ExpenseItem::factory()->create(['is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1_000_000]);
        PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => 300_000]);

        // Dua realisasi pada detail yang sama — harus tetap fan-out-safe untuk total_amount.
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 200_000]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 200_000]);

        $summary = $this->service->summary($this->manajer);
        $unitRow = collect($summary['by_unit'])->firstWhere('unit_id', $this->unit->id);

        $this->assertEquals(700_000, $unitRow['total_amount']);
        $this->assertEquals(400_000, $unitRow['total_realized']);
    }

    public function test_category_summary_subtracts_deduction_and_is_not_multiplied_by_transfers(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        $category    = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item        = ExpenseItem::factory()->create(['subcategory_id' => $subcategory->id, 'is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        $detail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 500_000,
        ]);

        // Dua transfer pada detail yang sama — total_budget harus tetap 1 kali (fan-out-safe).
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 100_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 100_000]);

        $result = $this->service->categorySummary($this->manajer);
        $row    = collect($result)->firstWhere('category_id', $category->id);

        // is_deduction => -500.000, bukan +500.000
        $this->assertEquals(-500_000, $row['total_budget']);
        // 100.000 + 100.000, bukan dikali oleh fan-out
        $this->assertEquals(200_000, $row['total_transferred']);
    }

    // ── total_realized/balance tidak boleh terinflasi oleh potongan yang belum ──
    //    punya realisasi asli (bug yang sama seperti RecapQueryService).

    public function test_total_realized_not_inflated_by_unrealized_deduction(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $dedItem    = ExpenseItem::factory()->create(['is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 8_894_864]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 8_894_864, 'transfer_destination' => 'rek_kebun']);

        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 4_500_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -4_500_000, 'transfer_destination' => 'rek_kebun',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $summary = $this->service->summary($this->manajer);
        $unitRow = collect($summary['by_unit'])->firstWhere('unit_id', $this->unit->id);

        $this->assertEquals(0, $summary['total_realized']);
        $this->assertEquals(0, $unitRow['total_realized']);
    }

    /**
     * Regresi PDO Juli: begitu realisasi penyeimbangnya tercatat, potongan harus
     * dinetkan penuh — bukan diabaikan. Pernah rusak saat netting dihapus total.
     */
    public function test_total_realized_nets_deduction_fully_once_realized(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $dedItem    = ExpenseItem::factory()->create(['is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);

        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 4_000_000, 'transfer_destination' => 'rek_kebun']);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 5_000_000]);

        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000, 'transfer_destination' => 'rek_kebun',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $summary = $this->service->summary($this->manajer);
        $unitRow = collect($summary['by_unit'])->firstWhere('unit_id', $this->unit->id);

        // 5.000.000 realisasi − 1.000.000 potongan = 4.000.000
        $this->assertEquals(4_000_000, $summary['total_realized']);
        $this->assertEquals(4_000_000, $unitRow['total_realized']);
    }

    /**
     * Clamp harus PER KANTONG: potongan kantong kebun yang belum direalisasi tidak
     * boleh "memakan" realisasi kantong pribadi/vendor. Sempat terjadi — Dashboard
     * SS Agustus menampilkan 8.565.000 padahal Rekap & Daftar PDO 13.065.000,
     * selisihnya persis potongan kebun 4.500.000.
     */
    public function test_kebun_deduction_does_not_consume_pribadi_realization(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $dedItem    = ExpenseItem::factory()->create(['is_deduction' => true]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);

        // Kantong pribadi/vendor: ada realisasi, TIDAK ada potongan.
        $pribadiDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 13_065_000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $pribadiDetail->id, 'amount' => 13_065_000, 'transfer_destination' => 'vendor']);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $pribadiDetail->id,
            'amount'           => 13_065_000,
            'funding_source'   => RealizationEntry::FUNDING_REKENING_UTAMA,
            'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
        ]);

        // Kantong kebun: ada potongan, BELUM ada realisasi sama sekali.
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 4_500_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -4_500_000, 'transfer_destination' => 'rek_kebun',
            'entry_source' => 'system', 'is_auto_generated' => true,
        ]);

        $summary = $this->service->summary($this->manajer);
        $unitRow = collect($summary['by_unit'])->firstWhere('unit_id', $this->unit->id);

        $this->assertEquals(13_065_000, $summary['total_realized']);
        $this->assertEquals(13_065_000, $unitRow['total_realized']);
    }
}
