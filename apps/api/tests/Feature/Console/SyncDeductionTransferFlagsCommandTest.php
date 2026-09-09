<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDeductionTransferFlagsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduksi insiden nyata PDO-2026-08-BN-001: entri transfer positif yang masih
     * tertunda dihapus manual dari database (satu-satunya cara entri committed
     * terhapus saat ini), sehingga baris potongan tersangkut di Daftar Perintah
     * Transfer walau syarat "semua sudah ditransfer" sudah terpenuhi.
     */
    public function test_recalculates_deduction_flag_after_committed_entry_deleted_manually(): void
    {
        $company = Company::factory()->create();
        $unit    = PlantationUnit::factory()->create(['company_id' => $company->id]);
        $role    = Role::factory()->create(['code' => Role::KERANI]);
        $kerani  = User::factory()->create(['company_id' => $company->id, 'role_id' => $role->id, 'plantation_unit_id' => $unit->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $d1    = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 3_000_000]);
        $item1 = TransferEntry::factory()->create([
            'pdo_detail_id' => $d1->id, 'amount' => 3_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN, 'is_transferred' => true,
        ]);

        $dedItem   = ExpenseItem::factory()->create(['is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 500_000]);
        $potongan  = TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -500_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
            'entry_source' => TransferEntry::SOURCE_SYSTEM, 'is_auto_generated' => true,
            'is_transferred' => false,
        ]);

        // Entri kedua yang masih tertunda — dihapus manual dari database (bukan lewat
        // aplikasi), meninggalkan baris potongan tersangkut.
        $d2    = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 2_000_000]);
        $item2 = TransferEntry::factory()->create([
            'pdo_detail_id' => $d2->id, 'amount' => 2_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN, 'is_transferred' => false,
        ]);
        $item2->delete();

        $this->assertFalse((bool) $potongan->fresh()->is_transferred, 'sebelum command dijalankan: masih tersangkut');

        $this->artisan('transfer:sync-deduction-flags')
            ->expectsOutputToContain('1 baris potongan disesuaikan')
            ->assertExitCode(0);

        $this->assertTrue((bool) $potongan->fresh()->is_transferred);
        $this->assertNull($potongan->fresh()->transferred_by, 'dijalankan dari CLI tanpa actor → atribusi sistem');
    }

    public function test_reports_no_adjustment_needed_when_already_synced(): void
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
        ]);

        $this->artisan('transfer:sync-deduction-flags')
            ->expectsOutputToContain('Sudah selaras')
            ->assertExitCode(0);
    }

    public function test_pdo_option_filters_to_single_pdo(): void
    {
        $company = Company::factory()->create();
        $unit    = PlantationUnit::factory()->create(['company_id' => $company->id]);
        $role    = Role::factory()->create(['code' => Role::KERANI]);
        $kerani  = User::factory()->create(['company_id' => $company->id, 'role_id' => $role->id, 'plantation_unit_id' => $unit->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $this->artisan('transfer:sync-deduction-flags', ['--pdo' => $pdo->pdo_number])
            ->expectsOutputToContain('di 1 PDO')
            ->assertExitCode(0);
    }

    public function test_unknown_pdo_number_fails(): void
    {
        $this->artisan('transfer:sync-deduction-flags', ['--pdo' => 'PDO-TIDAK-ADA'])
            ->assertExitCode(1);
    }
}
