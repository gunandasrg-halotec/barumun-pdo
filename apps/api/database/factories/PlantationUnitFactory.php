<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PlantationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlantationUnitFactory extends Factory
{
    protected $model = PlantationUnit::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code'       => strtoupper($this->faker->unique()->lexify('??')),
            'name'       => 'Kebun ' . $this->faker->city(),
            'is_active'  => true,
        ];
    }
}
