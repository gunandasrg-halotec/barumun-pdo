<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class VehicleSparepartWatermark extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'vehicle_id',
        'period_month',
        'period_year',
        'watermark_date',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month'   => 'integer',
            'period_year'    => 'integer',
            'watermark_date' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Tanggal terakhir yang sudah dibebankan (inklusif) untuk kendaraan +
     * periode ini, atau null jika belum pernah diproses sama sekali.
     */
    public static function lastCoveredDate(string $vehicleId, int $periodYear, int $periodMonth): ?Carbon
    {
        return self::where('vehicle_id', $vehicleId)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->value('watermark_date');
    }

    /**
     * Majukan watermark ke tanggal tertentu (hanya jika lebih maju dari yang
     * sudah ada). Dipanggil hanya saat CSV benar-benar diunduh, bukan saat preview.
     */
    public static function advance(string $vehicleId, int $periodYear, int $periodMonth, Carbon $date, ?User $actor): void
    {
        $existing = self::where('vehicle_id', $vehicleId)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->first();

        if ($existing && $existing->watermark_date->greaterThanOrEqualTo($date)) {
            return;
        }

        self::updateOrCreate(
            ['vehicle_id' => $vehicleId, 'period_year' => $periodYear, 'period_month' => $periodMonth],
            ['watermark_date' => $date, 'updated_by' => $actor?->id]
        );
    }
}
