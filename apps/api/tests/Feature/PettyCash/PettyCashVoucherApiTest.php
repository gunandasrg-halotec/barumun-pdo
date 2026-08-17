<?php

namespace Tests\Feature\PettyCash;

use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PettyCashVoucherApiTest extends TestCase
{
    use RefreshDatabase;

    private PlantationUnit $unit;
    private User $kerani;
    private PdoHeader $pdo;
    private PdoDetail $detail;

    protected function setUp(): void
    {
        parent::setUp();

        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $this->unit = PlantationUnit::factory()->create();

        $this->kerani = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->unit->id,
            'company_id'         => $this->unit->company_id,
        ]);

        $this->pdo = PdoHeader::factory()->create([
            'company_id'         => $this->unit->company_id,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $this->kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        $this->detail = PdoDetail::factory()->create([
            'pdo_header_id' => $this->pdo->id,
            'amount'        => 2000000,
        ]);

        TransferEntry::factory()->create([
            'pdo_detail_id'        => $this->detail->id,
            'amount'               => 2000000,
            'transfer_destination' => 'rek_kebun',
        ]);
    }

    private function payload(): array
    {
        return [
            'paid_to'      => 'Budi Santoso',
            'voucher_date' => sprintf('%04d-%02d-15', $this->pdo->period_year, $this->pdo->period_month),
            'lines'        => [
                ['pdo_detail_id' => $this->detail->id, 'description' => 'Beli oli', 'amount' => 150000],
            ],
        ];
    }

    public function test_kerani_can_crud_voucher(): void
    {
        Sanctum::actingAs($this->kerani);

        $create = $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $this->payload());
        $create->assertStatus(201)->assertJsonPath('success', true);
        $voucherId = $create->json('data.id');

        $this->getJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/petty-cash-vouchers/{$voucherId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $voucherId);

        $this->putJson("/api/v1/petty-cash-vouchers/{$voucherId}", ['paid_to' => 'Nama Baru'])
            ->assertStatus(200)
            ->assertJsonPath('data.paid_to', 'Nama Baru');

        $this->deleteJson("/api/v1/petty-cash-vouchers/{$voucherId}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('petty_cash_vouchers', ['id' => $voucherId]);
    }

    public function test_non_kerani_forbidden(): void
    {
        $staffRole = Role::factory()->create(['code' => Role::STAFF_PURCHASING]);
        $staff     = User::factory()->create([
            'role_id'            => $staffRole->id,
            'plantation_unit_id' => $this->unit->id,
            'company_id'         => $this->unit->company_id,
        ]);
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $this->payload())
            ->assertStatus(403);
    }

    public function test_validasi_422_lines_kosong(): void
    {
        Sanctum::actingAs($this->kerani);

        $payload = $this->payload();
        $payload['lines'] = [];

        $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $payload)
            ->assertStatus(422);
    }

    public function test_pdo_closed_blocks_write_operations(): void
    {
        Sanctum::actingAs($this->kerani);

        $create = $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $this->payload());
        $create->assertStatus(201);
        $voucherId = $create->json('data.id');

        $this->pdo->update(['status' => PdoHeader::STATUS_CLOSED, 'closed_at' => now(), 'closure_type' => 'manual']);

        $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_IS_CLOSED');

        $this->putJson("/api/v1/petty-cash-vouchers/{$voucherId}", ['paid_to' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_IS_CLOSED');

        $this->deleteJson("/api/v1/petty-cash-vouchers/{$voucherId}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_IS_CLOSED');

        $this->postJson("/api/v1/petty-cash-vouchers/{$voucherId}/scan", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_IS_CLOSED');
    }

    public function test_idor_kerani_unit_lain_tidak_bisa_akses_voucher(): void
    {
        Sanctum::actingAs($this->kerani);
        $voucherId = $this->postJson("/api/v1/pdo/{$this->pdo->id}/petty-cash-vouchers", $this->payload())
            ->json('data.id');

        $otherUnit  = PlantationUnit::factory()->create();
        $keraniRole = Role::where('code', Role::KERANI)->first();
        $otherKerani = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $otherUnit->id,
            'company_id'         => $otherUnit->company_id,
        ]);
        Sanctum::actingAs($otherKerani);

        // PdoHeader punya global scope 'unit_access' sendiri: begitu $voucher->pdoHeader
        // di-lazy-load dari body controller (setelah EnsureUnitAccess selesai untuk
        // request ini), relasinya null untuk actor unit lain — find()/authorizeActor()
        // menerjemahkan null itu jadi 404 di ketiga endpoint, konsisten (tidak bocorkan
        // keberadaan voucher unit lain lewat kode error yang berbeda-beda).
        $this->getJson("/api/v1/petty-cash-vouchers/{$voucherId}")->assertStatus(404);
        $this->putJson("/api/v1/petty-cash-vouchers/{$voucherId}", ['paid_to' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/v1/petty-cash-vouchers/{$voucherId}")->assertStatus(404);
    }
}
