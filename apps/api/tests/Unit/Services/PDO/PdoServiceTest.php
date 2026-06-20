<?php

namespace Tests\Unit\Services\PDO;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\PDO\PdoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PdoServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdoService $service;
    private User $kerani;
    private PlantationUnit $unit;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new PdoService();
        $this->companyId = Company::factory()->create()->id;

        $keraniRole   = Role::factory()->create(['code' => Role::KERANI]);
        $this->unit   = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        $this->kerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // BR-PDO-001: Satu PDO per unit per bulan/tahun
    // ─────────────────────────────────────────────────────

    public function test_cannot_create_duplicate_pdo_for_same_unit_and_period(): void
    {
        $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 6,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 6,
            'period_year'        => 2026,
        ], $this->kerani);
    }

    public function test_can_create_pdo_for_different_periods(): void
    {
        $pdo1 = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 5,
            'period_year'        => 2026,
        ], $this->kerani);

        $pdo2 = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 6,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->assertNotEquals($pdo1->id, $pdo2->id);
    }

    // ─────────────────────────────────────────────────────
    // BR-PDO-002: Template otomatis dari item rutin
    // ─────────────────────────────────────────────────────

    public function test_creates_pdo_with_routine_items_as_template(): void
    {
        $this->createRoutineItem('A1-001', 'Gaji Manager');
        $this->createRoutineItem('A1-002', 'Gaji Staff');

        $pdo = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 6,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->assertEquals(2, $pdo->details()->count());
        $this->assertEquals(0, $pdo->details()->first()->amount); // amount=0, diisi KERANI
    }

    public function test_pdo_without_routine_items_starts_empty(): void
    {
        // Tidak ada routine items
        $pdo = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 7,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->assertEquals(0, $pdo->details()->count());
    }

    public function test_non_routine_items_not_included_in_template(): void
    {
        $this->createRoutineItem('A1-001', 'Gaji Rutin');
        $this->createNonRoutineItem('B1-001', 'Biaya Tidak Rutin');

        $pdo = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 8,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->assertEquals(1, $pdo->details()->count());
        $this->assertEquals('Gaji Rutin', $pdo->details()->first()->description);
    }

    public function test_routine_item_snapshots_account_unit_rate(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create([
            'subcategory_id'         => $sub->id,
            'code'                   => 'A1-001',
            'name'                   => 'Gaji Manager',
            'is_routine'             => true,
            'is_active'              => true,
            'default_account_number' => '5101001',
            'default_unit'           => 'Orang',
            'default_rate'           => 15000000,
        ]);

        $pdo    = $this->service->createPdo(['plantation_unit_id' => $this->unit->id, 'period_month' => 9, 'period_year' => 2026], $this->kerani);
        $detail = $pdo->details()->first();

        $this->assertEquals('5101001', $detail->account_number);
        $this->assertEquals('Orang', $detail->unit);
        $this->assertEquals(15000000, $detail->rate);
    }

    // ─────────────────────────────────────────────────────
    // BR-PDO-003: Hanya bisa edit saat draft
    // ─────────────────────────────────────────────────────

    public function test_cannot_edit_pdo_not_in_draft(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_SUBMITTED,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->updatePdo($pdo, ['notes' => 'coba ubah'], $this->kerani);
    }

    public function test_cannot_delete_pdo_not_in_draft(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_SUBMITTED,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->deletePdo($pdo, $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // PDO Details — snapshot & audit
    // ─────────────────────────────────────────────────────

    public function test_add_detail_snapshots_item_fields(): void
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create([
            'subcategory_id'         => $sub->id,
            'default_account_number' => '5102001',
            'default_unit'           => 'Liter',
            'default_rate'           => 10000,
            'is_active'              => true,
        ]);

        $pdo    = $this->makeDraftPdo();
        $detail = $this->service->addDetail($pdo, [
            'expense_item_id' => $item->id,
            'description'     => 'Pembelian BBM',
            'amount'          => 500000,
        ], $this->kerani);

        $this->assertEquals('5102001', $detail->account_number);
        $this->assertEquals('Liter', $detail->unit);
        $this->assertEquals(10000, $detail->rate);
    }

    public function test_pdo_number_format_correct(): void
    {
        $pdo = $this->service->createPdo([
            'plantation_unit_id' => $this->unit->id,
            'period_month'       => 6,
            'period_year'        => 2026,
        ], $this->kerani);

        $this->assertMatchesRegularExpression('/^PDO-2026-06-.+-\d{3}$/', $pdo->pdo_number);
    }

    // ─────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────

    private function createRoutineItem(string $code, string $name): ExpenseItem
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        return ExpenseItem::factory()->create([
            'subcategory_id' => $sub->id,
            'code'           => $code,
            'name'           => $name,
            'is_routine'     => true,
            'is_active'      => true,
        ]);
    }

    private function createNonRoutineItem(string $code, string $name): ExpenseItem
    {
        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);

        return ExpenseItem::factory()->create([
            'subcategory_id' => $sub->id,
            'code'           => $code,
            'name'           => $name,
            'is_routine'     => false,
            'is_active'      => true,
        ]);
    }

    private function makeDraftPdo(): PdoHeader
    {
        return PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_DRAFT,
        ]);
    }
}
