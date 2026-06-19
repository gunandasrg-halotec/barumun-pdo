<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $service) {}

    /** GET /reports/realization?year=&month=&unit_id=&category_id= */
    public function realization(Request $request): JsonResponse
    {
        $request->validate(['year' => 'required|integer', 'month' => 'required|integer|min:1|max:12']);
        $data = $this->service->realization($request->user(), $request->only(['year', 'month', 'unit_id', 'category_id']));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /reports/over-budget?year=&month=&unit_id= */
    public function overBudget(Request $request): JsonResponse
    {
        $request->validate(['year' => 'required|integer', 'month' => 'required|integer|min:1|max:12']);
        $data = $this->service->overBudget($request->user(), $request->only(['year', 'month', 'unit_id']));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /reports/missing-proof?year=&month=&unit_id= */
    public function missingProof(Request $request): JsonResponse
    {
        $request->validate(['year' => 'required|integer', 'month' => 'required|integer|min:1|max:12']);
        $data = $this->service->missingProof($request->user(), $request->only(['year', 'month', 'unit_id']));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /reports/recap?year=&month= */
    public function recap(Request $request): JsonResponse
    {
        $request->validate(['year' => 'required|integer', 'month' => 'required|integer|min:1|max:12']);
        $data = $this->service->recap($request->user(), $request->only(['year', 'month']));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /reports/export
     * Dispatch async export job, kembalikan job_id untuk polling.
     */
    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'type'  => ['required', 'in:realization,over_budget,missing_proof,recap'],
            'year'  => ['required', 'integer'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $jobId = (string) Str::uuid();

        Cache::put("export:{$jobId}", ['status' => 'queued'], now()->addHour());

        ExportReportJob::dispatch(
            $jobId,
            $request->input('type'),
            $request->only(['year', 'month', 'unit_id', 'category_id']),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'data'    => ['job_id' => $jobId],
            'message' => 'Export sedang diproses. Gunakan job_id untuk cek status.',
        ], 202);
    }

    /**
     * GET /reports/export/{job}
     * Polling status export. Jika done, kembalikan download URL.
     */
    public function exportStatus(Request $request, string $job): JsonResponse
    {
        $state = Cache::get("export:{$job}");

        if (! $state) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Export job tidak ditemukan atau sudah kedaluwarsa.'],
            ], 404);
        }

        $response = ['success' => true, 'data' => $state];

        // Jika selesai, tambahkan URL download sementara
        if ($state['status'] === 'done' && isset($state['path'])) {
            $response['data']['download_url'] = route('reports.export.download', ['job' => $job]);
        }

        return response()->json($response);
    }
}
