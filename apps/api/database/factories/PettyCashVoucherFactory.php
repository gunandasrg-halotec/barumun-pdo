<?php

namespace Database\Factories;

use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PettyCashVoucherFactory extends Factory
{
    protected $model = PettyCashVoucher::class;

    public function definition(): array
    {
        return [
            'pdo_header_id'  => PdoHeader::factory(),
            'voucher_number' => 'PCV-' . $this->faker->unique()->numerify('######'),
            'paid_to'        => $this->faker->name(),
            'voucher_date'   => now()->toDateString(),
            'status'         => PettyCashVoucher::STATUS_DRAFT,
            'total_amount'   => 0,
            'created_by'     => User::factory(),
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status'                => PettyCashVoucher::STATUS_POSTED,
            'scan_file_name'        => 'scan.pdf',
            'scan_file_path'        => 'petty-cash-voucher-scans/2026/08/' . $this->faker->uuid() . '.pdf',
            'scan_disk'             => 'local',
            'scan_mime_type'        => 'application/pdf',
            'scan_file_size_bytes'  => 123456,
            'scan_uploaded_by'      => User::factory(),
            'scan_uploaded_at'      => now(),
            'posted_at'             => now(),
            'posted_by'             => User::factory(),
        ]);
    }
}
