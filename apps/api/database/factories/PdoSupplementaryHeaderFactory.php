<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\PlantationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PdoSupplementaryHeaderFactory extends Factory
{
    protected $model = PdoSupplementaryHeader::class;

    public function definition(): array
    {
        $month = $this->faker->numberBetween(1, 12);
        $year  = $this->faker->numberBetween(2024, 2026);
        $unit  = strtoupper($this->faker->lexify('??'));

        return [
            'parent_pdo_header_id' => PdoHeader::factory(),
            'company_id'           => Company::factory(),
            'plantation_unit_id'   => PlantationUnit::factory(),
            'created_by'           => User::factory(),
            'pdo_number'           => 'PDOT-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $unit . '-' . $this->faker->unique()->numberBetween(1, 9999),
            'period_month'         => $month,
            'period_year'          => $year,
            'submission_date'      => null,
            'status'               => PdoSupplementaryHeader::STATUS_DRAFT,
            'merged_at'            => null,
            'notes'                => null,
        ];
    }
}
