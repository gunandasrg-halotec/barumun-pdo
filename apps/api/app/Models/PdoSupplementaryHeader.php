<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdoSupplementaryHeader extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const STATUS_DRAFT              = 'draft';
    const STATUS_SUBMITTED          = 'submitted';
    const STATUS_REVIEWED_ASISTEN   = 'reviewed_asisten';
    const STATUS_IN_REVIEW_MANAGER  = 'in_review_manager';
    const STATUS_IN_REVIEW_DIREKTUR = 'in_review_direktur';
    const STATUS_FINAL_MERGED       = 'final_merged';
    const STATUS_REJECTED           = 'rejected';

    protected $fillable = [
        'parent_pdo_header_id',
        'company_id',
        'plantation_unit_id',
        'created_by',
        'pdo_number',
        'period_month',
        'period_year',
        'submission_date',
        'status',
        'manager_kebun_approved',
        'manager_keuangan_approved',
        'merged_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month'              => 'integer',
            'period_year'               => 'integer',
            'submission_date'           => 'date',
            'manager_kebun_approved'    => 'boolean',
            'manager_keuangan_approved' => 'boolean',
            'merged_at'                 => 'datetime',
        ];
    }

    public function parentPdo(): BelongsTo
    {
        return $this->belongsTo(PdoHeader::class, 'parent_pdo_header_id');
    }

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

    public function details(): HasMany
    {
        return $this->hasMany(PdoSupplementaryDetail::class, 'pdo_supplementary_header_id')
            ->orderBy('display_order');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(PdoSupplementaryApprovalLog::class, 'pdo_supplementary_header_id')
            ->orderBy('sequence_number');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isMerged(): bool
    {
        return $this->status === self::STATUS_FINAL_MERGED;
    }

    public function nextApprovalSequence(): int
    {
        return ($this->approvalLogs()->max('sequence_number') ?? 0) + 1;
    }

    /**
     * Generate nomor PDO Tambahan.
     * Format: PDOT-YYYY-MM-{UNIT_CODE}-{SEQ}
     */
    public static function generateNumber(string $unitCode, int $year, int $month): string
    {
        $prefix = sprintf('PDOT-%04d-%02d-%s-', $year, $month, $unitCode);

        $last = static::where('pdo_number', 'like', $prefix . '%')
            ->orderByDesc('pdo_number')
            ->value('pdo_number');

        $sequence = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
