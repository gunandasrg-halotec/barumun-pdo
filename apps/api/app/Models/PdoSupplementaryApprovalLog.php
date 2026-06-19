<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdoSupplementaryApprovalLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    const ACTION_SUBMIT   = 'submit';
    const ACTION_APPROVE  = 'approve';
    const ACTION_REJECT   = 'reject';
    const ACTION_RESUBMIT = 'resubmit';

    protected $fillable = [
        'pdo_supplementary_header_id',
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

    public function supplementaryHeader(): BelongsTo
    {
        return $this->belongsTo(PdoSupplementaryHeader::class, 'pdo_supplementary_header_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
