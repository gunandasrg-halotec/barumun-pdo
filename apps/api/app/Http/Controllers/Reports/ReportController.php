<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private readonly ReportQueryService $service) {}

    /** GET /reports/realization */
    public function realization(Request $request): JsonResponse
    {
        $request->validate([
            'period_year'  => 'required|integer',
            'period_month' => 'required|integer|min:1|max:12',
        ]);

        $data = $this->service->getRealizationData($this->buildFilters($request));

        return response()->json(['success' => true, 'data' => $data->values()]);
    }

    /** GET /reports/over-budget */
    public function overBudget(Request $request): JsonResponse
    {
        $request->validate([
            'period_year'  => 'required|integer',
            'period_month' => 'required|integer|min:1|max:12',
        ]);

        $data = $this->service->getOverBudgetData($this->buildFilters($request));

        return response()->json(['success' => true, 'data' => $data->values()]);
    }

    /** GET /reports/missing-proof */
    public function missingProof(Request $request): JsonResponse
    {
        $request->validate([
            'period_year'  => 'required|integer',
            'period_month' => 'required|integer|min:1|max:12',
        ]);

        $data = $this->service->getMissingProofData($this->buildFilters($request));

        return response()->json(['success' => true, 'data' => $data->values()]);
    }

    /** GET /reports/recap */
    public function recap(Request $request): JsonResponse
    {
        $request->validate([
            'period_year'  => 'required|integer',
            'period_month' => 'required|integer|min:1|max:12',
        ]);

        $data = $this->service->getRecapData($this->buildFilters($request));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /reports/export
     * Dispatch async export, returns job_id (202).
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type'  => ['required', 'in:realization,over_budget,missing_proof,recap'],
            'format'       => ['required', 'in:xlsx,pdf'],
            'period_year'  => ['required', 'integer'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'unit_id'      => ['nullable', 'uuid'],
            'category_id'  => ['nullable', 'uuid'],
        ]);

        $jobId = (string) Str::uuid();

        Cache::put("export_job:{$jobId}", [
            'status'      => 'queued',
            'report_type' => $validated['report_type'],
            'format'      => $validated['format'],
            'created_at'  => now()->toISOString(),
        ], now()->addHours(24));

        ExportReportJob::dispatch(
            $jobId,
            $validated['report_type'],
            $validated['format'],
            $this->buildFilters($request),
            $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'data'    => ['job_id' => $jobId],
            'message' => 'Export sedang diproses.',
        ], 202);
    }

    /** GET /reports/export/{jobId} — polling */
    public function exportStatus(string $jobId): JsonResponse
    {
        $state = Cache::get("export_job:{$jobId}");

        if (!$state) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Export job tidak ditemukan atau sudah kedaluwarsa.'],
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $state]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function buildFilters(Request $request): array
    {
        $user = $request->user();

        return array_filter([
            'period_year'  => $request->integer('period_year') ?: null,
            'period_month' => $request->integer('period_month') ?: null,
            'unit_id'      => $request->input('unit_id'),
            'category_id'  => $request->input('category_id'),
            'company_id'   => $user?->company_id,
        ], fn ($v) => !is_null($v));
    }
}
