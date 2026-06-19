<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PdoHeader;
use App\Models\PlantationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PdoHeaderFactory extends Factory
{
    protected $model = PdoHeader::class;

    public function definition(): array
    {
        $month = $this->faker->numberBetween(1, 12);
        $year  = $this->faker->numberBetween(2024, 2026);
        $unit  = strtoupper($this->faker->lexify('??'));

        return [
            'company_id'         => Company::factory(),
            'plantation_unit_id' => PlantationUnit::factory(),
            'created_by'         => User::factory(),
            'closed_by'          => null,
            'pdo_number'         => 'PDO-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $unit . '-' . $this->faker->unique()->numberBetween(1, 9999),
            'period_month'       => $month,
            'period_year'        => $year,
            'submission_date'    => null,
            'status'             => PdoHeader::STATUS_DRAFT,
            'closure_type'       => null,
            'closed_at'          => null,
            'closure_notes'      => null,
            'notes'              => null,
        ];
    }
}
