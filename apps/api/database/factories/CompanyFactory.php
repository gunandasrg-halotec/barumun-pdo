<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'code'      => strtoupper($this->faker->unique()->lexify('???')),
            'name'      => $this->faker->company(),
            'is_active' => true,
        ];
    }
}
