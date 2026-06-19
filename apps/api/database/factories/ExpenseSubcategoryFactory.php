<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseSubcategoryFactory extends Factory
{
    protected $model = ExpenseSubcategory::class;

    public function definition(): array
    {
        return [
            'category_id'   => ExpenseCategory::factory(),
            'code'          => strtoupper($this->faker->unique()->lexify('SUB-???')),
            'name'          => 'Sub ' . $this->faker->word(),
            'display_order' => $this->faker->numberBetween(1, 100),
            'is_active'     => true,
            'notes'         => null,
        ];
    }
}
