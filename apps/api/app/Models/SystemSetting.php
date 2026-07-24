<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSetting extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const UPDATED_AT = 'updated_at';

    // Key constants — dipakai di seluruh Service agar tidak typo
    const KEY_THRESHOLD_PROOF       = 'threshold_proof_amount';
    const KEY_THRESHOLD_EXPLANATION = 'threshold_explanation_amount';
    const KEY_WA_GATEWAY_URL        = 'wa_gateway_url';
    const KEY_WA_GATEWAY_USERNAME   = 'wa_gateway_username';
    const KEY_WA_GATEWAY_PASSWORD   = 'wa_gateway_password';
    const KEY_WA_GATEWAY_DEVICE_ID  = 'wa_gateway_device_id';
    const KEY_REMINDER_DAY          = 'reminder_day_of_month';
    const KEY_REMINDER_HOUR         = 'reminder_hour';

    protected $fillable = ['company_id', 'key', 'value', 'description', 'updated_by'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Ambil nilai setting per key untuk company tertentu. */
    public static function getValue(string $companyId, string $key, mixed $default = null): mixed
    {
        return static::where('company_id', $companyId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }
}
