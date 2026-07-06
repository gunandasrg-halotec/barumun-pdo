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

    private const EXTERNAL_FINGERPRINT_FIELDS = [
        'source_system',
        'component',
        'component_keys',
        'role',
        'block_keys',
    ];

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $appends = [
        'total_transferred',
        'total_realized',
        'is_auto_external_active',
        'needs_pull',
        'is_stale_external_snapshot',
        'is_external_read_only',
    ];

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
        'external_source_system',
        'external_component',
        'external_component_key',
        'external_component_keys',
        'external_block_keys',
        'external_amount_pulled_at',
        'external_payload',
        'notes',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'float',
            'rate'          => 'integer',
            'amount'        => 'integer',
            'external_amount_pulled_at' => 'datetime',
            'external_payload' => 'array',
            'external_component_keys' => 'array',
            'external_block_keys' => 'array',
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

    public function sourceSupplementary(): BelongsTo
    {
        return $this->belongsTo(PdoSupplementaryHeader::class, 'source_pdo_supplementary_id');
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

    public function attachments(): HasMany
    {
        return $this->hasMany(PdoDetailAttachment::class);
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

    public function getIsAutoExternalActiveAttribute(): bool
    {
        return $this->currentExpenseItem()?->mode_input === ExpenseItem::MODE_AUTO_EXTERNAL;
    }

    public function getNeedsPullAttribute(): bool
    {
        return $this->isDraftExternalRow()
            && (! $this->hasSuccessfulExternalSnapshot() || $this->is_stale_external_snapshot);
    }

    public function getIsStaleExternalSnapshotAttribute(): bool
    {
        if (! $this->isDraftExternalRow() || ! $this->hasSuccessfulExternalSnapshot()) {
            return false;
        }

        $storedFingerprint = $this->storedExternalMappingFingerprint();

        if ($this->isLegacyExternalSnapshot($storedFingerprint)) {
            return false;
        }

        return $storedFingerprint !== $this->currentExternalMappingFingerprint();
    }

    public function getIsExternalReadOnlyAttribute(): bool
    {
        return $this->isDraftExternalRow();
    }

    public function currentExternalMappingFingerprint(): array
    {
        $item = $this->currentExpenseItem();

        if (! $item instanceof ExpenseItem) {
            return [];
        }

        return [
            'source_system' => $item->external_source_system,
            'component' => $item->external_component,
            'component_keys' => $this->resolveCanonicalExternalComponentKeys($item),
            'role' => ExpenseItem::supportsPayrollRole($item->external_component) ? $this->normalizeExternalComponentKey($item->external_role) : null,
            'block_keys' => $item->resolveExternalBlockKeysForPlantationUnit($this->currentPdoHeader()?->plantation_unit_id),
        ];
    }

    private function resolveCanonicalExternalComponentKeys(?ExpenseItem $item): ?array
    {
        if (! $item instanceof ExpenseItem) {
            return null;
        }

        $keys = $this->normalizeStringList($item->external_component_keys);

        if ($keys !== null) {
            return $keys;
        }

        if (filled($item->external_component_key)) {
            return [$item->external_component_key];
        }

        return null;
    }

    public function storedExternalMappingFingerprint(): array
    {
        $payload = is_array($this->external_payload) ? $this->external_payload : [];

        return [
            'source_system' => $payload['source_system'] ?? null,
            'component' => $payload['component'] ?? null,
            'component_keys' => $this->normalizeStringList(
                $payload['component_keys']
                    ?? (filled($payload['component_key'] ?? null) ? [$payload['component_key']] : null)
            ),
            'role' => $this->normalizeExternalComponentKey($payload['role'] ?? null),
            'block_keys' => $this->normalizeStringList($payload['block_keys'] ?? null),
        ];
    }

    public function hasSuccessfulExternalSnapshot(): bool
    {
        return $this->external_amount_pulled_at !== null
            && is_array($this->external_payload);
    }

    private function isLegacyExternalSnapshot(array $storedFingerprint): bool
    {
        foreach ($storedFingerprint as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    private function normalizeStringList(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized === [] ? null : $normalized;
    }

    private function normalizeExternalComponentKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function currentExpenseItem(): ?ExpenseItem
    {
        if ($this->relationLoaded('expenseItem')) {
            return $this->expenseItem;
        }

        return $this->expenseItem()->first();
    }

    private function currentPdoHeader(): ?PdoHeader
    {
        if ($this->relationLoaded('pdoHeader')) {
            return $this->pdoHeader;
        }

        return $this->pdoHeader()->first();
    }

    private function isDraftExternalRow(): bool
    {
        return $this->getIsAutoExternalActiveAttribute()
            && $this->currentPdoHeader()?->isDraft() === true;
    }
}
