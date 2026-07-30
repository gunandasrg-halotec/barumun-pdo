<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitOpeningBalance extends Model
{
    use HasUuids;

    protected $fillable = ['plantation_unit_id', 'amount', 'as_of_date', 'notes', 'updated_by'];

    protected $casts = [
        'amount'     => 'integer',
        'as_of_date' => 'date',
    ];

    public function plantationUnit(): BelongsTo
    {
        return $this->belongsTo(PlantationUnit::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Saldo awal kas kebun untuk unit tertentu (0 kalau belum diset). */
    public static function amountForUnit(string $unitId): int
    {
        return (int) static::where('plantation_unit_id', $unitId)->value('amount');
    }
}
