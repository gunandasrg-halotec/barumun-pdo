<?php

namespace Tests\Feature\PettyCash;

use App\Models\Company;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PettyCash\PettyCashVoucherService;
use App\Services\Realization\RealizationEntryService;
use App\Support\Terbilang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PettyCashVoucherPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_returns_pdf_with_expected_content(): void
    {
        $company = Company::factory()->create(['name' => 'PT. Barumun Palma Nauli']);
        $unit    = PlantationUnit::factory()->create(['company_id' => $company->id, 'name' => 'Kota Pinang']);
        $role    = Role::factory()->create(['code' => Role::KERANI]);
        $kerani  = User::factory()->create([
            'company_id'         => $company->id,
            'role_id'            => $role->id,
            'plantation_unit_id' => $unit->id,
        ]);

        $pdo = PdoHeader::factory()->create([
            'company_id'         => $company->id,
            'plantation_unit_id' => $unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 2000000]);
        TransferEntry::factory()->create([
            'pdo_detail_id'        => $detail->id,
            'amount'               => 2000000,
            'transfer_destination' => 'rek_kebun',
        ]);

        $service = new PettyCashVoucherService(new RealizationEntryService());
        $voucher = $service->create([
            'pdo_header_id' => $pdo->id,
            'paid_to'       => 'Budi Santoso',
            'voucher_date'  => sprintf('%04d-%02d-15', $pdo->period_year, $pdo->period_month),
            'lines'         => [
                ['pdo_detail_id' => $detail->id, 'description' => 'Beli oli mesin', 'amount' => 150000],
            ],
        ], $kerani);

        Sanctum::actingAs($kerani);

        $response = $this->get("/api/v1/petty-cash-vouchers/{$voucher->id}/print");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');

        $pdfContent = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);

        // Render blade langsung supaya bisa cek isi teksnya (PDF binary tidak grep-able).
        $html = view('pdf.petty_cash_voucher', [
            'voucher'   => $voucher->load('lines.pdoDetail.expenseItem'),
            'company'   => $company,
            'unit'      => $unit,
            'pdoNumber' => $pdo->pdo_number,
            'total'     => $voucher->total_amount,
            'terbilang' => Terbilang::rupiah($voucher->total_amount),
            'rows'      => $voucher->lines,
        ])->render();

        $this->assertStringContainsString('PERKEBUNAN BARUMUN PALMA NAULI', $html);
        $this->assertStringContainsString('PETTY CASH VOUCHER', $html);
        $this->assertStringContainsString($voucher->voucher_number, $html);
        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString(Terbilang::rupiah(150000), $html);
        $this->assertStringContainsString('Dibuat Oleh', $html);
        $this->assertStringContainsString('Diperiksa Oleh', $html);
        $this->assertStringContainsString('disetujui Oleh', $html);
        $this->assertStringContainsString('Penerima', $html);
    }
}
