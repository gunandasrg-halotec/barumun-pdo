<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * petty_cash_voucher di-append secara global (pola sama dengan PdoDetail).
     * Pemanggil yang menampilkan banyak entri sekaligus (list()) WAJIB eager-load
     * pettyCashVoucherLine.voucher, kalau tidak accessor ini jatuh ke lazy load per-baris.
     */
    protected $appends = [
        'petty_cash_voucher',
    ];

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
        'is_auto_generated',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date'       => 'date',
            'amount'                 => 'integer',
            'exported_to_journal_at' => 'datetime',
            'is_auto_generated'      => 'boolean',
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

    public function pettyCashVoucherLine(): HasOne
    {
        return $this->hasOne(PettyCashVoucherLine::class, 'realization_entry_id');
    }

    /** Ringkasan voucher (id + nomor) untuk keterlacakan, atau null jika bukan hasil voucher. */
    public function getPettyCashVoucherAttribute(): ?array
    {
        $voucher = $this->pettyCashVoucherLine?->pettyCashVoucher;

        if (! $voucher) {
            return null;
        }

        return [
            'id'             => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
        ];
    }
}
