<?php

namespace Tests\Unit\Services\PDO;

use App\Models\Company;
use App\Models\ExpenseItem;
use App\Models\PdoApprovalLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PDO\PdoApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PdoApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdoApprovalService $service;
    private string $companyId;
    private PlantationUnit $unit;

    private User $kerani;
    private User $asisten;
    private User $manajerKebun;
    private User $manajerKeuangan;
    private User $direktur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new PdoApprovalService();
        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $roles = Role::factory()->createMany([
            ['code' => Role::KERANI],
            ['code' => Role::ASISTEN_KEBUN],
            ['code' => Role::MANAJER_KEBUN],
            ['code' => Role::MANAJER_KEUANGAN],
            ['code' => Role::DIREKTUR_KEUANGAN],
        ])->keyBy('code');

        $make = fn ($roleCode) => User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $roles[$roleCode]->id,
            'plantation_unit_id' => in_array($roleCode, [Role::KERANI, Role::ASISTEN_KEBUN]) ? $this->unit->id : null,
        ]);

        $this->kerani          = $make(Role::KERANI);
        $this->asisten         = $make(Role::ASISTEN_KEBUN);
        $this->manajerKebun    = $make(Role::MANAJER_KEBUN);
        $this->manajerKeuangan = $make(Role::MANAJER_KEUANGAN);
        $this->direktur        = $make(Role::DIREKTUR_KEUANGAN);
    }

    // ─────────────────────────────────────────────────────
    // SUBMIT
    // ─────────────────────────────────────────────────────

    public function test_kerani_can_submit_draft_pdo(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_DRAFT);

        $updated = $this->service->submit($pdo, '2026-06-01', $this->kerani);

        $this->assertEquals(PdoHeader::STATUS_SUBMITTED, $updated->status);
        $this->assertEquals('2026-06-01', $updated->submission_date->toDateString());
    }

    public function test_cannot_submit_if_all_amounts_are_zero(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_DRAFT, amount: 0);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->submit($pdo, '2026-06-01', $this->kerani);
    }

    public function test_non_kerani_cannot_submit(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_DRAFT);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->submit($pdo, '2026-06-01', $this->asisten);
    }

    public function test_cannot_submit_if_auto_external_detail_has_not_been_pulled(): void
    {
        $pdo = $this->makePdoWithAutoExternalDetail(PdoHeader::STATUS_DRAFT, amount: 0);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->submit($pdo, '2026-06-01', $this->kerani);
    }

    public function test_can_submit_if_auto_external_detail_has_successful_zero_pull(): void
    {
        $pdo = $this->makePdoWithAutoExternalDetail(
            PdoHeader::STATUS_DRAFT,
            amount: 0,
            externalAmountPulledAt: now(),
            externalPayload: [
                'status' => 'empty',
                'amount' => 0,
                'component_label' => 'Gaji Pokok',
            ],
        );

        $updated = $this->service->submit($pdo, '2026-06-01', $this->kerani);

        $this->assertEquals(PdoHeader::STATUS_SUBMITTED, $updated->status);
    }

    public function test_can_submit_if_auto_external_detail_has_successful_positive_pull(): void
    {
        $pdo = $this->makePdoWithAutoExternalDetail(
            PdoHeader::STATUS_DRAFT,
            amount: 1250000,
            externalAmountPulledAt: now(),
            externalPayload: [
                'status' => 'ok',
                'amount' => 1250000,
                'component_label' => 'Gaji Pokok',
            ],
        );

        $updated = $this->service->submit($pdo, '2026-06-01', $this->kerani);

        $this->assertEquals(PdoHeader::STATUS_SUBMITTED, $updated->status);
    }

    public function test_cannot_submit_if_auto_external_detail_snapshot_is_stale(): void
    {
        $pdo = $this->makePdoWithAutoExternalDetail(
            PdoHeader::STATUS_DRAFT,
            amount: 1250000,
            externalAmountPulledAt: now(),
            externalPayload: [
                'status' => 'ok',
                'amount' => 1250000,
                'source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
                'component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
                'component_key' => null,
                'role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
            ],
        );

        $pdo->details()->firstOrFail()->expenseItem()->update([
            'external_role' => ExpenseItem::PAYROLL_ROLE_BHL,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->submit($pdo->fresh(), '2026-06-01', $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // APPROVE CHAIN
    // ─────────────────────────────────────────────────────

    public function test_asisten_approves_submitted_pdo(): void
    {
        $pdo     = $this->makePdoWithDetail(PdoHeader::STATUS_SUBMITTED);
        $updated = $this->service->approve($pdo, null, $this->asisten);

        $this->assertEquals(PdoHeader::STATUS_REVIEWED_ASISTEN, $updated->status);
    }

    public function test_manajer_kebun_approves_reviewed_asisten(): void
    {
        $pdo     = $this->makePdoWithDetail(PdoHeader::STATUS_REVIEWED_ASISTEN);
        $updated = $this->service->approve($pdo, null, $this->manajerKebun);

        $this->assertEquals(PdoHeader::STATUS_IN_REVIEW_MANAGER, $updated->status);
    }

    public function test_manajer_keuangan_approves_in_review_manager(): void
    {
        // BR-APPR-002: Kedua manajer harus approve sebelum lanjut ke direktur.
        // Simulasi: Manajer Kebun sudah approve (manager_kebun_approved=true, status=in_review_manager)
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_IN_REVIEW_MANAGER);
        $pdo->update(['manager_kebun_approved' => true]);

        // Manajer Keuangan approve sekarang → keduanya sudah approve → advance ke direktur
        $updated = $this->service->approve($pdo->fresh(), null, $this->manajerKeuangan);

        $this->assertEquals(PdoHeader::STATUS_IN_REVIEW_DIREKTUR, $updated->status);
    }

    public function test_direktur_approves_to_final_and_generates_transfer(): void
    {
        $pdo     = $this->makePdoWithDetail(PdoHeader::STATUS_IN_REVIEW_DIREKTUR, amount: 5000000);
        $updated = $this->service->approve($pdo, 'Disetujui', $this->direktur);

        $this->assertEquals(PdoHeader::STATUS_FINAL, $updated->status);

        // BR-APPROVAL-003: transfer entry otomatis ter-generate
        $this->assertDatabaseHas('transfer_entries', [
            'pdo_detail_id'    => $pdo->details()->first()->id,
            'entry_source'     => TransferEntry::SOURCE_SYSTEM,
            'is_auto_generated'=> true,
            'amount'           => 5000000,
        ]);
    }

    public function test_wrong_role_cannot_approve(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_SUBMITTED);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        // KERANI bukan approver
        $this->service->approve($pdo, null, $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // REJECT
    // ─────────────────────────────────────────────────────

    public function test_reject_returns_pdo_to_draft(): void
    {
        $pdo     = $this->makePdoWithDetail(PdoHeader::STATUS_SUBMITTED);
        $updated = $this->service->reject($pdo, 'Anggaran terlalu besar', $this->asisten);

        $this->assertEquals(PdoHeader::STATUS_DRAFT, $updated->status);
    }

    public function test_reject_from_any_review_stage_goes_to_draft(): void
    {
        // Setiap stage memiliki approver yang berwenang reject
        $cases = [
            [PdoHeader::STATUS_SUBMITTED,          $this->asisten],
            [PdoHeader::STATUS_REVIEWED_ASISTEN,   $this->manajerKebun],
            [PdoHeader::STATUS_IN_REVIEW_MANAGER,  $this->manajerKeuangan],
            [PdoHeader::STATUS_IN_REVIEW_DIREKTUR, $this->direktur],
        ];

        foreach ($cases as [$status, $actor]) {
            $pdo     = $this->makePdoWithDetail($status);
            $updated = $this->service->reject($pdo, 'Perlu revisi', $actor);

            $this->assertEquals(PdoHeader::STATUS_DRAFT, $updated->status, "Gagal dari status: {$status}");
        }
    }

    // ─────────────────────────────────────────────────────
    // APPROVAL LOG
    // ─────────────────────────────────────────────────────

    public function test_approval_log_recorded_on_submit(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_DRAFT);
        $this->service->submit($pdo, '2026-06-01', $this->kerani);

        $this->assertDatabaseHas('pdo_approval_logs', [
            'pdo_header_id' => $pdo->id,
            'action'        => PdoApprovalLog::ACTION_SUBMIT,
            'actor_user_id' => $this->kerani->id,
        ]);
    }

    public function test_approval_log_recorded_on_approve(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_SUBMITTED);
        $this->service->approve($pdo, 'OK', $this->asisten);

        $this->assertDatabaseHas('pdo_approval_logs', [
            'pdo_header_id' => $pdo->id,
            'action'        => PdoApprovalLog::ACTION_APPROVE,
            'actor_user_id' => $this->asisten->id,
        ]);
    }

    public function test_approval_log_recorded_on_reject(): void
    {
        $pdo = $this->makePdoWithDetail(PdoHeader::STATUS_SUBMITTED);
        $this->service->reject($pdo, 'Ditolak', $this->asisten);

        $this->assertDatabaseHas('pdo_approval_logs', [
            'pdo_header_id' => $pdo->id,
            'action'        => PdoApprovalLog::ACTION_REJECT,
            'actor_user_id' => $this->asisten->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────

    private function makePdoWithDetail(string $status, int $amount = 1000000): PdoHeader
    {
        // Buat unit baru per panggilan untuk menghindari unique constraint (unit+period)
        $unit = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        $pdo  = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => $status,
        ]);

        PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'amount'        => $amount,
        ]);

        return $pdo;
    }

    /**
     * @param  array<string, mixed>|null  $externalPayload
     */
    private function makePdoWithAutoExternalDetail(
        string $status,
        int $amount = 0,
        ?\Illuminate\Support\Carbon $externalAmountPulledAt = null,
        ?array $externalPayload = null
    ): PdoHeader {
        $pdo = $this->makePdoWithDetail($status, amount: $amount);

        /** @var PdoDetail $detail */
        $detail = $pdo->details()->firstOrFail();

        $detail->expenseItem()->update([
            'mode_input' => ExpenseItem::MODE_AUTO_EXTERNAL,
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_role' => ExpenseItem::PAYROLL_ROLE_PEMANEN,
        ]);

        $detail->update([
            'external_source_system' => ExpenseItem::EXTERNAL_SOURCE_PAYROLL,
            'external_component' => ExpenseItem::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            'external_amount_pulled_at' => $externalAmountPulledAt,
            'external_payload' => $externalPayload,
        ]);

        return $pdo->fresh();
    }
}
