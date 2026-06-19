<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferEntry extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const SOURCE_SYSTEM = 'system';
    const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'pdo_detail_id',
        'recorded_by',
        'entry_source',
        'is_auto_generated',
        'transfer_date',
        'amount',
        'reference_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date'    => 'date',
            'amount'           => 'integer',
            'is_auto_generated'=> 'boolean',
        ];
    }

    public function pdoDetail(): BelongsTo
    {
        return $this->belongsTo(PdoDetail::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
