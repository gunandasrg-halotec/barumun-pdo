<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdoApprovalLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    // Nilai valid action (sesuai DB constraint)
    const ACTION_SUBMIT   = 'submit';
    const ACTION_APPROVE  = 'approve';
    const ACTION_REJECT   = 'reject';
    const ACTION_RESUBMIT = 'resubmit';
    const ACTION_CLOSE    = 'close';

    protected $fillable = [
        'pdo_header_id',
        'actor_user_id',
        'approval_stage',
        'action',
        'reason',
        'sequence_number',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'created_at'      => 'datetime',
        ];
    }

    public function pdoHeader(): BelongsTo
    {
        return $this->belongsTo(PdoHeader::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
