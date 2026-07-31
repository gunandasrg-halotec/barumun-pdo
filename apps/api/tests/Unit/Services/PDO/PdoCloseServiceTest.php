<?php

namespace Tests\Unit\Services\PDO;

use App\Exceptions\PdoNotFinalException;
use App\Models\AuditLog;
use App\Models\PdoHeader;
use App\Models\Role;
use App\Models\User;
use App\Services\PDO\PdoCloseService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PdoCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdoCloseService $service;
    private User $manajer;
    private User $kerani;
    private PdoHeader $finalPdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PdoCloseService();

        $manajerRole = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $keraniRole  = Role::factory()->create(['code' => Role::KERANI]);

        $this->manajer = User::factory()->create(['role_id' => $manajerRole->id]);
        $this->kerani  = User::factory()->create(['role_id' => $keraniRole->id]);

        // PDO periode bulan ini dengan status final
        $this->finalPdo = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_FINAL,
            'period_year'  => Carbon::today()->year,
            'period_month' => Carbon::today()->month,
        ]);
    }

    // ── BR-CLOSE-002: Manual close ────────────────────────────────────────────

    public function test_manual_close_changes_status_to_closed(): void
    {
        $this->service->closeManual($this->finalPdo->id, $this->manajer, [
            'closed_date'   => Carbon::today()->toDateString(),
            'closure_notes' => 'Ditutup manual oleh manajer.',
        ]);

        $this->assertDatabaseHas('pdo_headers', [
            'id'           => $this->finalPdo->id,
            'status'       => PdoHeader::STATUS_CLOSED,
            'closure_type' => 'manual',
            'closed_by'    => $this->manajer->id,
        ]);
    }

    public function test_manual_close_records_audit_log(): void
    {
        $this->service->closeManual($this->finalPdo->id, $this->manajer, [
            'closed_date'   => Carbon::today()->toDateString(),
            'closure_notes' => 'Test audit.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $this->manajer->id,
            'entity_type'   => 'pdo_headers',
            'entity_id'     => $this->finalPdo->id,
            'action'        => 'CLOSE',
        ]);

        $log = AuditLog::where('entity_id', $this->finalPdo->id)->where('action', 'CLOSE')->first();
        $this->assertEquals('manual', $log->new_values['closure_type']);
    }

    public function test_manual_close_blocked_for_non_manajer_keuangan(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->closeManual($this->finalPdo->id, $this->kerani, [
            'closed_date' => Carbon::today()->toDateString(),
        ]);
    }

    public function test_manual_close_blocked_if_pdo_not_final(): void
    {
        $this->expectException(PdoNotFinalException::class);

        $draftPdo = PdoHeader::factory()->create(['status' => PdoHeader::STATUS_DRAFT]);

        $this->service->closeManual($draftPdo->id, $this->manajer, [
            'closed_date' => Carbon::today()->toDateString(),
        ]);
    }

    public function test_manual_close_blocked_if_date_before_today(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->closeManual($this->finalPdo->id, $this->manajer, [
            'closed_date' => Carbon::yesterday()->toDateString(),
        ]);
    }

    // ── BR-CLOSE-001: Auto close ──────────────────────────────────────────────

    /** Tanggal 1 bulan berikutnya — saat job dijadwalkan berjalan. */
    private function runDay(): Carbon
    {
        return Carbon::today()->startOfMonth()->addMonthNoOverflow();
    }

    public function test_auto_close_closes_all_final_pdos_of_previous_period(): void
    {
        // Job berjalan tanggal 1, menutup PDO periode bulan sebelumnya
        // (= bulan pembuatan $this->finalPdo di setUp).
        Carbon::setTestNow($this->runDay());

        $pdo2 = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_FINAL,
            'period_year'  => $this->finalPdo->period_year,
            'period_month' => $this->finalPdo->period_month,
        ]);

        $count = $this->service->closeAutomatic();

        $this->assertEquals(2, $count); // finalPdo + pdo2

        $this->assertDatabaseHas('pdo_headers', [
            'id'           => $this->finalPdo->id,
            'status'       => PdoHeader::STATUS_CLOSED,
            'closure_type' => 'system',
        ]);
        $this->assertDatabaseHas('pdo_headers', [
            'id'     => $pdo2->id,
            'status' => PdoHeader::STATUS_CLOSED,
        ]);

        Carbon::setTestNow(); // reset
    }

    public function test_auto_close_does_nothing_when_not_first_day_of_month(): void
    {
        // Pengaman: dijalankan di tanggal selain 1 → tidak menutup apa pun
        Carbon::setTestNow($this->runDay()->addDays(5));

        $count = $this->service->closeAutomatic();

        $this->assertEquals(0, $count);
        $this->assertDatabaseHas('pdo_headers', [
            'id'     => $this->finalPdo->id,
            'status' => PdoHeader::STATUS_FINAL, // tidak tersentuh
        ]);

        Carbon::setTestNow();
    }

    public function test_auto_close_skips_pdos_from_other_periods(): void
    {
        Carbon::setTestNow($this->runDay());

        // PDO periode bulan berjalan (bukan bulan lalu) — belum waktunya ditutup
        $currentPeriodPdo = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_FINAL,
            'period_year'  => $this->runDay()->year,
            'period_month' => $this->runDay()->month,
        ]);

        $this->service->closeAutomatic();

        $this->assertDatabaseHas('pdo_headers', [
            'id'     => $currentPeriodPdo->id,
            'status' => PdoHeader::STATUS_FINAL, // tidak diubah
        ]);

        Carbon::setTestNow();
    }

    public function test_auto_close_records_audit_log_with_null_actor(): void
    {
        Carbon::setTestNow($this->runDay());

        $this->service->closeAutomatic();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => null,
            'entity_type'   => 'pdo_headers',
            'entity_id'     => $this->finalPdo->id,
            'action'        => 'CLOSE',
        ]);

        $log = AuditLog::where('entity_id', $this->finalPdo->id)->where('action', 'CLOSE')->first();
        $this->assertEquals('system', $log->new_values['closure_type']);

        Carbon::setTestNow();
    }

    public function test_auto_close_skips_non_final_pdos(): void
    {
        Carbon::setTestNow($this->runDay());

        $draftPdo = PdoHeader::factory()->create([
            'status'       => PdoHeader::STATUS_DRAFT,
            'period_year'  => $this->finalPdo->period_year,
            'period_month' => $this->finalPdo->period_month,
        ]);

        $this->service->closeAutomatic();

        $this->assertDatabaseHas('pdo_headers', [
            'id'     => $draftPdo->id,
            'status' => PdoHeader::STATUS_DRAFT, // tidak diubah
        ]);

        Carbon::setTestNow();
    }
}
