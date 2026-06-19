<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function plantationUnits(): HasMany
    {
        return $this->hasMany(PlantationUnit::class);
    }

    public function pdoHeaders(): HasMany
    {
        return $this->hasMany(PdoHeader::class);
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function systemSettings(): HasMany
    {
        return $this->hasMany(SystemSetting::class);
    }

    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }
}
