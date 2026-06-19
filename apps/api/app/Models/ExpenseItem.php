<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // BR-MASTER-006: nilai valid mode_input
    const MODE_MANUAL        = 'manual';
    const MODE_AUTO_EXTERNAL = 'auto_external';

    protected $fillable = [
        'subcategory_id',
        'code',
        'name',
        'default_account_number',
        'default_unit',
        'default_rate',
        'mode_input',
        'is_routine',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_rate' => 'integer',
            'is_routine'   => 'boolean',
            'is_active'    => 'boolean',
            'deleted_at'   => 'datetime',
        ];
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'subcategory_id');
    }

    /**
     * Cek apakah item ini sudah pernah dipakai di PDO (pdo_details).
     * Dipakai untuk BR-MASTER-004.
     */
    public function isUsedInPdo(): bool
    {
        return \DB::table('pdo_details')
            ->where('expense_item_id', $this->id)
            ->exists();
    }
}
