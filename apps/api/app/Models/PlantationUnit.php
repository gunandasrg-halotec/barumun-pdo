<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantationUnit extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'linked_unit_id',
        'code',
        'name',
        'payroll_estate_external_id',
        'account_code_kas_kebun',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pdoHeaders(): HasMany
    {
        return $this->hasMany(PdoHeader::class);
    }

    /**
     * Unit kedua yang otomatis accessible untuk KERANI/ASISTEN_KEBUN yang
     * terikat ke unit ini — mis. "Sosa Replanting" di-link dari "Sosa".
     */
    public function linkedUnit(): BelongsTo
    {
        return $this->belongsTo(PlantationUnit::class, 'linked_unit_id');
    }
}
