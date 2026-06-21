<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // Event types
    const EVENT_PDO_SUBMITTED           = 'pdo_submitted';
    const EVENT_PDO_APPROVED_ASISTEN    = 'pdo_approved_asisten';
    const EVENT_PDO_REJECTED_ASISTEN    = 'pdo_rejected';           // Asisten reject → Kerani
    const EVENT_PDO_APPROVED_MANAGER    = 'pdo_approved_manager';
    const EVENT_PDO_REJECTED_MANAGER    = 'pdo_rejected_manager';   // Manajer reject → Asisten + Kerani
    const EVENT_PDO_APPROVED_DIREKTUR   = 'pdo_approved_direktur';  // Dirkeu approve → semua
    const EVENT_PDO_REJECTED_DIREKTUR   = 'pdo_rejected_direktur';  // Dirkeu reject → semua
    const EVENT_PDO_FINAL               = 'pdo_final';
    const EVENT_PDO_CLOSED              = 'pdo_closed';
    const EVENT_SLA_REMINDER            = 'sla_reminder';
    const EVENT_MONTHLY_REMINDER        = 'monthly_reminder';

    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_SYSTEM   = 'in_system';

    protected $fillable = ['company_id', 'event_type', 'channel', 'template_body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Render template body dengan mengganti placeholder {{key}} menggunakan $variables.
     */
    public function render(array $variables): string
    {
        $body = $this->template_body;
        foreach ($variables as $key => $value) {
            // Support both {{key}} and {key} placeholder formats
            $body = str_replace(['{{' . $key . '}}', '{' . $key . '}'], $value, $body);
        }
        return $body;
    }
}
