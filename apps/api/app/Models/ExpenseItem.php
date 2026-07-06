<?php

namespace App\Models;

use App\Casts\PgUuidArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    // BR-MASTER-006: nilai valid mode_input
    const MODE_MANUAL = 'manual';

    const MODE_AUTO_EXTERNAL = 'auto_external';

    const EXTERNAL_SOURCE_PAYROLL = 'payroll';

    const PAYROLL_COMPONENT_HARVEST_TBS_TOTAL = 'harvest_tbs_total';

    const PAYROLL_COMPONENT_RELAYED_TBS_TOTAL = 'relayed_tbs_total';

    const PAYROLL_COMPONENT_HARVEST_BONUS_TOTAL = 'harvest_bonus_total';

    const PAYROLL_COMPONENT_LOOSE_FRUIT_TOTAL = 'loose_fruit_total';

    const PAYROLL_COMPONENT_MAINTENANCE_TOTAL = 'maintenance_total';

    const PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL = 'base_payroll_total';

    const PAYROLL_COMPONENT_ADDITIONAL_WAGES_TOTAL = 'additional_wages_total';

    const PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL = 'additional_wage_type_total';

    const PAYROLL_ROLE_PEMANEN = 'pemanen';

    const PAYROLL_ROLE_BHL = 'bhl';

    const PAYROLL_ROLE_SUPIR = 'supir';

    const PAYROLL_ROLE_PEGAWAI = 'pegawai';

    /** @var array<int,string> */
    public const PAYROLL_COMPONENT_OPTIONS = [
        self::PAYROLL_COMPONENT_HARVEST_TBS_TOTAL,
        self::PAYROLL_COMPONENT_RELAYED_TBS_TOTAL,
        self::PAYROLL_COMPONENT_HARVEST_BONUS_TOTAL,
        self::PAYROLL_COMPONENT_LOOSE_FRUIT_TOTAL,
        self::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
        self::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
        self::PAYROLL_COMPONENT_ADDITIONAL_WAGES_TOTAL,
        self::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
    ];

    /** @var array<int,string> */
    public const PAYROLL_ROLE_OPTIONS = [
        self::PAYROLL_ROLE_PEMANEN,
        self::PAYROLL_ROLE_BHL,
        self::PAYROLL_ROLE_SUPIR,
        self::PAYROLL_ROLE_PEGAWAI,
    ];

    /** @var array<int,string> */
    public const PAYROLL_COMPONENTS_WITH_EXTERNAL_OPTIONS = [
        self::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
        self::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
        self::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
    ];

    protected $fillable = [
        'subcategory_id',
        'code',
        'name',
        'default_account_number',
        'default_unit',
        'default_rate',
        'mode_input',
        'external_source_system',
        'external_component',
        'external_component_key',
        'external_component_keys',
        'external_block_keys',
        'external_role',
        'split_transfer',
        'split_transfer_plantation_unit_ids',
        'is_routine',
        'routine_plantation_unit_ids',
        'is_active',
        'is_deduction',
        'notes',
    ];

    public static function payrollComponents(): array
    {
        return self::PAYROLL_COMPONENT_OPTIONS;
    }

    public static function payrollSourceSystems(): array
    {
        return [self::EXTERNAL_SOURCE_PAYROLL];
    }

    public static function payrollRoles(): array
    {
        return self::PAYROLL_ROLE_OPTIONS;
    }

    public static function requiresComponentKey(string $component): bool
    {
        return $component === self::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL;
    }

    public static function supportsExternalOption(string $component): bool
    {
        return in_array($component, self::PAYROLL_COMPONENTS_WITH_EXTERNAL_OPTIONS, true);
    }

    public static function supportsPayrollRole(?string $component): bool
    {
        return $component === self::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL;
    }

    public static function optionedPayrollComponents(): array
    {
        return self::PAYROLL_COMPONENTS_WITH_EXTERNAL_OPTIONS;
    }

    public static function allowsEmptyExternalComponentKey(?string $component): bool
    {
        return in_array($component, [
            self::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            self::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
        ], true);
    }

    public static function supportsSelectorSets(?string $component): bool
    {
        return in_array($component, [
            self::PAYROLL_COMPONENT_BASE_PAYROLL_TOTAL,
            self::PAYROLL_COMPONENT_MAINTENANCE_TOTAL,
            self::PAYROLL_COMPONENT_ADDITIONAL_WAGE_TYPE_TOTAL,
        ], true);
    }

    public static function supportsMaintenanceBlockSelectors(?string $component): bool
    {
        return $component === self::PAYROLL_COMPONENT_MAINTENANCE_TOTAL;
    }

    protected function casts(): array
    {
        return [
            'default_rate' => 'integer',
            'split_transfer' => 'boolean',
            'external_component_keys' => 'array',
            'external_block_keys' => 'array',
            'split_transfer_plantation_unit_ids' => PgUuidArray::class,
            'is_routine' => 'boolean',
            'is_active' => 'boolean',
            'is_deduction' => 'boolean',
            'routine_plantation_unit_ids' => PgUuidArray::class,
            'deleted_at' => 'datetime',
        ];
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'subcategory_id');
    }

    /**
     * Cek apakah item ini sudah pernah dipakai di PDO mana pun (termasuk closed).
     * Dipakai untuk BR-MASTER-004.
     */
    public function isUsedInPdo(): bool
    {
        return \DB::table('pdo_details')
            ->where('expense_item_id', $this->id)
            ->exists();
    }

    /**
     * Cek apakah item dipakai di PDO yang aktif (semua status kecuali closed).
     * Jika true, item tidak boleh dihapus sama sekali.
     */
    public function isUsedInActivePdo(): bool
    {
        return \DB::table('pdo_details')
            ->join('pdo_headers', 'pdo_details.pdo_header_id', '=', 'pdo_headers.id')
            ->where('pdo_details.expense_item_id', $this->id)
            ->where('pdo_headers.status', '!=', PdoHeader::STATUS_CLOSED)
            ->exists();
    }
}
