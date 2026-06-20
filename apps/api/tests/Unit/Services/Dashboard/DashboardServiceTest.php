<?php

namespace Tests\Unit\Services\Dashboard;

use App\Models\Company;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;
    private string $companyId;
    private PlantationUnit $unit;
    private User $manajer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new DashboardService();
        $this->companyId = Company::factory()->create()->id;
        $this->unit      = PlantationUnit::factory()->create(['company_id' => $this->companyId]);

        $role          = Role::factory()->create(['code' => Role::MANAJER_KEUANGAN]);
        $this->manajer = User::factory()->create([
            'company_id' => $this->companyId,
            'role_id'    => $role->id,
        ]);
    }

    public function test_summary_returns_pdo_count_by_status(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_DRAFT]);
        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_SUBMITTED, 'period_month' => 5]);
        PdoHeader::factory()->create(['company_id' => $this->companyId, 'plantation_unit_id' => $this->unit->id, 'created_by' => $kerani->id, 'status' => PdoHeader::STATUS_FINAL, 'period_month' => 4]);

        $summary = $this->service->summary($this->manajer);

        $this->assertArrayHasKey('pdo_by_status', $summary);
        $this->assertEquals(1, $summary['pdo_by_status'][PdoHeader::STATUS_DRAFT]);
        $this->assertEquals(1, $summary['pdo_by_status'][PdoHeader::STATUS_SUBMITTED]);
    }

    public function test_summary_includes_monthly_transfer_and_realization_totals(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        $pdo    = PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_FINAL,
            'period_month'       => now()->month,
            'period_year'        => now()->year,
        ]);
        $detail = PdoDetail::factory()->create(['pdo_header_id' => $pdo->id, 'amount' => 5000000]);
        TransferEntry::factory()->create(['pdo_detail_id' => $detail->id, 'amount' => 3000000]);

        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(3000000, $summary['total_transferred']);
    }

    public function test_pending_approval_count_correct_for_manajer_keuangan(): void
    {
        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);
        $kerani     = User::factory()->create(['company_id' => $this->companyId, 'role_id' => $keraniRole->id]);

        // Status yang menunggu MANAJER_KEUANGAN adalah in_review_manager
        PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_IN_REVIEW_MANAGER,
        ]);
        PdoHeader::factory()->create([
            'company_id'         => $this->companyId,
            'plantation_unit_id' => $this->unit->id,
            'created_by'         => $kerani->id,
            'status'             => PdoHeader::STATUS_DRAFT,
            'period_month'       => 5,
        ]);

        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(1, $summary['pending_pdo_count']);
    }

    public function test_summary_period_is_current_month(): void
    {
        $summary = $this->service->summary($this->manajer);

        $this->assertEquals(now()->month, $summary['period']['month']);
        $this->assertEquals(now()->year,  $summary['period']['year']);
    }
}
