<?php

namespace Tests\Unit\Services\Realization;

use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealizationEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    private RealizationEntryService $service;
    private User $kerani;
    private string $companyId;
    private PlantationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new RealizationEntryService();
        $this->companyId = (string) Str::uuid();
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role         = Role::factory()->create(['code' => Role::KERANI]);
        $this->kerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // BR-REAL-001: hanya saat PDO final
    // ─────────────────────────────────────────────────────

    public function test_cannot_record_realization_if_pdo_not_final(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_SUBMITTED, budget: 1000000, transferred: 0);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'reference_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // BR-REAL-002: tidak boleh melebihi transfer yang masuk
    // ─────────────────────────────────────────────────────

    public function test_realization_exceeding_total_transfer_is_rejected(): void
    {
        // budget 1jt, tapi transfer baru 400rb
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 400000);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 500000, // lebih dari 400.000 yang sudah ditransfer
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'reference_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // BR-REAL-003: tidak boleh melebihi amount yang disetujui
    // ─────────────────────────────────────────────────────

    public function test_realization_exceeding_approved_budget_is_rejected(): void
    {
        // Transfer lebih dari budget (edge case: transfer melebihi budget tidak mungkin di skenario normal,
        // tapi kita tetap validasi di sisi realisasi)
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 500000, transferred: 800000);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 600000, // melebihi budget 500.000
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'reference_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    public function test_valid_realization_is_stored(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 800000,
            'payment_method'   => RealizationEntry::PAYMENT_TRANSFER,
            'reference_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_REKENING_KEBUN,
        ], $this->kerani);

        $this->assertEquals(800000, $entry->amount);
        $this->assertEquals(RealizationEntry::PAYMENT_TRANSFER, $entry->payment_method);
        $this->assertEquals($this->kerani->id, $entry->recorded_by);
    }

    // ─────────────────────────────────────────────────────
    // Tidak bisa hapus/ubah setelah PDO closed
    // ─────────────────────────────────────────────────────

    public function test_cannot_update_realization_if_pdo_closed(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_CLOSED, budget: 1000000, transferred: 1000000);
        $entry  = RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->update($entry, ['amount' => 500000], $this->kerani);
    }

    public function test_cannot_delete_realization_if_pdo_closed(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_CLOSED, budget: 1000000, transferred: 1000000);
        $entry  = RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->destroy($entry, $this->kerani);
    }

    public function test_can_delete_realization_when_pdo_final(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);
        $entry  = RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id]);

        $this->service->destroy($entry, $this->kerani);

        $this->assertDatabaseMissing('realization_entries', ['id' => $entry->id]);
    }

    // ─────────────────────────────────────────────────────
    // Audit Log
    // ─────────────────────────────────────────────────────

    public function test_audit_log_created_on_store(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'reference_number' => 'KW-999',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'realization_entries',
            'action'      => 'INSERT',
            'actor_id'    => $this->kerani->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────

    private function makeDetail(string $status, int $budget, int $transferred): PdoDetail
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => $status,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'amount'        => $budget,
        ]);

        if ($transferred > 0) {
            TransferEntry::factory()->create([
                'pdo_detail_id' => $detail->id,
                'amount'        => $transferred,
            ]);
        }

        return $detail;
    }
}
