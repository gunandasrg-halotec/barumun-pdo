<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'company_id'       => Company::factory(),
            'code'             => strtoupper($this->faker->unique()->lexify('CAT-???')),
            'name'             => 'Kategori ' . $this->faker->word(),
            'display_order'    => $this->faker->numberBetween(1, 100),
            'include_in_recap' => true,
            'is_active'        => true,
            'notes'            => null,
        ];
    }
}
