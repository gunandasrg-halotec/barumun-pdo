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
        return $this->hasAnyRole(['MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN']);
    }

    /**
     * Role yang bisa simpan permanen (commit) rencana transfer — hanya Direktur Keuangan,
     * sebagai persetujuan akhir sebelum dana benar-benar ditransfer.
     */
    public function canCommitTransfer(): bool
    {
        return $this->hasAnyRole(['DIREKTUR_KEUANGAN']);
    }

    /**
     * Role yang bisa menandai instruksi transfer sebagai sudah dieksekusi (dana benar-benar dikirim).
     */
    public function canMarkTransferExecuted(): bool
    {
        return $this->hasAnyRole(['STAFF_PURCHASING', 'MANAJER_KEUANGAN', 'DIREKTUR_KEUANGAN']);
    }

    /**
     * Role yang bisa mencatat realisasi.
     */
    public function canRecordRealization(): bool
    {
        return $this->hasAnyRole(['KERANI', 'STAFF_PURCHASING', 'MANAJER_KEUANGAN']);
    }

    /**
     * Role yang bisa export realisasi ke jurnal umum (Jurnal by Mekari).
     */
    public function canExportJournal(): bool
    {
        return $this->hasAnyRole(['STAFF_KEUANGAN', 'MANAJER_KEUANGAN', 'DIREKTUR_KEUANGAN']);
    }

    /**
     * BR-REAL-005: kantong realisasi yang boleh dipakai role ini.
     * KERANI → kantong rek_kebun. STAFF_PURCHASING & MANAJER_KEUANGAN →
     * kantong pribadi+vendor. Role lain → null (tidak boleh realisasi).
     */
    public function realizationSettlementGroup(): ?string
    {
        if ($this->hasRole('KERANI')) {
            return RealizationEntry::SETTLEMENT_KEBUN;
        }
        if ($this->hasAnyRole(['STAFF_PURCHASING', 'MANAJER_KEUANGAN'])) {
            return RealizationEntry::SETTLEMENT_PRIBADI_VENDOR;
        }
        return null;
    }
}
