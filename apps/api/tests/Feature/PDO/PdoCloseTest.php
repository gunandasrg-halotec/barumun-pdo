<?php

namespace Tests\Feature\PDO;

use App\Models\PdoHeader;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PdoCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $manajer;
    private User $kerani;
    private PdoHeader $finalPdo;

    protected function setUp(): void
    {
        parent::setUp();

        $manajerRole = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $keraniRole  = Role::factory()->create(['code' => Role::KERANI]);

        $this->manajer = User::factory()->create(['role_id' => $manajerRole->id]);
        $this->kerani  = User::factory()->create(['role_id' => $keraniRole->id]);

        $this->finalPdo = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_FINAL,
            'period_year'  => Carbon::today()->year,
            'period_month' => Carbon::today()->month,
        ]);
    }

    // ── BR-CLOSE-002: Manual close ────────────────────────────────────────────

    public function test_manajer_keuangan_can_close_final_pdo(): void
    {
        Sanctum::actingAs($this->manajer);

        $response = $this->postJson("/api/v1/pdo/{$this->finalPdo->id}/close", [
            'closed_date'   => Carbon::today()->toDateString(),
            'closure_notes' => 'Test penutupan.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closure_type', 'MANUAL');
    }

    public function test_kerani_cannot_close_pdo(): void
    {
        Sanctum::actingAs($this->kerani);

        $response = $this->postJson("/api/v1/pdo/{$this->finalPdo->id}/close", [
            'closed_date' => Carbon::today()->toDateString(),
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_close_non_final_pdo_returns_422_PDO_NOT_FINAL(): void
    {
        Sanctum::actingAs($this->manajer);

        $draftPdo = PdoHeader::factory()->create(['status' => PdoHeader::STATUS_DRAFT]);

        $response = $this->postJson("/api/v1/pdo/{$draftPdo->id}/close", [
            'closed_date' => Carbon::today()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_NOT_FINAL');
    }

    // ── BR-CLOSE-003: Guard write pada PDO closed ─────────────────────────────

    public function test_write_to_closed_pdo_returns_422_PDO_IS_CLOSED(): void
    {
        Sanctum::actingAs($this->manajer);

        // Close PDO dulu
        $this->postJson("/api/v1/pdo/{$this->finalPdo->id}/close", [
            'closed_date' => Carbon::today()->toDateString(),
        ]);

        // Coba tambah realisasi ke PDO yang sudah closed
        $response = $this->postJson("/api/v1/pdo/{$this->finalPdo->id}/realizations", [
            'amount' => 100000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'PDO_IS_CLOSED');
    }

    public function test_read_closed_pdo_is_allowed(): void
    {
        Sanctum::actingAs($this->kerani);

        $closedPdo = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_CLOSED,
            'closure_type' => 'manual',
            'closed_at'    => now(),
        ]);

        $response = $this->getJson("/api/v1/pdo/{$closedPdo->id}");

        $response->assertStatus(200);
    }
}
