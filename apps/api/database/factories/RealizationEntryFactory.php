<?php

namespace Database\Factories;

use App\Models\PdoDetail;
use App\Models\RealizationEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RealizationEntryFactory extends Factory
{
    protected $model = RealizationEntry::class;

    public function definition(): array
    {
        return [
            'pdo_detail_id'    => PdoDetail::factory(),
            'recorded_by'      => User::factory(),
            'transaction_date' => $this->faker->dateThisYear(),
            'amount'           => $this->faker->numberBetween(50000, 3000000),
            'payment_method'   => RealizationEntry::PAYMENT_TUNAI,
            'reference_number' => 'REAL-' . $this->faker->numerify('######'),
            'funding_source'   => RealizationEntry::FUNDING_KAS_KEBUN,
            'explanation'      => null,
        ];
    }
}
