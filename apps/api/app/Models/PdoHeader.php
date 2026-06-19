<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model PDO Bulanan.
 * UNIQUE constraint: (plantation_unit_id, period_month, period_year)
 * Global Scope: hanya tampilkan data unit kebun user yang login (untuk KERANI/ASISTEN)
 *
 * @property string $id
 * @property string $pdo_number
 * @property string $status  draft|submitted|reviewed_asisten|in_review_manager|in_review_direktur|final|closed
 */
class PdoHeader extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'plantation_unit_id',
        'created_by',
        'closed_by',
        'pdo_number',
        'period_month',
        'period_year',
        'submission_date',
        'status',
        'closure_type',
        'closed_at',
        'closure_notes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month'    => 'integer',
            'period_year'     => 'integer',
            'submission_date' => 'date',
            'closed_at'       => 'date',
        ];
    }

    // ─── Status constants ─────────────────────────────

    const STATUS_DRAFT              = 'draft';
    const STATUS_SUBMITTED          = 'submitted';
    const STATUS_REVIEWED_ASISTEN   = 'reviewed_asisten';
    const STATUS_IN_REVIEW_MANAGER  = 'in_review_manager';
    const STATUS_IN_REVIEW_DIREKTUR = 'in_review_direktur';
    const STATUS_FINAL              = 'final';
    const STATUS_CLOSED             = 'closed';

    // Status yang memungkinkan input realisasi dan transfer
    const WRITABLE_STATUSES = [self::STATUS_FINAL];

    // ─── Global Scope (unit access) ───────────────────

    /**
     * TAD 5.2: Row-level security untuk KERANI dan ASISTEN.
     * Middleware EnsureUnitAccess akan bind 'current_unit_id' ke container.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('unit_access', function (Builder $builder) {
            if (app()->bound('current_unit_id')) {
                $builder->where('plantation_unit_id', app('current_unit_id'));
            }
        });
    }

    // ─── Relasi ───────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plantationUnit(): BelongsTo
    {
        return $this->belongsTo(PlantationUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PdoDetail::class)->orderBy('display_order');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(PdoApprovalLog::class)->orderBy('sequence_number');
    }

    public function supplementaryHeaders(): HasMany
    {
        return $this->hasMany(PdoSupplementaryHeader::class, 'parent_pdo_header_id');
    }

    // ─── Helper methods ───────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isWritable(): bool
    {
        return in_array($this->status, self::WRITABLE_STATUSES);
    }

    /**
     * Hitung Total Transfer PDO (kumulatif semua entri transfer).
     * Digunakan untuk validasi kumulatif realisasi.
     * BR-REAL-005, FR-03A-004
     */
    public function getTotalTransferAttribute(): int
    {
        return $this->details()
            ->join('transfer_entries', 'pdo_details.id', '=', 'transfer_entries.pdo_detail_id')
            ->sum('transfer_entries.amount') ?? 0;
    }

    /**
     * Hitung Total Realisasi PDO (kumulatif semua entri realisasi).
     */
    public function getTotalRealizationAttribute(): int
    {
        return $this->details()
            ->join('realization_entries', 'pdo_details.id', '=', 'realization_entries.pdo_detail_id')
            ->sum('realization_entries.amount') ?? 0;
    }

    /**
     * Generate nomor PDO otomatis.
     * Format: PDO-YYYY-MM-{UNIT_CODE}-{NOMOR_URUT}
     * FR-03-016
     */
    public static function generateNumber(string $unitCode, int $year, int $month): string
    {
        $prefix = sprintf('PDO-%04d-%02d-%s-', $year, $month, $unitCode);

        // Cari nomor urut tertinggi untuk prefix ini
        $last = static::withoutGlobalScopes()
            ->where('pdo_number', 'like', $prefix . '%')
            ->orderByDesc('pdo_number')
            ->value('pdo_number');

        $sequence = $last
            ? ((int) substr($last, -3)) + 1
            : 1;

        return $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Hitung sequence_number berikutnya untuk approval log PDO ini.
     */
    public function nextApprovalSequence(): int
    {
        return ($this->approvalLogs()->max('sequence_number') ?? 0) + 1;
    }
}
