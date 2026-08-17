<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashVoucher extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const STATUS_DRAFT  = 'draft';
    const STATUS_POSTED = 'posted';

    protected $fillable = [
        'pdo_header_id',
        'voucher_number',
        'paid_to',
        'voucher_date',
        'status',
        'total_amount',
        'created_by',
        'scan_file_name',
        'scan_file_path',
        'scan_disk',
        'scan_mime_type',
        'scan_file_size_bytes',
        'scan_uploaded_by',
        'scan_uploaded_at',
        'posted_at',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date'          => 'date',
            'total_amount'          => 'integer',
            'scan_file_size_bytes'  => 'integer',
            'scan_uploaded_at'      => 'datetime',
            'posted_at'             => 'datetime',
        ];
    }

    // ─── Global Scope (unit access) ───────────────────

    /**
     * Row-level security: sama seperti PdoDetail — KERANI/ASISTEN hanya
     * boleh akses voucher milik PDO dari unit yang boleh diakses actor.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('unit_access', function (Builder $builder) {
            if (app()->bound('current_unit_ids')) {
                $builder->whereHas('pdoHeader', fn ($q) =>
                    $q->whereIn('plantation_unit_id', app('current_unit_ids'))
                );
            }
        });
    }

    // ─── Relasi ───────────────────────────────────────

    public function pdoHeader(): BelongsTo
    {
        return $this->belongsTo(PdoHeader::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scanUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scan_uploaded_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PettyCashVoucherLine::class)->orderBy('line_no');
    }

    // ─── Helpers ────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function hasScan(): bool
    {
        return $this->scan_file_path !== null;
    }
}
