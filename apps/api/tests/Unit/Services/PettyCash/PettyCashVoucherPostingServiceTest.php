<?php

namespace Tests\Unit\Services\PettyCash;

use App\Models\Company;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\PlantationUnit;
use App\Models\RealizationEntry;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PettyCash\PettyCashVoucherPostingService;
use App\Services\PettyCash\PettyCashVoucherService;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PettyCashVoucherPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PettyCashVoucherService $voucherService;
    private PettyCashVoucherPostingService $postingService;
    private string $companyId;
    private PlantationUnit $unit;
    private User $kerani;

    protected function setUp(): void
    {
        parent::setUp();

        $realizationService   = new RealizationEntryService();
        $this->voucherService = new PettyCashVoucherService($realizationService);
        $this->postingService = new PettyCashVoucherPostingService($realizationService);

        $this->companyId = Company::factory()->create()->id;
        $this->unit       = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role         = Role::factory()->create(['code' => Role::KERANI]);
        $this->kerani = User::factory()->create([
            'company_id'         => $this->companyId,
            'role_id'            => $role->id,
            'plantation_unit_id' => $this->unit->id,
        ]);

        Storage::fake('local');
    }

    private function makeDetail(int $budget, int $transferred): PdoDetail
    {
        $pdo = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $detail = PdoDetail::factory()->create([
            'pdo_header_id' => $pdo->id,
            'amount'        => $budget,
        ]);

        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => $transferred,
            'transfer_destination' => 'rek_kebun',
            // Tanggal transfer WAJIB di dalam periode PDO-nya, seperti data riil —
            // lihat catatan di PettyCashVoucherServiceTest::makeDetail().
            'transfer_date'        => sprintf('%04d-%02d-01', $pdo->period_year, $pdo->period_month),
        ]);

        return $detail->fresh();
    }

    private function voucherDateFor(PdoHeader $pdo): string
    {
        return sprintf('%04d-%02d-15', $pdo->period_year, $pdo->period_month);
    }

    private function createDraftVoucher(PdoHeader $pdo, array $lines): PettyCashVoucher
    {
        return $this->voucherService->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi Santoso',
            'voucher_date'  => $this->voucherDateFor($pdo),
            'lines'         => $lines,
        ], $this->kerani);
    }

    private function fakeScan(): UploadedFile
    {
        return UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');
    }

    // ─────────────────────────────────────────────────────

    public function test_happy_path_creates_realization_entries_and_posts_voucher(): void
    {
        $detail = $this->makeDetail(budget: 3000000, transferred: 3000000);
        $pdo    = $detail->pdoHeader;

        $voucher = $this->createDraftVoucher($pdo, [
            ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 500000],
            ['pdo_detail_id' => $detail->id, 'description' => 'Beli bensin', 'amount' => 300000],
        ]);

        $posted = $this->postingService->postWithScan($voucher, $this->fakeScan(), $this->kerani);

        $this->assertTrue($posted->isPosted());
        $this->assertEquals(800000, $posted->total_amount);
        $this->assertNotNull($posted->scan_file_path);
        Storage::disk('local')->assertExists($posted->scan_file_path);

        $this->assertEquals(2, RealizationEntry::count());
        foreach ($posted->lines as $line) {
            $this->assertNotNull($line->realization_entry_id);
            $entry = RealizationEntry::find($line->realization_entry_id);
            $this->assertFalse($entry->is_auto_generated);
            $this->assertEquals(RealizationEntry::PAYMENT_TUNAI, $entry->payment_method);
            $this->assertEquals(RealizationEntry::FUNDING_KAS_KEBUN, $entry->funding_source);
            $this->assertEquals(RealizationEntry::SETTLEMENT_KEBUN, $entry->settlement_group);
        }
    }

    public function test_proof_number_melanjutkan_sequence_existing(): void
    {
        $detail = $this->makeDetail(budget: 3000000, transferred: 3000000);
        $pdo    = $detail->pdoHeader;
        $itemCode = $detail->expenseItem->code;

        // Realisasi existing lewat jalur lain sudah memakai seq 1.
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'recorded_by'      => $this->kerani->id,
            'proof_number'     => "{$pdo->pdo_number}/{$itemCode}/1",
            'amount'           => 100000,
            'settlement_group' => RealizationEntry::SETTLEMENT_KEBUN,
            'funding_source'   => RealizationEntry::FUNDING_REKENING_KEBUN,
        ]);

        $voucher = $this->createDraftVoucher($pdo, [
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris A', 'amount' => 200000],
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris B', 'amount' => 300000],
        ]);

        $posted = $this->postingService->postWithScan($voucher, $this->fakeScan(), $this->kerani);

        $proofNumbers = $posted->lines->map(fn ($l) => $l->realizationEntry->proof_number)->sort()->values();

        $this->assertEquals("{$pdo->pdo_number}/{$itemCode}/2", $proofNumbers[0]);
        $this->assertEquals("{$pdo->pdo_number}/{$itemCode}/3", $proofNumbers[1]);
    }

    public function test_upload_kedua_ditolak_409_tanpa_entri_bertambah(): void
    {
        $detail = $this->makeDetail(budget: 3000000, transferred: 3000000);
        $pdo    = $detail->pdoHeader;

        $voucher = $this->createDraftVoucher($pdo, [
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris A', 'amount' => 200000],
        ]);

        $this->postingService->postWithScan($voucher, $this->fakeScan(), $this->kerani);
        $this->assertEquals(1, RealizationEntry::count());

        try {
            $this->postingService->postWithScan($voucher->fresh(), $this->fakeScan(), $this->kerani);
            $this->fail('Expected HttpResponseException (409)');
        } catch (HttpResponseException $e) {
            $this->assertEquals(409, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_ALREADY_POSTED', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }

        $this->assertEquals(1, RealizationEntry::count());
    }

    public function test_br_real_002_jebol_di_tengah_rollback_total_dan_file_dihapus(): void
    {
        $detail = $this->makeDetail(budget: 3000000, transferred: 3000000);
        $pdo    = $detail->pdoHeader;

        // Draft dibuat saat kantong masih penuh (3jt) → lolos validasi draft-time.
        $voucher = $this->createDraftVoucher($pdo, [
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris 1', 'amount' => 500000],
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris 2', 'amount' => 500000],
            ['pdo_detail_id' => $detail->id, 'description' => 'Baris 3', 'amount' => 500000],
        ]);

        // Simulasikan konsumsi kantong lewat jalur lain SETELAH draft dibuat, SEBELUM
        // di-posting — saldo kantong sekarang cuma cukup untuk 2 dari 3 baris.
        RealizationEntry::factory()->create([
            'pdo_detail_id'    => $detail->id,
            'recorded_by'      => $this->kerani->id,
            'amount'           => 1600000,
            'settlement_group' => RealizationEntry::SETTLEMENT_KEBUN,
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
        ]);

        try {
            $this->postingService->postWithScan($voucher->fresh(), $this->fakeScan(), $this->kerani);
            $this->fail('Expected HttpResponseException (BR-REAL-002)');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('REALIZATION_EXCEEDS_KANTONG', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }

        // Rollback total: hanya entri simulasi (di luar voucher) yang tersisa.
        $this->assertEquals(1, RealizationEntry::count());
        $this->assertTrue($voucher->fresh()->isDraft());
        $this->assertNull($voucher->fresh()->scan_file_path);

        // File yatim harus dihapus — tidak ada file tersisa di bawah prefix scan.
        $this->assertEmpty(Storage::disk('local')->allFiles('petty-cash-voucher-scans'));
    }

    public function test_voucher_kosong_ditolak_422(): void
    {
        $detail = $this->makeDetail(budget: 1000000, transferred: 1000000);
        $pdo    = $detail->pdoHeader;

        $voucher = PettyCashVoucher::factory()->create([
            'pdo_header_id' => $pdo->id,
            'created_by'    => $this->kerani->id,
            'status'        => PettyCashVoucher::STATUS_DRAFT,
        ]);

        try {
            $this->postingService->postWithScan($voucher, $this->fakeScan(), $this->kerani);
            $this->fail('Expected HttpResponseException (VOUCHER_EMPTY)');
        } catch (HttpResponseException $e) {
            $this->assertEquals(422, $e->getResponse()->getStatusCode());
            $this->assertEquals('VOUCHER_EMPTY', json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
    }
}
