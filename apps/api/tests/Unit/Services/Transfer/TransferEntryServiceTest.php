<?php

namespace Tests\Unit\Services\Transfer;

use App\Models\Company;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Models\Vehicle;
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
        $this->companyId = Company::factory()->create()->id;
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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

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
            'actor_user_id'    => $this->manajerKeuangan->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // summaryByPdo — sumber PDO per baris (Bulanan vs Tambahan)
    // ─────────────────────────────────────────────────────

    /**
     * Regresi: listAll() dulu memfilter is_auto_generated=false, sehingga entri
     * NEGATIF (potongan panjar & koreksi manual) tidak ikut terjumlah di halaman
     * Daftar Perintah Transfer. Subtotalnya jadi lebih besar dari uang yang
     * benar-benar keluar dan berbeda dari Transfer Dana/Rekap/Dashboard.
     */
    public function test_list_all_includes_negative_auto_generated_entries(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 5_000_000);

        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 4_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => -1_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
            'entry_source'         => TransferEntry::SOURCE_SYSTEM,
            'is_auto_generated'    => true,
            'notes'                => 'Potongan otomatis (mengurangi transfer Rek. Kebun)',
        ]);

        $rows = $this->service->listAll($this->manajerKeuangan);

        $this->assertCount(2, $rows, 'entri potongan harus ikut terkirim ke halaman');
        // Jumlah bersih = yang benar-benar ditransfer ke rekening kebun.
        $this->assertEquals(3_000_000, (int) $rows->sum('amount'));
    }

    // ─────────────────────────────────────────────────────
    // Sinkronisasi baris potongan dengan progres transfer PDO
    // ─────────────────────────────────────────────────────

    /**
     * @return array{0: PdoHeader, 1: TransferEntry, 2: TransferEntry, 3: TransferEntry}
     *         [pdo, item1, item2, potongan]
     */
    private function makePdoWithTwoItemsAndDeduction(): array
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);

        $d1 = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 3_000_000]);
        $d2 = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 2_000_000]);
        $item1 = TransferEntry::factory()->create(['pdo_detail_id' => $d1->id, 'amount' => 3_000_000, 'transfer_destination' => TransferEntry::DEST_REK_KEBUN]);
        $item2 = TransferEntry::factory()->create(['pdo_detail_id' => $d2->id, 'amount' => 2_000_000, 'transfer_destination' => TransferEntry::DEST_REK_KEBUN]);

        $dedItem   = ExpenseItem::factory()->create(['is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        $potongan  = TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
            'entry_source' => TransferEntry::SOURCE_SYSTEM, 'is_auto_generated' => true,
        ]);

        return [$pdo, $item1, $item2, $potongan];
    }

    public function test_deduction_stays_pending_while_items_remain(): void
    {
        [, $item1, , $potongan] = $this->makePdoWithTwoItemsAndDeduction();

        $result = $this->service->markTransferred([$item1->id], true, $this->manajerKeuangan);

        $this->assertFalse((bool) $potongan->fresh()->is_transferred, 'masih ada item tertunda');
        // `updated` hanya menghitung entri yang dipilih kasir, bukan baris potongan.
        $this->assertEquals(1, $result['updated']);
    }

    public function test_deduction_marked_once_last_item_transferred(): void
    {
        [, $item1, $item2, $potongan] = $this->makePdoWithTwoItemsAndDeduction();

        $this->service->markTransferred([$item1->id], true, $this->manajerKeuangan);
        $result = $this->service->markTransferred([$item2->id], true, $this->manajerKeuangan);

        $this->assertTrue((bool) $potongan->fresh()->is_transferred, 'semua item selesai → potongan ikut selesai');
        $this->assertNotNull($potongan->fresh()->transferred_at);
        $this->assertEquals(1, $result['updated'], 'potongan tidak ikut dihitung di updated');
    }

    /**
     * PDO yang potongannya sudah ada tapi belum punya entri transfer positif sama sekali
     * tidak boleh dianggap tuntas — kalau tidak, baris potongan hilang dari daftar kasir
     * padahal belum ada apa pun yang ditransfer.
     */
    public function test_deduction_not_marked_when_pdo_has_no_positive_entries_yet(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);

        $dedItem   = ExpenseItem::factory()->create(['is_deduction' => true]);
        $dedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'expense_item_id' => $dedItem->id, 'amount' => 1_000_000]);
        $potongan  = TransferEntry::factory()->create([
            'pdo_detail_id' => $dedDetail->id, 'amount' => -1_000_000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
            'entry_source' => TransferEntry::SOURCE_SYSTEM, 'is_auto_generated' => true,
        ]);

        // Picu sinkronisasi lewat entri PDO LAIN supaya PDO ini ikut ter-evaluasi.
        $this->service->markTransferred([$potongan->id], false, $this->manajerKeuangan);

        $this->assertFalse((bool) $potongan->fresh()->is_transferred);
    }

    public function test_deduction_returns_to_pending_when_an_item_is_unmarked(): void
    {
        [, $item1, $item2, $potongan] = $this->makePdoWithTwoItemsAndDeduction();

        $this->service->markTransferred([$item1->id, $item2->id], true, $this->manajerKeuangan);
        $this->assertTrue((bool) $potongan->fresh()->is_transferred);

        $this->service->markTransferred([$item2->id], false, $this->manajerKeuangan);

        $this->assertFalse((bool) $potongan->fresh()->is_transferred, 'ada item tertunda lagi → potongan kembali tertunda');
        $this->assertNull($potongan->fresh()->transferred_at);
    }

    public function test_summary_by_pdo_marks_source_pdo_number_for_merged_tambahan_rows(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->manajerKeuangan->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $bulananDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1_000_000]);

        $supp = PdoSupplementaryHeader::factory()->create([
            'parent_pdo_header_id' => $pdo->id,
            'company_id'           => $this->companyId,
            'plantation_unit_id'   => $this->unit->id,
            'created_by'           => $this->manajerKeuangan->id,
            'pdo_number'           => 'PDOT-2026-06-XX-0001',
            'merged_at'            => now(),
        ]);
        $tambahanDetail = PdoDetail::factory()->create([
            'pdo_header_id'               => $pdo->id,
            'source_pdo_supplementary_id' => $supp->id,
            'amount'                      => 500_000,
        ]);

        $summary = $this->service->summaryByPdo($pdo);

        $bulananRow  = $summary->firstWhere('pdo_detail_id', $bulananDetail->id);
        $tambahanRow = $summary->firstWhere('pdo_detail_id', $tambahanDetail->id);

        $this->assertNull($bulananRow['source_pdo_number']);
        $this->assertEquals('PDOT-2026-06-XX-0001', $tambahanRow['source_pdo_number']);
    }

    // ─────────────────────────────────────────────────────
    // markTransferred(): auto-realisasi untuk kantong pribadi/vendor
    // ─────────────────────────────────────────────────────

    public function test_mark_transferred_pribadi_creates_matching_realization(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);

        $result = $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        $this->assertEquals(1, $result['updated']);
        $this->assertEquals(1, $result['realizations_created']);

        $entry = RealizationEntry::where('pdo_detail_id', $detail->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals(300000, $entry->amount);
        $this->assertEquals(RealizationEntry::SETTLEMENT_PRIBADI_VENDOR, $entry->settlement_group);
        $this->assertEquals(RealizationEntry::FUNDING_REKENING_UTAMA, $entry->funding_source);
        $this->assertTrue((bool) $entry->is_auto_generated);
    }

    public function test_mark_transferred_rek_kebun_creates_no_realization(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
        ]);

        $result = $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        $this->assertEquals(0, $result['realizations_created']);
        $this->assertEquals(0, RealizationEntry::where('pdo_detail_id', $detail->id)->count());
    }

    public function test_mark_transferred_second_batch_only_realizes_the_delta(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer1 = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);
        $this->service->markTransferred([$transfer1->id], true, $this->manajerKeuangan);

        $transfer2 = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 200000,
            'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);
        $result = $this->service->markTransferred([$transfer2->id], true, $this->manajerKeuangan);

        $this->assertEquals(1, $result['realizations_created']);
        $this->assertEquals(500000, (int) RealizationEntry::where('pdo_detail_id', $detail->id)->sum('amount'));
        $this->assertEquals(2, RealizationEntry::where('pdo_detail_id', $detail->id)->count());
    }

    public function test_mark_transferred_skips_when_already_fully_realized_manually(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_VENDOR,
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'amount'           => 300000,
            'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
        ]);

        $result = $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        $this->assertEquals(0, $result['realizations_created']);
    }

    public function test_mark_transferred_skips_deduction_items(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);
        $item = ExpenseItem::factory()->create(['is_deduction' => true]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 300000, 'expense_item_id' => $item->id]);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 300000, 'is_auto_generated' => true, 'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);

        $result = $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        $this->assertEquals(0, $result['realizations_created']);
    }

    public function test_mark_transferred_requires_vehicle_for_inventory_item_and_rolls_back(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);
        $item = ExpenseItem::factory()->create(['code' => 'BBM-TRK-001']);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 300000, 'expense_item_id' => $item->id]);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 300000, 'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        try {
            $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan, []);
        } finally {
            $this->assertFalse((bool) $transfer->fresh()->is_transferred);
            $this->assertEquals(0, RealizationEntry::where('pdo_detail_id', $detail->id)->count());
        }
    }

    public function test_mark_transferred_with_vehicle_for_inventory_item_succeeds(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);
        $item = ExpenseItem::factory()->create(['code' => 'BBM-TRK-001']);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 300000, 'expense_item_id' => $item->id]);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'amount' => 300000, 'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);
        $vehicle = Vehicle::create(['nomor_polisi' => 'BK 1 AA', 'nama' => 'Truck', 'is_active' => true]);

        $result = $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan, [$detail->id => $vehicle->id]);

        $this->assertEquals(1, $result['realizations_created']);
        $entry = RealizationEntry::where('pdo_detail_id', $detail->id)->first();
        $this->assertEquals($vehicle->id, $entry->vehicle_id);
    }

    public function test_mark_transferred_batch_exceeding_kantong_rolls_back_entirely(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);
        $detailOk = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 5_000_000]);
        // Item lain di PDO yang sama sudah "over-realized" relatif terhadap transfernya
        // sendiri (mis. realokasi antar item dalam kantong yang sama, diizinkan oleh
        // BR-REAL-002) — supaya kantong pribadi_vendor PDO ini sudah nyaris habis.
        $otherDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 5_000_000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $otherDetail->id, 'amount' => 50000, 'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);
        RealizationEntry::factory()->create([
            'pdo_detail_id' => $otherDetail->id, 'amount' => 900000, 'settlement_group' => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
        ]);

        // Transfer baru: kantong total = 50.000 + 300.000 = 350.000, tapi realisasi
        // yang sudah ada (900.000) + delta baru (300.000) = 1.200.000 > 350.000.
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id' => $detailOk->id, 'amount' => 300000, 'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        try {
            $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);
        } finally {
            $this->assertFalse((bool) $transfer->fresh()->is_transferred);
        }
    }

    public function test_mark_transferred_allowed_for_direktur_keuangan(): void
    {
        $direkturRole = Role::factory()->create(['code' => Role::DIREKTUR_KEUANGAN]);
        $direktur     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $direkturRole->id]);

        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_VENDOR,
        ]);

        $result = $this->service->markTransferred([$transfer->id], true, $direktur);

        $this->assertEquals(1, $result['realizations_created']);
    }

    public function test_mark_transferred_two_details_same_item_get_sequential_proof_numbers(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);
        $pdo = PdoHeader::factory()->create([
            'company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id,
            'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL,
        ]);
        $item = ExpenseItem::factory()->create(['code' => 'REK-001']);
        $detail1 = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 300000, 'expense_item_id' => $item->id]);
        $detail2 = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 300000, 'expense_item_id' => $item->id]);
        $transfer1 = TransferEntry::factory()->create(['pdo_detail_id' => $detail1->id, 'amount' => 100000, 'transfer_destination' => TransferEntry::DEST_PRIBADI]);
        $transfer2 = TransferEntry::factory()->create(['pdo_detail_id' => $detail2->id, 'amount' => 100000, 'transfer_destination' => TransferEntry::DEST_PRIBADI]);

        $this->service->markTransferred([$transfer1->id, $transfer2->id], true, $this->manajerKeuangan);

        $proofNumbers = RealizationEntry::whereIn('pdo_detail_id', [$detail1->id, $detail2->id])->pluck('proof_number')->sort()->values();
        $this->assertEquals(["{$pdo->pdo_number}/REK-001/1", "{$pdo->pdo_number}/REK-001/2"], $proofNumbers->all());
    }

    public function test_unmark_transferred_rejected_when_realization_exceeds_remaining(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_PRIBADI,
        ]);
        $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        // Realisasi otomatis 300.000 sudah tercatat; batalkan tanda -> sisa transfer jadi 0 < realisasi.
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        $this->service->markTransferred([$transfer->id], false, $this->manajerKeuangan);
    }

    public function test_unmark_transferred_allowed_when_no_realization_yet(): void
    {
        $detail = $this->makeDetailWithStatus(PdoHeader::STATUS_FINAL, 1000000);
        $transfer = TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 300000,
            'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
        ]);
        $this->service->markTransferred([$transfer->id], true, $this->manajerKeuangan);

        $result = $this->service->markTransferred([$transfer->id], false, $this->manajerKeuangan);

        $this->assertEquals(1, $result['updated']);
        $this->assertFalse((bool) $transfer->fresh()->is_transferred);
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
