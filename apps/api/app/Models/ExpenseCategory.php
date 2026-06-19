<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'display_order',
        'include_in_recap',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'display_order'    => 'integer',
            'include_in_recap' => 'boolean',
            'is_active'        => 'boolean',
            'deleted_at'       => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(ExpenseSubcategory::class, 'category_id');
    }

    public function activeSubcategories(): HasMany
    {
        return $this->hasMany(ExpenseSubcategory::class, 'category_id')
            ->where('is_active', true)
            ->whereNull('deleted_at');
    }
}
