<?php

namespace App\Http\Controllers\Reports;

use App\Exports\CashBookDirectExport;
use App\Http\Controllers\Controller;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Services\Report\CashBookQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashBookController extends Controller
{
    public function __construct(private readonly CashBookQueryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        if ($filters === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_REQUIRED', 'message' => 'Unit kebun wajib dipilih.'],
            ], 422);
        }

        $cashBook    = $this->service->getCashBookData($filters);
        $unit        = PlantationUnit::find($filters['unit_id']);
        $periodLabel = Carbon::createFromDate($filters['period_year'], $filters['period_month'], 1)
                             ->locale('id')
                             ->isoFormat('MMMM YYYY');

        return response()->json([
            'success' => true,
            'data'    => array_merge($cashBook, [
                'period_label' => $periodLabel,
                'unit'         => $unit ? ['code' => $unit->code, 'name' => $unit->name] : null,
            ]),
        ]);
    }

    /** GET /reports/cashbook/export — synchronous Excel download */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $filters = $this->validatedFilters($request);

        if ($filters === null) {
            abort(422, 'Unit kebun wajib dipilih.');
        }

        $cashBook   = $this->service->getCashBookData($filters);
        $unit       = PlantationUnit::find($filters['unit_id']);
        $month      = $filters['period_month'];
        $year       = $filters['period_year'];
        $dateSuffix = ($filters['start_date'] || $filters['end_date'])
            ? '_' . ($filters['start_date'] ?? '') . '_sd_' . ($filters['end_date'] ?? '')
            : '';
        $filename   = "BukuKasHarian_{$year}_{$month}" . ($unit ? "_{$unit->code}" : '') . $dateSuffix . '.xlsx';

        return Excel::download(new CashBookDirectExport($cashBook, $unit, $month, $year), $filename);
    }

    private function validatedFilters(Request $request): ?array
    {
        $request->validate([
            'period_year'  => ['required', 'integer', 'min:2020', 'max:2030'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'unit_id'      => ['nullable', 'uuid'],
            'start_date'   => ['nullable', 'date_format:Y-m-d'],
            'end_date'     => ['nullable', 'date_format:Y-m-d'],
        ]);

        $unitId = $this->resolveUnitId($request, $request->user());

        if ($unitId === null) {
            return null;
        }

        return [
            'period_year'  => $request->integer('period_year'),
            'period_month' => $request->integer('period_month'),
            'unit_id'      => $unitId,
            'start_date'   => $request->input('start_date') ?: null,
            'end_date'     => $request->input('end_date')   ?: null,
        ];
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

        return $user->plantation_unit_id;
    }
}
