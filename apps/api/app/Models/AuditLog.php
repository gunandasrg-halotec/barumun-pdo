<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Audit trail system-wide. Append-only — tidak ada UPDATE atau DELETE.
 * BRD BR-NOTIF-005, BR-NOTIF-006
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'actor_user_id',
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relasi ───────────────────────────────────────────

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ─── Static helper: cara mudah menulis audit log ──────

    /**
     * Catat satu entri audit log.
     *
     * Penggunaan:
     *   AuditLog::record(actor: $user, entityType: 'pdo_headers', entityId: $pdo->id, action: 'STATUS_CHANGE',
     *                    oldValues: ['status' => 'draft'], newValues: ['status' => 'submitted']);
     */
    public static function record(
        ?User $actor,
        string $entityType,
        string $entityId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): self {
        $request = app(Request::class);

        return self::create([
            'actor_user_id' => $actor?->id,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'action'        => $action,
            'old_values'    => $oldValues,
            'new_values'    => $newValues,
            'ip_address'    => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);
    }
}
