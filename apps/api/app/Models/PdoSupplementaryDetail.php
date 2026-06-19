<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdoSupplementaryDetail extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pdo_supplementary_header_id',
        'expense_item_id',
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

    public function supplementaryHeader(): BelongsTo
    {
        return $this->belongsTo(PdoSupplementaryHeader::class, 'pdo_supplementary_header_id');
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class);
    }
}
