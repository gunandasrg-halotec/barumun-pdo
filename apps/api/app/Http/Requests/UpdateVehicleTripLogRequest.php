<?php

namespace App\Http\Requests;

use App\Models\VehicleTripLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleTripLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canRecordRealization() ?? false;
    }

    public function rules(): array
    {
        return [
            'trip_date'   => ['sometimes', 'date'],
            'driver_name' => ['sometimes', 'string', 'max:150'],
            'trip_count'  => ['sometimes', 'integer', 'min:1'],
            'trip_type'   => ['sometimes', Rule::in([VehicleTripLog::TRIP_TYPE_ANGKUT_TBS, VehicleTripLog::TRIP_TYPE_PERAWATAN])],
            'destination' => ['sometimes', 'string', 'max:150'],
            'weight'      => ['sometimes', 'integer', 'min:1', 'max:21'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
