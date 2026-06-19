<?php

namespace Database\Factories;

use App\Models\PdoDetail;
use App\Models\TransferEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransferEntryFactory extends Factory
{
    protected $model = TransferEntry::class;

    public function definition(): array
    {
        return [
            'pdo_detail_id'    => PdoDetail::factory(),
            'recorded_by'      => User::factory(),
            'entry_source'     => TransferEntry::SOURCE_MANUAL,
            'is_auto_generated'=> false,
            'transfer_date'    => now()->toDateString(),
            'amount'           => $this->faker->numberBetween(100000, 5000000),
            'reference_number' => 'TRF-' . $this->faker->numerify('######'),
            'notes'            => null,
        ];
    }
}
