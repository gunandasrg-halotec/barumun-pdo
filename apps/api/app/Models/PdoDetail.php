<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    protected $appends = ['total_transferred', 'total_realized'];

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

    // ─── Global Scope (unit access) ───────────────────

    /**
     * Row-level security: KERANI and ASISTEN can only access PDO details
     * from PDOs belonging to their assigned plantation unit.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('unit_access', function (Builder $builder) {
            if (app()->bound('current_unit_id')) {
                $builder->whereHas('pdoHeader', fn ($q) =>
                    $q->where('plantation_unit_id', app('current_unit_id'))
                );
            }
        });
    }

    // ─── Relasi ───────────────────────────────────────

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
        if ($this->relationLoaded('transferEntries')) {
            return (int) $this->transferEntries->sum('amount');
        }
        return (int) $this->transferEntries()->sum('amount');
    }

    /** Total realisasi yang sudah dicatat untuk item ini. */
    public function getTotalRealizedAttribute(): int
    {
        if ($this->relationLoaded('realizationEntries')) {
            return (int) $this->realizationEntries->sum('amount');
        }
        return (int) $this->realizationEntries()->sum('amount');
    }
}
