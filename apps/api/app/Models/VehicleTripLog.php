<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleTripLog extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    const TRIP_TYPE_ANGKUT_TBS = 'angkut_tbs';

    const TRIP_TYPE_PERAWATAN = 'perawatan';

    protected $fillable = [
        'pdo_header_id',
        'vehicle_id',
        'trip_date',
        'driver_name',
        'trip_count',
        'trip_type',
        'destination',
        'weight',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'trip_date'  => 'date',
            'trip_count' => 'integer',
            'weight'     => 'integer',
        ];
    }

    public function pdoHeader(): BelongsTo
    {
        return $this->belongsTo(PdoHeader::class, 'pdo_header_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Hitung rasio pemakaian 1 "tangki" (window antar realisasi pembelian
     * berurutan untuk kendaraan yang sama, lintas PDO/unit — FIFO).
     *
     * Rasio dibobot berdasarkan trip_count × weight (weight = estimasi
     * jarak per kelipatan 5km, 1 = 0-5km), bukan trip_count polos — trip
     * yang lebih jauh (mis. ke PKS) membebankan porsi BBM lebih besar
     * daripada trip pendek (mis. ke RAM), meski sama-sama masuk kategori
     * "Angkut TBS".
     *
     * Mengembalikan daftar kelompok (pdo_header_id, trip_type) beserta
     * weighted_trip_count dan rasio terhadap total dalam window tsb. Trip
     * yang dicatat oleh unit lain (bukan unit pembeli) tetap ikut dihitung,
     * karena kendaraan bisa berpindah unit sebelum tangki berikutnya dibeli.
     *
     * @return array{total_trips: int, groups: array<int, array{pdo_header_id: string, trip_type: string, trip_count: int, ratio: float}>}|null
     *         null jika tidak ada trip log sama sekali dalam window
     */
    public static function usageSplitForWindow(string $vehicleId, \Illuminate\Support\Carbon $windowStart, ?\Illuminate\Support\Carbon $windowEnd): ?array
    {
        $query = self::where('vehicle_id', $vehicleId)
            ->where('trip_date', '>=', $windowStart->toDateString());

        if ($windowEnd) {
            $query->where('trip_date', '<', $windowEnd->toDateString());
        }

        $totals = $query
            ->selectRaw('pdo_header_id, trip_type, SUM(trip_count * weight) as total')
            ->groupBy('pdo_header_id', 'trip_type')
            ->orderBy('pdo_header_id')
            ->orderBy('trip_type')
            ->get();

        $grandTotal = (int) $totals->sum('total');
        if ($grandTotal === 0) {
            return null;
        }

        $groups = $totals->map(fn ($row) => [
            'pdo_header_id' => $row->pdo_header_id,
            'trip_type'     => $row->trip_type,
            'trip_count'    => (int) $row->total,
            'ratio'         => ((int) $row->total) / $grandTotal,
        ])->values()->all();

        return ['total_trips' => $grandTotal, 'groups' => $groups];
    }

    /**
     * Ambil weight terakhir yang tercatat untuk destination tertentu, dalam
     * lingkup unit kebun tertentu (bukan lintas kebun — jarak riil dari
     * kebun yang beda ke tempat bernama sama bisa berbeda). Dipakai utk
     * mendeteksi jika kerani mengisi jarak yang berbeda dari catatan
     * sebelumnya ke destination yang sama.
     */
    public static function lastWeightForDestination(string $plantationUnitId, string $destination): ?array
    {
        $log = self::query()
            ->whereHas('pdoHeader', fn ($q) => $q->where('plantation_unit_id', $plantationUnitId))
            ->whereRaw('LOWER(TRIM(destination)) = ?', [mb_strtolower(trim($destination))])
            ->orderByDesc('trip_date')
            ->orderByDesc('created_at')
            ->first();

        if (! $log) {
            return null;
        }

        return ['weight' => $log->weight, 'trip_date' => $log->trip_date->toDateString()];
    }
}
