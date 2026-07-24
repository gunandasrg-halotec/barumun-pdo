<?php

namespace App\Http\Requests;

use App\Models\PdoHeader;
use App\Models\Vehicle;
use App\Models\VehicleTripLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleTripLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordRealization() ?? false;
    }

    public function rules(): array
    {
        return [
            'pdo_header_id' => ['required', 'uuid', 'exists:pdo_headers,id'],
            'vehicle_id'    => ['required', 'uuid', 'exists:vehicles,id'],
            'trip_date'     => ['required', 'date'],
            'driver_name'   => ['required', 'string', 'max:150'],
            'trip_count'    => ['required', 'integer', 'min:1'],
            'trip_type'     => ['required', Rule::in([VehicleTripLog::TRIP_TYPE_ANGKUT_TBS, VehicleTripLog::TRIP_TYPE_PERAWATAN])],
            'destination'   => ['required', 'string', 'max:150'],
            'weight'        => ['required', 'integer', 'min:1', 'max:21'],
            'notes'         => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $vehicleId = $this->input('vehicle_id');
            if ($vehicleId) {
                $vehicle = Vehicle::find($vehicleId);
                if ($vehicle && ! $vehicle->is_active) {
                    $validator->errors()->add('vehicle_id', 'Kendaraan yang dipilih tidak aktif.');
                }
            }

            $pdoHeaderId = $this->input('pdo_header_id');
            $destination = $this->input('destination');
            $weight      = $this->input('weight');

            if (! $pdoHeaderId || ! $destination || ! $weight) {
                return;
            }

            $pdoHeader = PdoHeader::find($pdoHeaderId);
            if (! $pdoHeader) {
                return;
            }

            $last = VehicleTripLog::lastWeightForDestination($pdoHeader->plantation_unit_id, $destination);

            // Hanya dianggap mismatch kalau jarak BARU lebih besar dari catatan
            // sebelumnya. Jarak lebih kecil valid tanpa peringatan — trip pulang-
            // pergi bisa dipecah jadi 2 entry per arah (masing2 dgn weight lebih
            // kecil dari 1 entry gabungan pulang-pergi).
            if ($last && (int) $weight > (int) $last['weight'] && ! trim((string) $this->input('notes'))) {
                $validator->errors()->add(
                    'notes',
                    "Jarak ke \"{$destination}\" sebelumnya tercatat lebih kecil (terakhir {$last['weight']} pada {$last['trip_date']}). ".
                    'Jelaskan alasan jarak kali ini lebih besar di kolom Keterangan.'
                );
            }
        });
    }
}
