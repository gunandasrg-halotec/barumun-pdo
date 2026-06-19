<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = ['name', 'code', 'description'];

    // Konstanta kode role untuk dipakai di seluruh aplikasi
    const ADMIN               = 'ADMIN';
    const KERANI              = 'KERANI';
    const ASISTEN_KEBUN       = 'ASISTEN_KEBUN';
    const MANAJER_KEBUN       = 'MANAJER_KEBUN';
    const MANAJER_KEUANGAN    = 'MANAJER_KEUANGAN';
    const STAFF_KEUANGAN      = 'STAFF_KEUANGAN';
    const DIREKTUR_KEUANGAN   = 'DIREKTUR_KEUANGAN';
    const STAFF_PURCHASING    = 'STAFF_PURCHASING';

    // Role yang approval PDO
    const APPROVER_ROLES = [
        self::ASISTEN_KEBUN,
        self::MANAJER_KEBUN,
        self::MANAJER_KEUANGAN,
        self::DIREKTUR_KEUANGAN,
    ];

    // Role yang tidak terikat ke satu unit (HO / lintas unit)
    const CROSS_UNIT_ROLES = [
        self::ADMIN,
        self::MANAJER_KEBUN,
        self::MANAJER_KEUANGAN,
        self::STAFF_KEUANGAN,
        self::DIREKTUR_KEUANGAN,
        self::STAFF_PURCHASING,
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
