<?php

namespace Tests\Unit\Services\Realization;

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
use App\Models\UnitOpeningBalance;
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
        $this->companyId = Company::factory()->create()->id;
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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number' => 'KW-001',
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

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 500000, // lebih dari 400.000 yang sudah ditransfer
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // BR-REAL-002 saja yang berlaku: realokasi antar item dalam
    // kantong yang sama diizinkan (BR-REAL-003 dihapus di 8eac4c3)
    // ─────────────────────────────────────────────────────

    public function test_realization_can_exceed_item_budget_when_kantong_has_sufficient_transfer(): void
    {
        // Item budget 500rb, tapi kantong sudah terima 800rb transfer.
        // Realisasi 600rb melebihi budget item, tapi masih di bawah plafon kantong → harus lolos.
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 500000, transferred: 800000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 600000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertEquals(600000, $entry->amount);
    }

    // ─────────────────────────────────────────────────────
    // BR-REAL-006: khusus kantong pribadi_vendor, realisasi per ITEM
    // tidak boleh melebihi transfer ke item itu sendiri — beda dari
    // Kas Kebun yang boleh realokasi antar item dalam kantong yang sama.
    // ─────────────────────────────────────────────────────

    public function test_pribadi_vendor_realization_cannot_exceed_items_own_transfer_even_if_kantong_sufficient(): void
    {
        $staffRole = Role::factory()->create(['code' => Role::STAFF_PURCHASING]);
        $staff     = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $staffRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        // Item A: transfer split 2 tujuan — 1.180.000 ke rek_kebun (bukan kantong staff ini)
        // + 8.446.000 ke vendor. Kantong pribadi/vendor PDO ini punya total 8.446.000+X dari
        // item lain, tapi item A SENDIRI hanya menerima 8.446.000 ke pribadi/vendor.
        $itemA = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 9626000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $itemA->id, 'transfer_destination' => TransferEntry::DEST_REK_KEBUN, 'amount' => 1180000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $itemA->id, 'transfer_destination' => TransferEntry::DEST_VENDOR, 'amount' => 8446000]);

        // Item B lain di kantong pribadi/vendor yang sama, supaya total kantong PDO
        // (8.446.000 + 8.865.000 = 17.311.000) jauh lebih besar dari kebutuhan item A
        // (9.626.000) — BR-REAL-002 (level kantong) akan LOLOS kalau dicek sendirian.
        $itemB = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 8865000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $itemB->id, 'transfer_destination' => TransferEntry::DEST_VENDOR, 'amount' => 8865000]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        // Realisasi 9.626.000 untuk item A — melebihi transfer pribadi/vendor item A sendiri
        // (8.446.000), meski total kantong PDO (17.311.000) masih cukup. Harus DITOLAK.
        $this->service->store([
            'pdo_detail_id'    => $itemA->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 9626000,
            'payment_method'   => RealizationEntry::PAYMENT_TRANSFER,
            'proof_number'     => 'PBB-PRR-001/1',
            'funding_source'   => RealizationEntry::FUNDING_REKENING_UTAMA,
        ], $staff);
    }

    public function test_pribadi_vendor_realization_within_items_own_transfer_is_accepted(): void
    {
        $staffRole = Role::factory()->create(['code' => Role::STAFF_PURCHASING]);
        $staff     = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $staffRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $itemA = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 9626000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $itemA->id, 'transfer_destination' => TransferEntry::DEST_REK_KEBUN, 'amount' => 1180000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $itemA->id, 'transfer_destination' => TransferEntry::DEST_VENDOR, 'amount' => 8446000]);

        // Realisasi persis sebesar transfer pribadi/vendor item ini (8.446.000) — harus lolos.
        $entry = $this->service->store([
            'pdo_detail_id'    => $itemA->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 8446000,
            'payment_method'   => RealizationEntry::PAYMENT_TRANSFER,
            'proof_number'     => 'PBB-PRR-001/1',
            'funding_source'   => RealizationEntry::FUNDING_REKENING_UTAMA,
        ], $staff);

        $this->assertEquals(8446000, $entry->amount);
    }

    public function test_valid_realization_is_stored(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 800000,
            'payment_method'   => RealizationEntry::PAYMENT_TRANSFER,
            'proof_number' => 'KW-001',
            'funding_source'   => RealizationEntry::FUNDING_REKENING_KEBUN,
        ], $this->kerani);

        $this->assertEquals(800000, $entry->amount);
        $this->assertEquals(RealizationEntry::PAYMENT_TRANSFER, $entry->payment_method);
        $this->assertEquals($this->kerani->id, $entry->recorded_by);
    }

    // ─────────────────────────────────────────────────────
    // proof_number: auto-generate, prefill, & anti-duplikat
    // ─────────────────────────────────────────────────────

    public function test_proof_number_is_auto_generated_when_blank(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);
        $item   = $detail->expenseItem;

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertEquals("{$detail->pdoHeader->pdo_number}/{$item->code}/1", $entry->proof_number);
    }

    public function test_proof_number_sequence_continues_across_detail_rows_with_same_item_code_in_same_pdo(): void
    {
        // Simulasi: item yang sama muncul di dua baris pdo_detail berbeda dalam
        // satu PDO header (mis. PDO Bulanan + PDO Tambahan yang sudah di-merge).
        $detail1 = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 2000000);
        $pdo     = $detail1->pdoHeader;
        $item    = $detail1->expenseItem;

        $detail2 = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'amount'          => 1000000,
            'expense_item_id' => $item->id,
        ]);

        $entry1 = $this->service->store([
            'pdo_detail_id'    => $detail1->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
        $this->assertEquals("{$pdo->pdo_number}/{$item->code}/1", $entry1->proof_number);

        $entry2 = $this->service->store([
            'pdo_detail_id'    => $detail2->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
        $this->assertEquals("{$pdo->pdo_number}/{$item->code}/2", $entry2->proof_number);
    }

    public function test_manual_proof_number_rejected_when_duplicate_within_same_pdo(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 2000000);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-CUSTOM-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-CUSTOM-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    public function test_manual_proof_number_accepted_when_not_duplicate(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-UNIK-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertEquals('KWT-UNIK-001', $entry->proof_number);
    }

    public function test_update_rejects_duplicate_proof_number_within_same_pdo(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 2000000);

        $entryA = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-A',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $entryB = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-B',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->update($entryB, ['proof_number' => 'KWT-A'], $this->kerani);
    }

    public function test_update_allows_keeping_own_unchanged_proof_number(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-20',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-A',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $updated = $this->service->update($entry, ['proof_number' => 'KWT-A', 'amount' => 150000], $this->kerani);

        $this->assertEquals('KWT-A', $updated->proof_number);
        $this->assertEquals(150000, $updated->amount);
    }

    // ─────────────────────────────────────────────────────
    // Tidak bisa hapus/ubah setelah PDO closed
    // ─────────────────────────────────────────────────────

    public function test_cannot_update_realization_if_pdo_closed(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_CLOSED, budget: 1000000, transferred: 1000000);
        $entry  = RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->update($entry, ['amount' => 500000], $this->kerani);
    }

    public function test_cannot_delete_realization_if_pdo_closed(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_CLOSED, budget: 1000000, transferred: 1000000);
        $entry  = RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

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
    // list() filters — dipakai oleh drill-down KPI header Buku Kas Kebun
    // ─────────────────────────────────────────────────────

    public function test_list_filters_by_funding_source_group(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'funding_source' => RealizationEntry::FUNDING_KAS_KEBUN]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'funding_source' => RealizationEntry::FUNDING_REKENING_KEBUN]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'funding_source' => RealizationEntry::FUNDING_REKENING_UTAMA]);

        $kebunOnly = $this->service->list($this->kerani, [
            'funding_source' => [RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN],
        ]);

        $this->assertCount(2, $kebunOnly);
        $this->assertTrue($kebunOnly->every(fn ($e) => in_array($e->funding_source, [RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN])));
    }

    public function test_list_filters_by_period_and_unit(): void
    {
        $detailJuly = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000, periodYear: 2026, periodMonth: 7);
        $detailJune = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000, periodYear: 2026, periodMonth: 6);

        RealizationEntry::factory()->create(['pdo_detail_id' => $detailJuly->id]);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detailJune->id]);

        $julyOnly = $this->service->list($this->kerani, [
            'unit_id'      => $this->unit->id,
            'period_year'  => 2026,
            'period_month' => 7,
        ]);

        $this->assertCount(1, $julyOnly);
        $this->assertEquals($detailJuly->id, $julyOnly->first()->pdo_detail_id);
    }

    public function test_list_filters_by_date_range(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'transaction_date' => '2026-07-05']);
        RealizationEntry::factory()->create(['pdo_detail_id' => $detail->id, 'transaction_date' => '2026-07-20']);

        $result = $this->service->list($this->kerani, [
            'start_date' => '2026-07-01',
            'end_date'   => '2026-07-10',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('2026-07-05', $result->first()->transaction_date->format('Y-m-d'));
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
            'proof_number' => 'KW-999',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'realization_entries',
            'action'      => 'INSERT',
            'actor_user_id'    => $this->kerani->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────
    // Pengembalian Sisa Dana Bulan Lalu (sentinel FUND_RETURN)
    // ─────────────────────────────────────────────────────

    private function seedFundReturnItem(): ExpenseItem
    {
        $category = ExpenseCategory::factory()->create([
            'company_id'        => $this->companyId,
            'code'              => 'PSD',
            'include_in_recap'  => true,
            'is_system'         => true,
        ]);
        $subcategory = ExpenseSubcategory::factory()->create([
            'category_id' => $category->id,
            'code'        => 'PSD-KAS',
            'is_system'   => true,
        ]);

        return ExpenseItem::factory()->create([
            'subcategory_id'          => $subcategory->id,
            'code'                    => 'PSD-KAS-001',
            'is_system'               => true,
            'is_fund_return'          => true,
            'default_account_number'  => '1-10019',
        ]);
    }

    public function test_fund_return_succeeds_even_when_kantong_plafon_zero(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 5000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);
        // Kantong kebun PDO ini 0 — tidak ada transfer sama sekali.
        PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);

        $entry = $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 2000000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->assertSame(2000000, $entry->amount);
        $this->assertSame(RealizationEntry::SETTLEMENT_KEBUN, $entry->settlement_group);
        $this->assertDatabaseHas('pdo_details', [
            'pdo_header_id'   => $pdo->id,
            'expense_item_id' => ExpenseItem::where('code', 'PSD-KAS-001')->first()->id,
            'amount'          => 0,
        ]);
    }

    public function test_fund_return_rejected_when_exceeding_current_balance(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 1000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 5000000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    public function test_fund_return_item_appears_in_available_items_for_kebun_without_transfer(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $result = $this->service->availableItemsForActor($pdo, $this->kerani);

        $fundReturn = collect($result['items'])->firstWhere('pdo_detail_id', RealizationEntryService::FUND_RETURN_SENTINEL);
        $this->assertNotNull($fundReturn);
        $this->assertSame(3000000, $fundReturn['saldo']);
    }

    /**
     * Saldo item pengembalian harus berkurang setelah pengembaliannya dicatat —
     * sebelumnya selalu menampilkan saldo awal penuh walau sudah direalisasikan.
     */
    public function test_fund_return_saldo_reduced_after_realization_recorded(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 1200000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $result     = $this->service->availableItemsForActor($pdo, $this->kerani);
        $fundReturn = collect($result['items'])->firstWhere('pdo_detail_id', RealizationEntryService::FUND_RETURN_SENTINEL);

        $this->assertSame(1800000, $fundReturn['saldo']);          // 3.000.000 − 1.200.000
        $this->assertSame(1200000, $fundReturn['realized_group']);
    }

    /** Setelah dikembalikan penuh, saldo jadi 0 (bukan tetap sebesar saldo awal). */
    public function test_fund_return_saldo_zero_after_fully_returned(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 3000000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $result     = $this->service->availableItemsForActor($pdo, $this->kerani);
        $fundReturn = collect($result['items'])->firstWhere('pdo_detail_id', RealizationEntryService::FUND_RETURN_SENTINEL);

        $this->assertSame(0, $fundReturn['saldo']);
    }

    /**
     * Pengembalian kedua yang melebihi sisa harus ditolak, walau saldo kas kebun
     * berjalan masih cukup (mis. karena ada transfer masuk bulan ini).
     */
    public function test_second_fund_return_exceeding_remaining_is_rejected(): void
    {
        $this->seedFundReturnItem();
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        // Transfer masuk besar bulan ini — saldo kas berjalan tetap tebal, jadi
        // cek FUND_RETURN_EXCEEDS_BALANCE saja tidak akan menangkap kasus ini.
        $funded = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 50000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $funded->id, 'amount' => 50000000, 'transfer_destination' => 'rek_kebun',
        ]);

        $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 3000000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'PSD-1',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        // Sisa sudah 0 — pengembalian kedua harus ditolak.
        $this->service->store([
            'pdo_detail_id'    => RealizationEntryService::FUND_RETURN_SENTINEL,
            'pdo_header_id'    => $pdo->id,
            'transaction_date' => '2026-08-06',
            'amount'           => 1000000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'PSD-2',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // PDO Tambahan "Gunakan Kas Kebun": funding_source dipaksa kas_kebun
    // ─────────────────────────────────────────────────────

    public function test_realization_forces_kas_kebun_funding_source_for_supplementary_kas_kebun_item(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        // Item lain di PDO yang sama, dengan transfer, supaya BR-REAL-002 (plafon kantong)
        // terpenuhi — funding_option=kas_kebun sendiri memang tidak punya TransferEntry.
        $fundedDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $fundedDetail->id, 'amount' => 1000000]);

        $kasKebunDetail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'amount'          => 0,
            'funding_option'  => 'kas_kebun',
        ]);

        $entry = $this->service->store([
            'pdo_detail_id'    => $kasKebunDetail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 200000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-KK-001',
            'funding_source'   => RealizationEntry::FUNDING_REKENING_UTAMA, // dikirim salah, harus diabaikan
        ], $this->kerani);

        $this->assertEquals(RealizationEntry::FUNDING_KAS_KEBUN, $entry->funding_source);
    }

    private function makeDetail(string $status, int $budget, int $transferred, ?int $periodYear = null, ?int $periodMonth = null): PdoDetail
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => $status,
            ...($periodYear  ? ['period_year' => $periodYear] : []),
            ...($periodMonth ? ['period_month' => $periodMonth] : []),
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
