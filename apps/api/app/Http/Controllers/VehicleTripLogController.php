<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleTripLogRequest;
use App\Http\Requests\UpdateVehicleTripLogRequest;
use App\Models\PdoHeader;
use App\Models\VehicleTripLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleTripLogController extends Controller
{
    /** GET /vehicle-trip-logs/last-weight?pdo_header_id=&destination= */
    public function lastWeight(Request $request): JsonResponse
    {
        $request->validate([
            'pdo_header_id' => ['required', 'uuid', 'exists:pdo_headers,id'],
            'destination'   => ['required', 'string'],
        ]);

        $pdoHeader = PdoHeader::findOrFail($request->query('pdo_header_id'));
        $last = VehicleTripLog::lastWeightForDestination($pdoHeader->plantation_unit_id, $request->query('destination'));

        return response()->json(['success' => true, 'data' => $last]);
    }

    public function index(Request $request): JsonResponse
    {
        $pdoHeaderId = $request->query('pdo_header_id');

        $logs = VehicleTripLog::with(['vehicle.expenseItem', 'recorder'])
            ->when($pdoHeaderId, fn ($q) => $q->where('pdo_header_id', $pdoHeaderId))
            ->orderByDesc('trip_date')
            ->get();

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function store(StoreVehicleTripLogRequest $request): JsonResponse
    {
        $data           = $request->validated();
        $data['recorded_by'] = $request->user()->id;

        $log = VehicleTripLog::create($data);

        return response()->json([
            'success' => true,
            'data'    => $log->load(['vehicle.expenseItem', 'recorder']),
            'message' => 'Log trip berhasil dicatat.',
        ], 201);
    }

    public function update(UpdateVehicleTripLogRequest $request, VehicleTripLog $vehicleTripLog): JsonResponse
    {
        $vehicleTripLog->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $vehicleTripLog->fresh()->load(['vehicle.expenseItem', 'recorder']),
            'message' => 'Log trip berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, VehicleTripLog $vehicleTripLog): JsonResponse
    {
        if (! $request->user()->canRecordRealization()) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'],
            ], 403);
        }

        $vehicleTripLog->delete();

        return response()->json(['success' => true, 'message' => 'Log trip berhasil dihapus.']);
    }
}
