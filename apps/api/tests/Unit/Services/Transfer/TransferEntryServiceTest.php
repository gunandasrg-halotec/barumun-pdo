<?php

namespace Tests\Unit\Services\Transfer;

use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Transfer\TransferEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransferEntryService $service;
    private User $manajerKeuangan;
    private string $companyId;
    private PlantationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new TransferEntryService();
        $this->companyId = (string) Str::uuid();
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role                 = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajerKeuangan = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $role->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // BR-TRANSFER-001: hanya saat PDO final
    // ─────────────────────────────────────────────────────

    public function test_cannot_record_transfer_if_pdo_not_final(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_SUBMITTED, 1000000);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->store($detail, [
            'transfer_date'    => '2026-06-15',
            'amount'           => 500000,
            'reference_number' => 'TRF-001',
        ], $this->manajerKeuangan);
    }

    public function test_can_record_transfer_when_pdo_is_final(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);

        $entry = $this->service->store($detail, [
            'transfer_date'    => '2026-06-15',
            'amount'           => 500000,
            'reference_number' => 'TRF-001',
        ], $this->manajerKeuangan);

        $this->assertEquals(500000, $entry->amount);
        $this->assertEquals(TransferEntry::SOURCE_MANUAL, $entry->entry_source);
        $this->assertFalse((bool) $entry->is_auto_generated);
    }

    // ─────────────────────────────────────────────────────
    // BR-TRANSFER-002: tidak boleh melebihi amount detail
    // ─────────────────────────────────────────────────────

    public function test_transfer_exceeding_approved_amount_is_rejected(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->store($detail, [
            'transfer_date'    => '2026-06-15',
            'amount'           => 1500000, // melebihi 1.000.000
            'reference_number' => 'TRF-001',
        ], $this->manajerKeuangan);
    }

    public function test_cumulative_transfer_cannot_exceed_amount(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);

        $this->service->store($detail, ['transfer_date' => '2026-06-01', 'amount' => 700000, 'reference_number' => 'TRF-001'], $this->manajerKeuangan);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        // 700.000 + 400.000 = 1.100.000 > 1.000.000
        $this->service->store($detail, ['transfer_date' => '2026-06-15', 'amount' => 400000, 'reference_number' => 'TRF-002'], $this->manajerKeuangan);
    }

    public function test_two_transfers_within_budget_are_accepted(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);

        $this->service->store($detail, ['transfer_date' => '2026-06-01', 'amount' => 500000, 'reference_number' => 'TRF-001'], $this->manajerKeuangan);
        $entry2 = $this->service->store($detail, ['transfer_date' => '2026-06-15', 'amount' => 500000, 'reference_number' => 'TRF-002'], $this->manajerKeuangan);

        $this->assertEquals(500000, $entry2->amount);
        $this->assertEquals(1000000, $detail->transferEntries()->sum('amount'));
    }

    // ─────────────────────────────────────────────────────
    // BR-TRANSFER-003: auto entry tidak bisa diedit
    // ─────────────────────────────────────────────────────

    public function test_cannot_edit_auto_generated_transfer(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $entry  = TransferEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'is_auto_generated'=> true,
            'entry_source'     => TransferEntry::SOURCE_SYSTEM,
            'amount'           => 1000000,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->update($entry, ['amount' => 500000], $this->manajerKeuangan);
    }

    public function test_can_edit_manual_transfer(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $entry  = TransferEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'is_auto_generated'=> false,
            'entry_source'     => TransferEntry::SOURCE_MANUAL,
            'amount'           => 500000,
        ]);

        $updated = $this->service->update($entry, ['amount' => 400000], $this->manajerKeuangan);

        $this->assertEquals(400000, $updated->amount);
    }

    // ─────────────────────────────────────────────────────
    // Audit Log
    // ─────────────────────────────────────────────────────

    public function test_audit_log_created_on_store(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);

        $this->service->store($detail, [
            'transfer_date'    => '2026-06-15',
            'amount'           => 300000,
            'reference_number' => 'TRF-999',
        ], $this->manajerKeuangan);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'transfer_entries',
            'action'      => 'INSERT',
            'actor_id'    => $this->manajerKeuangan->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────

    private function makeDetailWithStatus(string $status, int $amount): PdoDetail
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => $status,
        ]);

        return PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'amount'        => $amount,
        ]);
    }
}
