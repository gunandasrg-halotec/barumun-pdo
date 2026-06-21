<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\PdoHeader;

class ExpenseItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // BR-MASTER-006: nilai valid mode_input
    const MODE_MANUAL        = 'manual';
    const MODE_AUTO_EXTERNAL = 'auto_external';

    protected $fillable = [
        'subcategory_id',
        'code',
        'name',
        'default_account_number',
        'default_unit',
        'default_rate',
        'mode_input',
        'is_routine',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_rate' => 'integer',
            'is_routine'   => 'boolean',
            'is_active'    => 'boolean',
            'deleted_at'   => 'datetime',
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
