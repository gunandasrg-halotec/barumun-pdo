<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'nomor_polisi',
        'nama',
        'expense_item_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class, 'expense_item_id');
    }

    public function realizationEntries(): HasMany
    {
        return $this->hasMany(RealizationEntry::class, 'vehicle_id');
    }

    public function tripLogs(): HasMany
    {
        return $this->hasMany(VehicleTripLog::class, 'vehicle_id');
    }

    public function isUsedInRealization(): bool
    {
        return \DB::table('realization_entries')
            ->where('vehicle_id', $this->id)
            ->exists();
    }
}
