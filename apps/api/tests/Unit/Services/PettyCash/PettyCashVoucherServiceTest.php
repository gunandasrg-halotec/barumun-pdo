<?php

namespace Tests\Unit\Services\PettyCash;

use App\Models\Company;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PettyCash\PettyCashVoucherService;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class PettyCashVoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    private PettyCashVoucherService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private User $kerani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new PettyCashVoucherService(new RealizationEntryService());
        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role         = Role::factory()->create(['code' => Role::KERANI]);
        $this->kerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);
    }

    /** Buat PDO final + 1 pdo_detail dengan budget & transfer ke kantong rek_kebun. */
    private function makeDetail(int $budget, int $transferred, ?int $periodYear = null, ?int $periodMonth = null): PdoDetail
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            ...($periodYear ? ['period_year' => $periodYear] : []),
            ...($periodMonth ? ['period_month' => $periodMonth] : []),
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'amount'        => $budget,
        ]);

        if ($transferred > 0) {
            TransferEntry::factory()->create([
                'pdo_detail_id'        => $detail->id,
                'amount'               => $transferred,
                'transfer_destination' => 'rek_kebun',
                // Tanggal transfer WAJIB di dalam periode PDO-nya, seperti data riil:
                // transfer bertanggal sebelum awal periode akan terhitung dua kali —
                // sekali sebagai saldo awal, sekali sebagai transfer PDO ini
                // (lihat RealizationEntryService::remainingKantongForGroup()).
                // Default factory-nya now(), sedangkan PdoHeaderFactory memilih
                // periode acak, jadi tanpa ini test-nya flaky.
                'transfer_date'        => sprintf('%04d-%02d-01', $pdo->period_year, $pdo->period_month),
            ]);
        }

        return $detail->fresh();
    }

    private function periodOf(PdoHeader $pdo): array
    {
        return [(int) $pdo->period_year, (int) $pdo->period_month];
    }

    private function voucherDateFor(PdoHeader $pdo): string
    {
        return sprintf('%04d-%02d-15', $pdo->period_year, $pdo->period_month);
    }

    // ─────────────────────────────────────────────────────
    // Nomor voucher berurutan & reset per PDO
    // ─────────────────────────────────────────────────────

    public function test_nomor_voucher_berurutan_dan_reset_per_pdo(): void
    {
        // Periode dipatok eksplisit & berbeda: PdoHeaderFactory memilih bulan/tahun
        // ACAK, jadi dua PDO untuk unit yang sama sesekali bertabrakan di unique
        // (plantation_unit_id, period_month, period_year).
        $detail = $this->makeDetail(budget: 5000000, transferred: 5000000, periodYear: 2026, periodMonth: 6);
        $pdo    = $detail->pdoHeader;

        $v1 = $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 1000000],
            ],
        ], $this->kerani);

        $v2 = $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Beli bensin', 'amount' => 500000],
            ],
        ], $this->kerani);

        $this->assertEquals("PCV/{$pdo->pdo_number}/1", $v1->voucher_number);
        $this->assertEquals("PCV/{$pdo->pdo_number}/2", $v2->voucher_number);

        $otherDetail = $this->makeDetail(budget: 5000000, transferred: 5000000, periodYear: 2026, periodMonth: 7);
        $otherPdo    = $otherDetail->pdoHeader;

        $v3 = $this->service->create([
            'pdo_header_id' => $otherPdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($otherPdo),
            'lines'         => [
                ['pdo_detail_id' => $otherDetail->id, 'description' => 'Beli oli', 'amount' => 200000],
            ],
        ], $this->kerani);

        $this->assertEquals("PCV/{$otherPdo->pdo_number}/1", $v3->voucher_number);
    }

    // ─────────────────────────────────────────────────────
    // Otorisasi
    // ─────────────────────────────────────────────────────

    public function test_role_non_kerani_ditolak(): void
    {
        $staffRole = Role::factory()->create(['code' => Role::STAFF_PURCHASING]);
        $staff     = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $staffRole->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 100000],
                ],
            ], $staff);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_ROLE_FORBIDDEN', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_unit_mismatch_ditolak(): void
    {
        $otherUnit  = PlantationUnit::factory()->create(['company_id' => $this->companyId]);
        $otherKerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $this->kerani->role_id,
            'plantation_unit_id' => $otherUnit->id,
        ]);

        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 100000],
                ],
            ], $otherKerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
            $this->assertEquals('UNIT_MISMATCH', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    // ─────────────────────────────────────────────────────
    // BR-CLOSE / PDO status
    // ─────────────────────────────────────────────────────

    public function test_pdo_belum_final_ditolak(): void
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_SUBMITTED,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 1000000, 'transfer_destination' => 'rek_kebun']);

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 100000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(409, $e->getResponse()->getStatusCode());
            $this->assertEquals('PDO_NOT_FINAL', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    // ─────────────────────────────────────────────────────
    // Validasi baris
    // ─────────────────────────────────────────────────────

    public function test_baris_fund_return_ditolak(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;
        $detail->expenseItem->update(['is_fund_return' => true]);

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 100000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('FUND_RETURN_NOT_IN_VOUCHER', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_baris_deduction_ditolak(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;
        $detail->expenseItem->update(['is_deduction' => true]);

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 100000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(403, $e->getResponse()->getStatusCode());
            $this->assertEquals('DEDUCTION_NOT_REALIZABLE', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_baris_tanpa_transfer_ke_kebun_ditolak(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 0); // tidak ada TransferEntry sama sekali
        $pdo    = $detail->pdoHeader;

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 100000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_ITEM_NOT_AVAILABLE', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_voucher_date_luar_periode_ditolak(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000, periodYear: 2026, periodMonth: 7);
        $pdo    = $detail->pdoHeader;

        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => '2026-08-01', // di luar periode Juli
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 100000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_DATE_OUT_OF_PERIOD', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_total_melebihi_sisa_kantong_termasuk_reservasi_draft_lain(): void
    {
        $detail = $this->makeDetail(budget: 2000000, transferred: 1000000); // kantong hanya 1jt
        $pdo    = $detail->pdoHeader;

        // Voucher draft pertama menghabiskan 700rb dari kantong 1jt.
        $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 700000],
            ],
        ], $this->kerani);

        // Voucher kedua minta 400rb — sisa kantong cuma 300rb (reservasi draft pertama ikut dihitung).
        try {
            $this->service->create([
                'pdo_header_id' => $pdo->id,
                'paid_to'       => 'Budi',
                'voucher_date'  => $this->voucherDateFor($pdo),
                'lines'         => [
                    ['pdo_detail_id' => $detail->id, 'description' => 'y', 'amount' => 400000],
                ],
            ], $this->kerani);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_EXCEEDS_KANTONG', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    public function test_update_dan_destroy_ditolak_untuk_voucher_posted(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;

        $voucher = $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'x', 'amount' => 100000],
            ],
        ], $this->kerani);

        $voucher->update(['status' => PettyCashVoucher::STATUS_POSTED, 'posted_at' => now(), 'scan_file_path' => 'x.pdf']);

        try {
            $this->service->update($voucher, ['paid_to' => 'Lain'], $this->kerani);
            $this->fail('Expected HttpResponseException on update');
        } catch (HttpResponseException $e) {
            $this->assertEquals(409, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_NOT_DRAFT', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }

        try {
            $this->service->destroy($voucher, $this->kerani);
            $this->fail('Expected HttpResponseException on destroy');
        } catch (HttpResponseException $e) {
            $this->assertEquals(409, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_NOT_DRAFT', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }

    // ─────────────────────────────────────────────────────
    // §0-bis — regresi WAJIB: realokasi antar item dalam kantong kebun
    // ─────────────────────────────────────────────────────

    public function test_item_boleh_melebihi_anggarannya_sendiri_saat_kantong_cukup(): void
    {
        // Anggaran item 500rb, tapi kantong PDO (dari transfer item ini) 2jt.
        $detail = $this->makeDetail(budget: 500000, transferred: 2000000);
        $pdo    = $detail->pdoHeader;

        $voucher = $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Melebihi anggaran item', 'amount' => 900000],
            ],
        ], $this->kerani);

        $this->assertEquals(900000, $voucher->total_amount);
    }

    public function test_item_dengan_saldo_negatif_tetap_boleh_dimasukkan_ke_voucher_baru(): void
    {
        // Item sudah over-realized lewat jalur lain (realisasi langsung/voucher lain):
        // anggaran 300rb, tapi sudah direalisasikan 500rb (saldo -200rb). Kantong PDO
        // (transfer) besar (3jt) sehingga masih ada ruang untuk voucher baru.
        $detail = $this->makeDetail(budget: 300000, transferred: 3000000);
        $pdo    = $detail->pdoHeader;

        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'recorded_by'      => $this->kerani->id,
            'amount'           => 500000,
            'settlement_group' => RealizationEntry::SETTLEMENT_KEBUN,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        $voucher = $this->service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Item saldo negatif', 'amount' => 400000],
            ],
        ], $this->kerani);

        $this->assertEquals(400000, $voucher->total_amount);
    }
}
