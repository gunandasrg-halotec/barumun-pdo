<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseSubcategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'display_order',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active'     => 'boolean',
            'deleted_at'    => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'subcategory_id');
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'subcategory_id')
            ->where('is_active', true)
            ->whereNull('deleted_at');
    }
}
