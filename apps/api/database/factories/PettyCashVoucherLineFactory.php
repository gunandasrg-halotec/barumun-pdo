<?php

namespace Database\Factories;

use App\Models\PdoDetail;
use App\Models\PettyCashVoucher;
use App\Models\PettyCashVoucherLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashVoucherLineFactory extends Factory
{
    protected $model = PettyCashVoucherLine::class;

    public function definition(): array
    {
        return [
            'petty_cash_voucher_id' => PettyCashVoucher::factory(),
            'pdo_detail_id'         => PdoDetail::factory(),
            'line_no'               => 1,
            'description'           => $this->faker->sentence(3),
            'amount'                => $this->faker->numberBetween(50000, 1000000),
        ];
    }
}
