<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResyncPdoGrandTotalsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resyncs_stale_grand_total_amount(): void
    {
        $company = Company::factory()->create();
        $unit    = PlantationUnit::factory()->create(['company_id' => $company->id]);
        $role    = Role::factory()->create(['code' => Role::KERANI]);
        $kerani  = User::factory()->create(['company_id' => $company->id, 'role_id' => $role->id, 'plantation_unit_id' => $unit->id]);

        $category    = ExpenseCategory::factory()->create(['company_id' => $company->id]);
        $subcategory = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item        = ExpenseItem::factory()->create(['subcategory_id' => $subcategory->id, 'is_active' => true]);

        // Simulasikan PDO yang totalnya stale — mis. hasil merge PDO Tambahan sebelum
        // fix, di mana pdo_details bertambah tapi grand_total_amount tidak ter-update.
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'grand_total_amount' => 1_000_000,
        ]);
        PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => $item->id,
            'amount'          => 500_000,
        ]);

        // PDO lain yang sudah benar — tidak boleh tersentuh/dilaporkan sebagai berubah.
        $correctPdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'grand_total_amount' => 0,
        ]);

        $this->artisan('pdo:resync-grand-totals')
            ->expectsTable(['PDO', 'Sebelum', 'Sesudah'], [
                [$pdo->pdo_number, '1000000', '500000'],
            ])
            ->assertExitCode(0);

        $this->assertEquals(500_000, $pdo->fresh()->grand_total_amount);
        $this->assertEquals(0, $correctPdo->fresh()->grand_total_amount);
    }

    public function test_reports_no_changes_when_all_totals_already_correct(): void
    {
        $company = Company::factory()->create();
        $unit    = PlantationUnit::factory()->create(['company_id' => $company->id]);
        $role    = Role::factory()->create(['code' => Role::KERANI]);
        $kerani  = User::factory()->create(['company_id' => $company->id, 'role_id' => $role->id, 'plantation_unit_id' => $unit->id]);

        PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'grand_total_amount' => 0,
        ]);

        $this->artisan('pdo:resync-grand-totals')
            ->expectsOutputToContain('Semua grand_total_amount sudah sesuai')
            ->assertExitCode(0);
    }
}
