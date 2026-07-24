<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreVehicleRequest;
use App\Http\Requests\MasterData\UpdateVehicleRequest;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['is_active', 'expense_item_id', 'has_bbm_realization']);

        if (isset($filters['has_bbm_realization'])) {
            $filters['has_bbm_realization'] = filter_var($filters['has_bbm_realization'], FILTER_VALIDATE_BOOLEAN);
        }

        $data = $this->service->listVehicles($filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->service->createVehicle($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $vehicle, 'message' => 'Kendaraan berhasil ditambahkan.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $vehicle = $this->service->findVehicle($id);

        return response()->json(['success' => true, 'data' => $vehicle]);
    }

    public function update(UpdateVehicleRequest $request, string $id): JsonResponse
    {
        $vehicle = $this->service->findVehicle($id);
        $vehicle = $this->service->updateVehicle($vehicle, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $vehicle, 'message' => 'Kendaraan berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['ADMIN', 'STAFF_KEUANGAN'])) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'],
            ], 403);
        }

        $vehicle = $this->service->findVehicle($id);
        $this->service->deleteVehicle($vehicle, $request->user());

        return response()->json(['success' => true, 'message' => 'Kendaraan berhasil dihapus.']);
    }
}
