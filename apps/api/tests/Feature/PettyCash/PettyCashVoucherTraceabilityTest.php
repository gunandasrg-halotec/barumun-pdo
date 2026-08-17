<?php

namespace Tests\Feature\PettyCash;

use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PettyCash\PettyCashVoucherService;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §3g — keterlacakan scan dari entry/record realisasi: GET /realization-entries
 * harus menyertakan ringkasan voucher untuk entri hasil Petty Cash Voucher, dan
 * endpoint scan harus tampil inline (bukan dipaksa unduh) supaya bisa dibuka
 * langsung di tab baru dari chip pada Rekap/Buku Kas Harian.
 */
class PettyCashVoucherTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_realization_entries_list_includes_petty_cash_voucher_summary(): void
    {
        Storage::fake('local');

        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $unit       = PlantationUnit::factory()->create();
        $kerani     = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $unit->id,
            'company_id'         => $unit->company_id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $unit->company_id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 1000000,
            'transfer_destination' => 'rek_kebun',
        ]);

        $voucherService = new PettyCashVoucherService(new RealizationEntryService());
        $voucher = $voucherService->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => sprintf('%04d-%02d-15', $pdo->period_year, $pdo->period_month),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 150000],
            ],
        ], $kerani);

        Sanctum::actingAs($kerani);

        // Upload scan lewat endpoint HTTP supaya alur otentikasi/otorisasi asli terpakai.
        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/petty-cash-vouchers/{$voucher->id}/scan", ['file' => $file])
            ->assertStatus(201);

        // Entri langsung (bukan dari voucher) sebagai kontrol negatif.
        $directDetail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 500000]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $directDetail->id,
            'amount'               => 500000,
            'transfer_destination' => 'rek_kebun',
        ]);
        $this->postJson('/api/v1/realization-entries', [
            'pdo_detail_id'    => $directDetail->id,
            'transaction_date' => now()->toDateString(),
            'amount'           => 200000,
            'payment_method'   => 'transfer',
            'funding_source'   => 'rekening_kebun',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/realization-entries');
        $response->assertStatus(200);

        $entries = collect($response->json('data'));

        $voucherEntry = $entries->firstWhere('pdo_detail_id', $detail->id);
        $this->assertNotNull($voucherEntry);
        $this->assertNotNull($voucherEntry['petty_cash_voucher']);
        $this->assertEquals($voucher->voucher_number, $voucherEntry['petty_cash_voucher']['voucher_number']);

        $directEntry = $entries->firstWhere('pdo_detail_id', $directDetail->id);
        $this->assertNotNull($directEntry);
        $this->assertNull($directEntry['petty_cash_voucher']);
    }

    public function test_scan_endpoint_serves_inline_not_attachment(): void
    {
        Storage::fake('local');

        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $unit       = PlantationUnit::factory()->create();
        $kerani     = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $unit->id,
            'company_id'         => $unit->company_id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $unit->company_id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 1000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 1000000,
            'transfer_destination' => 'rek_kebun',
        ]);

        $voucherService = new PettyCashVoucherService(new RealizationEntryService());
        $voucher = $voucherService->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi',
            'voucher_date'  => sprintf('%04d-%02d-15', $pdo->period_year, $pdo->period_month),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli', 'amount' => 150000],
            ],
        ], $kerani);

        Sanctum::actingAs($kerani);

        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/petty-cash-vouchers/{$voucher->id}/scan", ['file' => $file])
            ->assertStatus(201);

        $response = $this->get("/api/v1/petty-cash-vouchers/{$voucher->id}/scan");

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline', $disposition);
        $this->assertStringNotContainsString('attachment', $disposition);
    }
}
