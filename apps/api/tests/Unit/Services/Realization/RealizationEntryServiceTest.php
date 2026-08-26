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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);
        $this->assertEquals("{$pdo->pdo_number}/{$item->code}/1", $entry1->proof_number);

        $entry2 = $this->service->store([
            'pdo_detail_id'    => $detail2->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-CUSTOM-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);

        $entryB = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-06-21',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KWT-B',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
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
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);

        $this->assertEquals(RealizationEntry::FUNDING_KAS_KEBUN, $entry->funding_source);
    }

    /**
     * Item PDOT "Gunakan Kas Kebun" HARUS bisa direalisasikan walau kantong PDO tidak
     * punya transfer sama sekali — dananya dari saldo kas kebun yang sudah ada, dan
     * TransferEntry penandanya bernominal 0. Tanpa pengecualian BR-REAL-002, plafonnya
     * 0 dan kerani terblokir merealisasikan item yang dia buat sendiri.
     *
     * Kecukupan dana divalidasi DI DEPAN saat PDOT dibuat (pengajuan < saldo kas kebun,
     * plus penilaian kerani atas dana yang sudah terikat), bukan lewat plafon transfer.
     */
    public function test_kas_kebun_item_realizable_even_when_kantong_has_no_transfer(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        // Kantong kebun PDO ini KOSONG: satu-satunya entri transfer adalah penanda
        // kas kebun bernominal 0 — persis kondisi setelah mergeIntoParent().
        $kasKebunDetail = PdoDetail::factory()->create([
            'pdo_header_id'   => $pdo->id,
            'amount'          => 500000,
            'funding_option'  => 'kas_kebun',
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $kasKebunDetail->id,
            'amount'               => 0,
            'transfer_destination' => 'rek_kebun',
            'entry_source'         => 'system',
            'is_auto_generated'    => true,
        ]);

        $entry = $this->service->store([
            'pdo_detail_id'    => $kasKebunDetail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);

        $this->assertEquals(500000, $entry->amount);
        $this->assertEquals(RealizationEntry::FUNDING_KAS_KEBUN, $entry->funding_source);
    }

    /**
     * Pengecualian BR-REAL-002 HANYA untuk item kas_kebun — item biasa di PDO yang sama
     * tetap dipagari plafon kantong seperti biasa.
     */
    public function test_br_real_002_still_enforced_for_normal_item_alongside_kas_kebun_item(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $kasKebunDetail = PdoDetail::factory()->create([
            'pdo_header_id'  => $pdo->id,
            'amount'         => 500000,
            'funding_option' => 'kas_kebun',
        ]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $kasKebunDetail->id,
            'amount'               => 0,
            'transfer_destination' => 'rek_kebun',
            'entry_source'         => 'system',
            'is_auto_generated'    => true,
        ]);

        // Item biasa dengan transfer 300rb — plafon kantong hanya 300rb.
        $normalDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $normalDetail->id,
            'amount'               => 300000,
            'transfer_destination' => 'rek_kebun',
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $normalDetail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 400000, // > plafon 300rb → tetap harus DITOLAK
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => '',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);
    }

    // ─────────────────────────────────────────────────────
    // BR-PCV-001: realisasi tunai kas kebun wajib lewat Petty Cash Voucher
    // ─────────────────────────────────────────────────────

    public function test_kas_kebun_without_voucher_flag_is_rejected(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        try {
            $this->service->store([
                'pdo_detail_id'    => $detail->id,
                'transaction_date' => '2026-08-05',
                'amount'           => 100000,
                'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
                'proof_number'     => 'KW-DIRECT-001',
                'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            ], $this->kerani);
            $this->fail('Expected HttpResponseException (REALIZATION_REQUIRES_VOUCHER)');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('REALIZATION_REQUIRES_VOUCHER', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_kas_kebun_with_voucher_flag_is_accepted(): void
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 100000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-VOUCHER-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'some-voucher-id',
        ], $this->kerani);

        $this->assertEquals(100000, $entry->amount);
    }

    public function test_pribadi_vendor_kas_kebun_not_affected_by_br_pcv_001(): void
    {
        // BR-REAL-004 sudah melarang STAFF_PURCHASING pakai kas_kebun; pakai
        // MANAJER_KEUANGAN (juga kantong pribadi_vendor, tapi tidak kena BR-REAL-004)
        // untuk membuktikan BR-PCV-001 (khusus kantong KEBUN) tidak ikut memblokirnya.
        $managerRole = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $manager     = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $managerRole->id,
            'plantation_unit_id' => null,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            // Periode WAJIB dipatok: saldo awal di-seed 2026-07-01 dan transaksi
            // di test ini bertanggal 2026-08-xx. Kalau periode dibiarkan acak dari
            // factory (2024-2026), sesekali periodenya jatuh SETELAH Agustus 2026
            // sehingga realisasi ikut terhitung di saldo awal (cumulativeBalanceBefore)
            // dan terpotong dua kali — bikin test flaky.
            'period_year'        => 2026,
            'period_month'       => 8,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id' => $detail->id, 'transfer_destination' => TransferEntry::DEST_VENDOR, 'amount' => 1000000,
        ]);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'MGR-KK-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ], $manager);

        $this->assertEquals(500000, $entry->amount);
    }

    private function createPostedVoucherLinkedEntry(): RealizationEntry
    {
        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 1000000, transferred: 1000000);

        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 200000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-PCV-LOCKED-001',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'placeholder',
        ], $this->kerani);

        $voucher = \App\Models\PettyCashVoucher::factory()->posted()->create([
            'pdo_header_id' => $detail->pdo_header_id,
            'created_by'    => $this->kerani->id,
        ]);
        \App\Models\PettyCashVoucherLine::factory()->create([
            'petty_cash_voucher_id' => $voucher->id,
            'pdo_detail_id'         => $detail->id,
            'realization_entry_id'  => $entry->id,
            'amount'                => $entry->amount,
        ]);

        return $entry->fresh();
    }

    public function test_destroy_rejects_entry_linked_to_posted_voucher(): void
    {
        $entry = $this->createPostedVoucherLinkedEntry();

        try {
            $this->service->destroy($entry, $this->kerani);
            $this->fail('Expected HttpResponseException (REALIZATION_LOCKED_BY_VOUCHER)');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('REALIZATION_LOCKED_BY_VOUCHER', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
        $this->assertDatabaseHas('realization_entries', ['id' => $entry->id]);
    }

    public function test_update_rejects_amount_change_on_entry_linked_to_posted_voucher(): void
    {
        $entry = $this->createPostedVoucherLinkedEntry();

        try {
            $this->service->update($entry, ['amount' => 999999], $this->kerani);
            $this->fail('Expected HttpResponseException (REALIZATION_AMOUNT_LOCKED_BY_VOUCHER)');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('REALIZATION_AMOUNT_LOCKED_BY_VOUCHER', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_update_allows_explanation_change_on_entry_linked_to_posted_voucher(): void
    {
        $entry = $this->createPostedVoucherLinkedEntry();

        $updated = $this->service->update($entry, ['explanation' => 'Koreksi penjelasan'], $this->kerani);

        $this->assertEquals('Koreksi penjelasan', $updated->explanation);
    }

    // ─────────────────────────────────────────────────────
    // Saldo kantong = saldo awal + transfer − realisasi
    // ─────────────────────────────────────────────────────

    /**
     * Kas kebun memegang uang fisik, jadi sisa kas bulan lalu sah dipakai
     * membiayai realisasi bulan ini. Rumusnya wajib sama dengan saldo di Buku Kas
     * Harian, Rekap Buku Kas, Daftar PDO, dan Dashboard.
     */
    public function test_remaining_kantong_kebun_includes_opening_balance(): void
    {
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 5000000, transferred: 1000000, periodYear: 2026, periodMonth: 8);
        TransferEntry::where('pdo_detail_id', $detail->id)->update(['transfer_destination' => 'rek_kebun']);

        $available = $this->service->availableItemsForActor($detail->pdoHeader, $this->kerani);

        $this->assertSame(3000000, $available['saldo_awal']);
        $this->assertSame(1000000, $available['total_kantong']);
        $this->assertSame(4000000, $available['remaining_kantong']);
    }

    public function test_realization_may_use_opening_balance_beyond_transfer(): void
    {
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 5000000, transferred: 1000000, periodYear: 2026, periodMonth: 8);
        TransferEntry::where('pdo_detail_id', $detail->id)->update(['transfer_destination' => 'rek_kebun']);

        // 1.500.000 > transfer 1.000.000, tapi masih di bawah 3.000.000 + 1.000.000.
        $entry = $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 1500000,
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-OPEN-1',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);

        $this->assertSame(1500000, $entry->amount);
    }

    public function test_realization_rejected_when_exceeding_opening_balance_plus_transfer(): void
    {
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 9000000, transferred: 1000000, periodYear: 2026, periodMonth: 8);
        TransferEntry::where('pdo_detail_id', $detail->id)->update(['transfer_destination' => 'rek_kebun']);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $this->service->store([
            'pdo_detail_id'    => $detail->id,
            'transaction_date' => '2026-08-05',
            'amount'           => 4500000, // > 3.000.000 + 1.000.000
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'proof_number'     => 'KW-OPEN-2',
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            '_from_petty_cash_voucher' => 'test-voucher-id',
        ], $this->kerani);
    }

    /**
     * Kantong Pribadi/Vendor tidak pernah menyimpan kas fisik — HO mentransfer
     * langsung ke rekening orang/rekanan per item — jadi tetap per-periode.
     */
    public function test_remaining_kantong_pribadi_ignores_opening_balance(): void
    {
        UnitOpeningBalance::create(['plantation_unit_id' => $this->unit->id, 'amount' => 3000000, 'as_of_date' => '2026-07-01']);

        $mkRole  = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $manajer = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $mkRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        $detail = $this->makeDetail(PdoHeader::STATUS_FINAL, budget: 5000000, transferred: 1000000, periodYear: 2026, periodMonth: 8);
        TransferEntry::where('pdo_detail_id', $detail->id)->update(['transfer_destination' => 'pribadi']);

        $available = $this->service->availableItemsForActor($detail->pdoHeader, $manajer);

        $this->assertSame(0, $available['saldo_awal']);
        $this->assertSame(1000000, $available['remaining_kantong']);
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
                // Tanggal transfer WAJIB di dalam periode PDO-nya, seperti data riil
                // (diverifikasi di produksi: nol transfer bertanggal sebelum awal
                // periodenya). Default factory-nya now() sedangkan PdoHeaderFactory
                // memilih periode acak 2024-2026 — kalau periodenya jatuh setelah
                // hari ini, transfer itu ikut terhitung sebagai SALDO AWAL dan
                // plafon kantong jadi dobel (lihat
                // RealizationEntryService::remainingKantongForGroup()).
                'transfer_date' => sprintf('%04d-%02d-01', $pdo->period_year, $pdo->period_month),
            ]);
        }

        return $detail;
    }
}
