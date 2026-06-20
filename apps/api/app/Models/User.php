<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'role_id',
        'plantation_unit_id',
        'company_id',
        'full_name',
        'email',
        'password_hash',
        'whatsapp_number',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    // Gunakan password_hash sebagai kolom password untuk Sanctum
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'last_login_at' => 'datetime',
            'deleted_at'    => 'datetime',
        ];
    }

    // ─── Relasi ───────────────────────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function plantationUnit(): BelongsTo
    {
        return $this->belongsTo(PlantationUnit::class);
    }

    public function createdPdoHeaders(): HasMany
    {
        return $this->hasMany(PdoHeader::class, 'created_by');
    }

    // ─── Helper methods ───────────────────────────────────

    public function hasRole(string $roleCode): bool
    {
        return $this->role?->code === $roleCode;
    }

    public function hasAnyRole(array $roleCodes): bool
    {
        return in_array($this->role?->code, $roleCodes);
    }

    /**
     * Role yang terikat ke satu unit kebun saja.
     * BR-PDO: Kerani dan Asisten hanya akses data unit mereka.
     */
    public function isUnitBound(): bool
    {
        return $this->hasAnyRole(['KERANI', 'ASISTEN_KEBUN']);
    }

    /**
     * Role yang bisa melakukan approval PDO.
     */
    public function canApprove(): bool
    {
        return $this->hasAnyRole([
            'ASISTEN_KEBUN',
            'MANAJER_KEBUN',
            'MANAJER_KEUANGAN',
            'DIREKTUR_KEUANGAN',
        ]);
    }

    /**
     * Role yang bisa mencatat transfer.
     */
    public function canRecordTransfer(): bool
    {
        return $this->hasAnyRole(['MANAJER_KEUANGAN', 'STAFF_KEUANGAN']);
    }

    /**
     * Role yang bisa mencatat realisasi.
     */
    public function canRecordRealization(): bool
    {
        return $this->hasAnyRole(['KERANI', 'STAFF_PURCHASING']);
    }
}
