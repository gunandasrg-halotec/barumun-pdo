<?php

namespace Tests\Unit\Services\PdoSupplementary;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryApprovalLog;
use App\Models\PdoSupplementaryHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\PdoSupplementary\PdoSupplementaryApprovalService;
use App\Services\PdoSupplementary\PdoSupplementaryMergeService;
use App\Services\PdoSupplementary\PdoSupplementaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PdoSupplementaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdoSupplementaryService $service;
    private PdoSupplementaryApprovalService $approvalService;
    private PdoSupplementaryMergeService $mergeService;

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

        $this->service         = new PdoSupplementaryService();
        $this->approvalService = new PdoSupplementaryApprovalService();
        $this->mergeService    = new PdoSupplementaryMergeService();

        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $roles = Role::factory()->createMany([
            ['code' => Role::KERANI],
            ['code' => Role::ASISTEN_KEBUN],
            ['code' => Role::MANAJER_KEBUN],
            ['code' => Role::MANAJER_KEUANGAN],
            ['code' => Role::DIREKTUR_KEUANGAN],
        ])->keyBy('code');

        $make = fn ($code) => User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $roles[$code]->id,
            'plantation_unit_id' => in_array($code, [Role::KERANI, Role::ASISTEN_KEBUN]) ? $this->unit->id : null,
        ]);

        $this->kerani          = $make(Role::KERANI);
        $this->asisten         = $make(Role::ASISTEN_KEBUN);
        $this->manajerKebun    = $make(Role::MANAJER_KEBUN);
        $this->manajerKeuangan = $make(Role::MANAJER_KEUANGAN);
        $this->direktur        = $make(Role::DIREKTUR_KEUANGAN);
    }

    // ─────────────────────────────────────────────────────
    // BR-SUPPL-001: Parent PDO harus final
    // ─────────────────────────────────────────────────────

    public function test_cannot_create_supplementary_if_parent_not_final(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_SUBMITTED);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);
    }

    public function test_can_create_supplementary_when_parent_is_final(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);

        $supp = $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);

        $this->assertEquals(PdoSupplementaryHeader::STATUS_DRAFT, $supp->status);
        $this->assertEquals($parentPdo->id, $supp->parent_pdo_header_id);
        $this->assertStringStartsWith('PDOT-', $supp->pdo_number);
    }

    // ─────────────────────────────────────────────────────
    // BR-SUPPL-002: Satu PDO Tambahan aktif per unit per parent PDO
    // ─────────────────────────────────────────────────────

    public function test_cannot_create_second_active_supplementary_for_same_parent(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);
    }

    public function test_can_create_new_supplementary_after_previous_is_rejected(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);

        // Reject supplementary pertama
        $supp->update(['status' => PdoSupplementaryHeader::STATUS_REJECTED]);

        // Sekarang bisa buat yang baru
        $supp2 = $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);
        $this->assertEquals(PdoSupplementaryHeader::STATUS_DRAFT, $supp2->status);
    }

    // ─────────────────────────────────────────────────────
    // Submit
    // ─────────────────────────────────────────────────────

    public function test_cannot_submit_empty_supplementary(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->service->create(['parent_pdo_header_id' => $parentPdo->id], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->approvalService->submit($supp, '2026-06-15', $this->kerani);
    }

    public function test_submit_moves_status_to_submitted(): void
    {
        $supp = $this->makeSupplementaryWithDetail();

        $updated = $this->approvalService->submit($supp, '2026-06-15', $this->kerani);

        $this->assertEquals(PdoSupplementaryHeader::STATUS_SUBMITTED, $updated->status);
    }

    public function test_rejected_supplementary_can_be_resubmitted(): void
    {
        $supp = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_REJECTED);

        $updated = $this->approvalService->submit($supp, '2026-06-16', $this->kerani);

        $this->assertEquals(PdoSupplementaryHeader::STATUS_SUBMITTED, $updated->status);

        // Harus tercatat sebagai resubmit di log
        $this->assertDatabaseHas('pdo_supplementary_approval_logs', [
            'pdo_supplementary_header_id' => $supp->id,
            'action'                      => PdoSupplementaryApprovalLog::ACTION_RESUBMIT,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // Approval chain
    // ─────────────────────────────────────────────────────

    public function test_full_approval_chain_leads_to_final_merged(): void
    {
        $supp = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_SUBMITTED);

        $supp = $this->approvalService->approve($supp, null, $this->asisten);
        $this->assertEquals(PdoSupplementaryHeader::STATUS_REVIEWED_ASISTEN, $supp->status);

        $supp = $this->approvalService->approve($supp, null, $this->manajerKebun);
        $this->assertEquals(PdoSupplementaryHeader::STATUS_IN_REVIEW_MANAGER, $supp->status);

        $supp = $this->approvalService->approve($supp, null, $this->manajerKeuangan);
        $this->assertEquals(PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR, $supp->status);

        $supp = $this->approvalService->approve($supp, 'Disetujui', $this->direktur);
        $this->assertEquals(PdoSupplementaryHeader::STATUS_FINAL_MERGED, $supp->status);
    }

    public function test_direktur_approval_auto_merge_resyncs_parent_grand_total_amount(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_SUBMITTED, $parentPdo);

        $supp = $this->approvalService->approve($supp, null, $this->asisten);
        $supp = $this->approvalService->approve($supp, null, $this->manajerKebun);
        $supp = $this->approvalService->approve($supp, null, $this->manajerKeuangan);
        $this->approvalService->approve($supp, 'Disetujui', $this->direktur);

        // Direktur approve men-trigger auto-merge (mergeIntoParent) — grand_total_amount
        // parent harus ikut ter-update, bukan hanya via endpoint merge manual.
        $this->assertEquals(500000, $parentPdo->fresh()->grand_total_amount);
    }

    public function test_reject_returns_status_to_draft(): void
    {
        $supp    = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_SUBMITTED);
        $updated = $this->approvalService->reject($supp, 'Anggaran tidak wajar', $this->asisten);

        // Sama seperti PDO Bulanan: reject kembali ke draft (bukan status rejected terpisah)
        // agar KERANI bisa langsung edit dan resubmit.
        $this->assertEquals(PdoSupplementaryHeader::STATUS_DRAFT, $updated->status);
    }

    // ─────────────────────────────────────────────────────
    // BR-MERGE
    // ─────────────────────────────────────────────────────

    public function test_merge_copies_details_to_parent_pdo(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_FINAL_MERGED, $parentPdo);

        $this->mergeService->merge($supp, $this->manajerKeuangan);

        // pdo_details parent harus bertambah satu baris dengan source_pdo_supplementary_id
        $this->assertDatabaseHas('pdo_details', [
            'pdo_header_id'              => $parentPdo->id,
            'source_pdo_supplementary_id'=> $supp->id,
        ]);

        // merged_at harus ter-set
        $this->assertNotNull($supp->fresh()->merged_at);
    }

    public function test_merge_resyncs_parent_grand_total_amount(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_FINAL_MERGED, $parentPdo);

        $this->mergeService->merge($supp, $this->manajerKeuangan);

        // grand_total_amount tersimpan di parent harus ikut bertambah 500.000 (amount detail
        // supplementary) setelah merge — bukan tetap 0/stale seperti sebelum fix.
        $this->assertEquals(500000, $parentPdo->fresh()->grand_total_amount);
    }

    public function test_cannot_merge_if_not_final_merged(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR, $parentPdo);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->mergeService->merge($supp, $this->manajerKeuangan);
    }

    public function test_cannot_merge_twice(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_FINAL_MERGED, $parentPdo);

        $this->mergeService->merge($supp, $this->manajerKeuangan);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->mergeService->merge($supp->fresh(), $this->manajerKeuangan);
    }

    public function test_only_manajer_keuangan_can_merge(): void
    {
        $parentPdo = $this->makeParentPdo(PdoHeader::STATUS_FINAL);
        $supp      = $this->makeSupplementaryWithDetail(PdoSupplementaryHeader::STATUS_FINAL_MERGED, $parentPdo);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->mergeService->merge($supp, $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────

    private function makeParentPdo(string $status): PdoHeader
    {
        return PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => $status,
        ]);
    }

    private function makeSupplementaryWithDetail(
        string $status = PdoSupplementaryHeader::STATUS_DRAFT,
        ?PdoHeader $parentPdo = null
    ): PdoSupplementaryHeader {
        $parentPdo ??= $this->makeParentPdo(PdoHeader::STATUS_FINAL);

        $supp = PdoSupplementaryHeader::factory()->create([
            'parent_pdo_header_id' => $parentPdo->id,
            'company_id'           => $this->companyId,
            'plantation_unit_id'   => $this->unit->id,
            'created_by'           => $this->kerani->id,
            'status'               => $status,
            'period_month'         => $parentPdo->period_month,
            'period_year'          => $parentPdo->period_year,
        ]);

        $category = ExpenseCategory::factory()->create(['company_id' => $this->companyId]);
        $sub      = ExpenseSubcategory::factory()->create(['category_id' => $category->id]);
        $item     = ExpenseItem::factory()->create(['subcategory_id' => $sub->id, 'is_active' => true]);

        \App\Models\PdoSupplementaryDetail::factory()->create([
            'pdo_supplementary_header_id' => $supp->id,
            'expense_item_id'             => $item->id,
            'amount'                      => 500000,
        ]);

        return $supp;
    }
}
