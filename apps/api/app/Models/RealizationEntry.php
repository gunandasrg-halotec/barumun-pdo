<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealizationEntry extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const PAYMENT_TUNAI    = 'tunai';
    const PAYMENT_TRANSFER = 'transfer';
    const PAYMENT_KAS_KECIL= 'kas_kecil';

    const FUNDING_KAS_KEBUN      = 'kas_kebun';
    const FUNDING_REKENING_KEBUN = 'rekening_kebun';
    const FUNDING_REKENING_UTAMA = 'rekening_utama';

    const SETTLEMENT_KEBUN          = 'kebun';
    const SETTLEMENT_PRIBADI_VENDOR = 'pribadi_vendor';

    protected $fillable = [
        'pdo_detail_id',
        'vehicle_id',
        'recorded_by',
        'transaction_date',
        'amount',
        'payment_method',
        'proof_number',
        'funding_source',
        'explanation',
        'settlement_group',
        'exported_to_journal_at',
        'exported_to_journal_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date'       => 'date',
            'amount'                 => 'integer',
            'exported_to_journal_at' => 'datetime',
        ];
    }

    public function pdoDetail(): BelongsTo
    {
        return $this->belongsTo(PdoDetail::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RealizationAttachment::class);
    }

    public function journalExporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_to_journal_by');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
