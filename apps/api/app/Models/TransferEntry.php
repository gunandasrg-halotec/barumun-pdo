<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    const DEST_REK_KEBUN = 'rek_kebun';
    const DEST_PRIBADI   = 'pribadi';
    const DEST_VENDOR    = 'vendor';

    const STATUS_DRAFT     = 'draft';
    const STATUS_COMMITTED = 'committed';

    /** Nama global scope yang otomatis membatasi query hanya ke entri committed. */
    const SCOPE_COMMITTED_ONLY = 'committed_only';

    protected $fillable = [
        'pdo_detail_id',
        'recorded_by',
        'entry_source',
        'is_auto_generated',
        'transfer_date',
        'amount',
        'reference_number',
        'notes',
        'transfer_destination',
        'status',
        'committed_at',
        'committed_by',
        'is_transferred',
        'transferred_at',
        'transferred_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date'    => 'date',
            'amount'           => 'integer',
            'is_auto_generated'=> 'boolean',
            'committed_at'     => 'datetime',
            'is_transferred'   => 'boolean',
            'transferred_at'   => 'datetime',
        ];
    }

    /**
     * Global scope: SEMUA query Eloquent (termasuk accessor total_transferred
     * dan relasi transferEntries) otomatis hanya menghitung entri committed.
     * Draft hanya terlihat jika scope ini dilepas via withDrafts()/onlyDrafts().
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::SCOPE_COMMITTED_ONLY, function (Builder $builder) {
            $builder->where('transfer_entries.status', self::STATUS_COMMITTED);
        });
    }

    /** Lepas filter committed — sertakan draft + committed. */
    public function scopeWithDrafts(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::SCOPE_COMMITTED_ONLY);
    }

    /** Hanya draft. */
    public function scopeOnlyDrafts(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::SCOPE_COMMITTED_ONLY)
            ->where('transfer_entries.status', self::STATUS_DRAFT);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function pdoDetail(): BelongsTo
    {
        return $this->belongsTo(PdoDetail::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function transferredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
