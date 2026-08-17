<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashVoucherLine extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'petty_cash_voucher_id',
        'pdo_detail_id',
        'vehicle_id',
        'line_no',
        'description',
        'amount',
        'realization_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'amount'  => 'integer',
        ];
    }

    public function pettyCashVoucher(): BelongsTo
    {
        return $this->belongsTo(PettyCashVoucher::class, 'petty_cash_voucher_id');
    }

    public function pdoDetail(): BelongsTo
    {
        return $this->belongsTo(PdoDetail::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function realizationEntry(): BelongsTo
    {
        return $this->belongsTo(RealizationEntry::class);
    }
}
