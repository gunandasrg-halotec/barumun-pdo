<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\UpdatePlantationUnitPayrollMappingRequest;
use App\Models\PlantationUnit;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlantationUnitController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PlantationUnit::where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN code = 'HO' THEN 1 ELSE 0 END")
            ->orderBy('code');

        if ($request->boolean('exclude_ho')) {
            $query->where('code', '!=', 'HO');
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(['id', 'code', 'name', 'payroll_estate_external_id']),
        ]);
    }

    public function updatePayrollEstateMapping(
        UpdatePlantationUnitPayrollMappingRequest $request,
        PlantationUnit $plantationUnit,
    ): JsonResponse {
        if ($plantationUnit->company_id !== $request->user()->company_id) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berhak memperbarui unit ini.'],
            ], 403);
        }

        $updated = $this->service->updatePlantationUnitPayrollMapping(
            $plantationUnit,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'data'    => $updated,
            'message' => 'Payroll Estate Mapping berhasil diperbarui.',
        ]);
    }
}
