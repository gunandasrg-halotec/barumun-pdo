<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdoDetail extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pdo_header_id',
        'expense_item_id',
        'source_pdo_supplementary_id',
        'account_number',
        'description',
        'quantity',
        'unit',
        'rate',
        'amount',
        'notes',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'float',
            'rate'          => 'integer',
            'amount'        => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function pdoHeader(): BelongsTo
    {
        return $this->belongsTo(PdoHeader::class);
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class);
    }

    public function transferEntries(): HasMany
    {
        return $this->hasMany(TransferEntry::class);
    }

    public function realizationEntries(): HasMany
    {
        return $this->hasMany(RealizationEntry::class);
    }

    /** Total transfer yang sudah masuk ke item ini. */
    public function getTotalTransferredAttribute(): int
    {
        return (int) $this->transferEntries()->sum('amount');
    }

    /** Total realisasi yang sudah dicatat untuk item ini. */
    public function getTotalRealizedAttribute(): int
    {
        return (int) $this->realizationEntries()->sum('amount');
    }
}
