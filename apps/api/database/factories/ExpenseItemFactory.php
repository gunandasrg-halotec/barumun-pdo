<?php

namespace Database\Factories;

use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseItemFactory extends Factory
{
    protected $model = ExpenseItem::class;

    public function definition(): array
    {
        return [
            'subcategory_id'        => ExpenseSubcategory::factory(),
            'code'                  => strtoupper($this->faker->unique()->lexify('ITEM-???')),
            'name'                  => 'Item ' . $this->faker->word(),
            'default_account_number'=> $this->faker->numerify('1-####-####'),
            'default_unit'          => $this->faker->randomElement(['Kg', 'Liter', 'Unit', 'HK']),
            'default_rate'          => $this->faker->numberBetween(10000, 1000000),
            'mode_input'            => ExpenseItem::MODE_MANUAL,
            'is_routine'            => false,
            'is_active'             => true,
            'notes'                 => null,
        ];
    }
}
