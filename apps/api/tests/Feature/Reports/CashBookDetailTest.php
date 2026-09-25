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

/**
 * Endpoint Buku Kas Harian Detail = endpoint Buku Kas Harian yang sama + param group_by=item.
 * Yang diuji di sini lapis HTTP-nya: validasi param dan nama file ekspor.
 */
class CashBookDetailTest extends TestCase
{
    use RefreshDatabase;

    private PlantationUnit $unit;
    private User $manajer;
    private string $params;

    protected function setUp(): void
    {
        parent::setUp();

        $company    = Company::factory()->create();
        $this->unit = PlantationUnit::factory()->create(['company_id' => $company->id]);

        $role          = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajer = User::factory()->create([
            'company_id'         => $company->id,
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->manajer->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);

        // Dua item dalam satu sub-kategori & tanggal yang sama: 1 baris di mode lama,
        // 2 baris di mode detail.
        $cat = ExpenseCategory::factory()->create(['company_id' => $company->id]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $cat->id]);

        foreach ([400_000, 600_000] as $amount) {
            $item = ExpenseItem::factory()->create(['subcategory_id' => $sub->id]);
            $det  = PdoDetail::factory()->create([
                'pdo_header_id' => $pdo->id, 'expense_item_id' => $item->id, 'amount' => $amount,
            ]);
            TransferEntry::factory()->create([
                'pdo_detail_id' => $det->id, 'amount' => $amount,
                'transfer_destination' => 'rek_kebun', 'transfer_date' => '2026-08-01',
            ]);
            RealizationEntry::factory()->create([
                'pdo_detail_id' => $det->id, 'amount' => $amount,
                'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN, 'transaction_date' => '2026-08-04',
            ]);
        }

        $this->params = "period_year=2026&period_month=8&unit_id={$this->unit->id}";
    }

    public function test_group_by_item_returns_more_rows_with_identical_totals(): void
    {
        Sanctum::actingAs($this->manajer);

        $lama   = $this->getJson("/api/v1/reports/cashbook?{$this->params}")->assertOk()->json('data');
        $detail = $this->getJson("/api/v1/reports/cashbook?{$this->params}&group_by=item")->assertOk()->json('data');

        foreach (['opening_balance', 'closing_balance', 'total_penerimaan', 'total_pengeluaran'] as $key) {
            $this->assertSame($lama[$key], $detail[$key], "{$key} harus identik antar mode");
        }

        $this->assertGreaterThan(count($lama['rows']), count($detail['rows']));
    }

    public function test_invalid_group_by_is_rejected(): void
    {
        Sanctum::actingAs($this->manajer);

        $this->getJson("/api/v1/reports/cashbook?{$this->params}&group_by=bogus")
            ->assertStatus(422);
    }

    public function test_export_filename_marks_detail_mode(): void
    {
        Sanctum::actingAs($this->manajer);

        $detail = $this->get("/api/v1/reports/cashbook/export?{$this->params}&group_by=item")->assertOk();
        $this->assertStringContainsString('BukuKasHarianDetail_2026_8', $detail->headers->get('content-disposition'));

        $lama = $this->get("/api/v1/reports/cashbook/export?{$this->params}");
        $this->assertStringContainsString('BukuKasHarian_2026_8', $lama->headers->get('content-disposition'));
        $this->assertStringNotContainsString('Detail', $lama->headers->get('content-disposition'));
    }
}
