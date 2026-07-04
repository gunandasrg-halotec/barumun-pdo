<?php

namespace App\Http\Controllers\Reports;

use App\Exports\RecapDirectExport;
use App\Http\Controllers\Controller;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Services\Report\RecapQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecapController extends Controller
{
    public function __construct(private readonly RecapQueryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period_year'  => ['required', 'integer', 'min:2020', 'max:2030'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'unit_id'      => ['nullable', 'uuid'],
            'category_id'  => ['nullable', 'uuid'],
            'start_date'   => ['nullable', 'date_format:Y-m-d'],
            'end_date'     => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();

        // Row-level security: KERANI & ASISTEN_KEBUN are locked to their own unit
        $unitId = $this->resolveUnitId($request, $user);

        if ($unitId === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_REQUIRED', 'message' => 'Unit kebun wajib dipilih.'],
            ], 422);
        }

        $filters = [
            'period_year'  => $request->integer('period_year'),
            'period_month' => $request->integer('period_month'),
            'unit_id'      => $unitId,
            'category_id'  => $request->input('category_id'),
            'start_date'   => $request->input('start_date') ?: null,
            'end_date'     => $request->input('end_date')   ?: null,
        ];

        $recap = $this->service->getRecapData($filters);

        $unit        = PlantationUnit::find($unitId);
        $periodLabel = Carbon::createFromDate($filters['period_year'], $filters['period_month'], 1)
                             ->locale('id')
                             ->isoFormat('MMMM YYYY');

        return response()->json([
            'success' => true,
            'data'    => array_merge($recap, [
                'period_label' => $periodLabel,
                'unit'         => $unit
                    ? ['code' => $unit->code, 'name' => $unit->name]
                    : null,
            ]),
        ]);
    }

    /** GET /reports/recap/export — synchronous Excel download (no queue/S3 needed) */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $request->validate([
            'period_year'  => ['required', 'integer', 'min:2020', 'max:2030'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'unit_id'      => ['nullable', 'uuid'],
            'category_id'  => ['nullable', 'uuid'],
            'start_date'   => ['nullable', 'date_format:Y-m-d'],
            'end_date'     => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user   = $request->user();
        $unitId = $this->resolveUnitId($request, $user);

        if ($unitId === null) {
            abort(422, 'Unit kebun wajib dipilih.');
        }

        $filters = [
            'period_year'  => $request->integer('period_year'),
            'period_month' => $request->integer('period_month'),
            'unit_id'      => $unitId,
            'category_id'  => $request->input('category_id'),
            'start_date'   => $request->input('start_date') ?: null,
            'end_date'     => $request->input('end_date')   ?: null,
        ];

        $recap      = $this->service->getRecapData($filters);
        $unit       = PlantationUnit::find($unitId);
        $month      = $filters['period_month'];
        $year       = $filters['period_year'];
        $dateSuffix = ($filters['start_date'] || $filters['end_date'])
            ? '_' . ($filters['start_date'] ?? '') . '_sd_' . ($filters['end_date'] ?? '')
            : '';
        $filename   = "BukuKasKebun_{$year}_{$month}" . ($unit ? "_{$unit->code}" : '') . $dateSuffix . '.xlsx';

        return Excel::download(new RecapDirectExport($recap, $unit, $month, $year), $filename);
    }

    private function resolveUnitId(Request $request, $user): ?string
    {
        $roleCode = $user->role?->code;

        // Locked roles — always use own unit
        if (in_array($roleCode, [Role::KERANI, Role::ASISTEN_KEBUN], true)) {
            return $user->plantation_unit_id;
        }

        // Cross-unit roles — use requested unit_id, or fall back to own unit
        $requestedUnit = $request->input('unit_id');

        if ($requestedUnit) {
            return $requestedUnit;
        }

        // If user has a unit, default to it; otherwise require selection
        return $user->plantation_unit_id;
    }
}
