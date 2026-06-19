<?php

namespace Database\Factories;

use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use Illuminate\Database\Eloquent\Factories\Factory;

class PdoDetailFactory extends Factory
{
    protected $model = PdoDetail::class;

    public function definition(): array
    {
        return [
            'pdo_header_id'              => PdoHeader::factory(),
            'expense_item_id'            => ExpenseItem::factory(),
            'source_pdo_supplementary_id'=> null,
            'account_number'             => $this->faker->numerify('1-####-####'),
            'description'                => $this->faker->sentence(4),
            'quantity'                   => $this->faker->randomFloat(2, 1, 100),
            'unit'                       => 'Kg',
            'rate'                       => $this->faker->numberBetween(10000, 500000),
            'amount'                     => $this->faker->numberBetween(100000, 10000000),
            'notes'                      => null,
            'display_order'              => $this->faker->numberBetween(1, 50),
        ];
    }
}
