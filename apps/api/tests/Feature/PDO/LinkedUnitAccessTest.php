<?php

namespace Tests\Feature\PDO;

use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fitur "unit linked": KERANI/ASISTEN_KEBUN yang terikat ke unit tertentu
 * (mis. Sosa) otomatis punya akses ke unit yang di-link (mis. Sosa Replanting)
 * lewat kolom plantation_units.linked_unit_id — tanpa akun kedua.
 */
class LinkedUnitAccessTest extends TestCase
{
    use RefreshDatabase;

    private PlantationUnit $sosa;
    private PlantationUnit $sosaReplanting;
    private PlantationUnit $otherUnit;
    private User $kerani;

    protected function setUp(): void
    {
        parent::setUp();

        $keraniRole = Role::factory()->create(['code' => Role::KERANI]);

        $this->sosaReplanting = PlantationUnit::factory()->create(['code' => 'SS-RPL', 'name' => 'Sosa - Replanting']);
        $this->sosa           = PlantationUnit::factory()->create([
            'code'           => 'SS',
            'name'           => 'Sosa',
            'company_id'     => $this->sosaReplanting->company_id,
            'linked_unit_id' => $this->sosaReplanting->id,
        ]);
        $this->otherUnit = PlantationUnit::factory()->create(['company_id' => $this->sosa->company_id]);

        $this->kerani = User::factory()->create([
            'role_id'            => $keraniRole->id,
            'plantation_unit_id' => $this->sosa->id,
            'company_id'         => $this->sosa->company_id,
        ]);
    }

    public function test_kerani_can_create_pdo_for_home_unit(): void
    {
        Sanctum::actingAs($this->kerani);

        $response = $this->postJson('/api/v1/pdo', [
            'plantation_unit_id' => $this->sosa->id,
            'period_month'       => 8,
            'period_year'        => 2026,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
    }

    public function test_kerani_can_create_pdo_for_linked_unit(): void
    {
        Sanctum::actingAs($this->kerani);

        $response = $this->postJson('/api/v1/pdo', [
            'plantation_unit_id' => $this->sosaReplanting->id,
            'period_month'       => 8,
            'period_year'        => 2026,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('pdo_headers', [
            'plantation_unit_id' => $this->sosaReplanting->id,
            'period_month'       => 8,
            'period_year'        => 2026,
        ]);
    }

    public function test_kerani_cannot_create_pdo_for_unrelated_unit(): void
    {
        Sanctum::actingAs($this->kerani);

        $response = $this->postJson('/api/v1/pdo', [
            'plantation_unit_id' => $this->otherUnit->id,
            'period_month'       => 8,
            'period_year'        => 2026,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('pdo_headers', ['plantation_unit_id' => $this->otherUnit->id]);
    }

    public function test_kerani_sees_pdo_from_both_home_and_linked_unit_in_listing(): void
    {
        PdoHeader::factory()->create(['plantation_unit_id' => $this->sosa->id, 'period_month' => 8, 'period_year' => 2026]);
        PdoHeader::factory()->create(['plantation_unit_id' => $this->sosaReplanting->id, 'period_month' => 8, 'period_year' => 2026]);
        PdoHeader::factory()->create(['plantation_unit_id' => $this->otherUnit->id, 'period_month' => 8, 'period_year' => 2026]);

        Sanctum::actingAs($this->kerani);

        $response = $this->getJson('/api/v1/pdo');

        $response->assertStatus(200);
        $unitIds = collect($response->json('data'))->pluck('plantation_unit_id')->unique()->values()->all();

        sort($unitIds);
        $expected = [$this->sosa->id, $this->sosaReplanting->id];
        sort($expected);

        $this->assertEquals($expected, $unitIds);
    }

    public function test_kerani_cannot_create_pdo_supplementary_for_unrelated_unit_parent(): void
    {
        $foreignPdo = PdoHeader::factory()->create([
            'plantation_unit_id' => $this->otherUnit->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        Sanctum::actingAs($this->kerani);

        $response = $this->postJson('/api/v1/pdo-supplementary', [
            'parent_pdo_header_id' => $foreignPdo->id,
            'funding_option'       => 'ho_transfer',
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('pdo_supplementary_headers', ['parent_pdo_header_id' => $foreignPdo->id]);
    }

    public function test_kerani_can_create_pdo_supplementary_for_linked_unit_parent(): void
    {
        $replantingPdo = PdoHeader::factory()->create([
            'plantation_unit_id' => $this->sosaReplanting->id,
            'status'             => PdoHeader::STATUS_FINAL,
        ]);

        Sanctum::actingAs($this->kerani);

        $response = $this->postJson('/api/v1/pdo-supplementary', [
            'parent_pdo_header_id' => $replantingPdo->id,
            'funding_option'       => 'ho_transfer',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('pdo_supplementary_headers', [
            'parent_pdo_header_id' => $replantingPdo->id,
            'plantation_unit_id'   => $this->sosaReplanting->id,
        ]);
    }
}
