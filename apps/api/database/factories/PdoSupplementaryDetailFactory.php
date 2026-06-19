<?php

namespace Database\Factories;

use App\Models\ExpenseItem;
use App\Models\PdoSupplementaryDetail;
use App\Models\PdoSupplementaryHeader;
use Illuminate\Database\Eloquent\Factories\Factory;

class PdoSupplementaryDetailFactory extends Factory
{
    protected $model = PdoSupplementaryDetail::class;

    public function definition(): array
    {
        return [
            'pdo_supplementary_header_id' => PdoSupplementaryHeader::factory(),
            'expense_item_id'             => ExpenseItem::factory(),
            'account_number'              => $this->faker->numerify('1-####-####'),
            'description'                 => $this->faker->sentence(4),
            'quantity'                    => $this->faker->randomFloat(2, 1, 100),
            'unit'                        => 'Kg',
            'rate'                        => $this->faker->numberBetween(10000, 500000),
            'amount'                      => $this->faker->numberBetween(100000, 10000000),
            'notes'                       => null,
            'display_order'               => $this->faker->numberBetween(1, 50),
        ];
    }
}
