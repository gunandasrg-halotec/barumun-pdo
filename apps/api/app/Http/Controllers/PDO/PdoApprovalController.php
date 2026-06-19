<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Http\Requests\PDO\ApprovePdoRequest;
use App\Http\Requests\PDO\RejectPdoRequest;
use App\Http\Requests\PDO\SubmitPdoRequest;
use App\Models\PdoHeader;
use App\Services\PDO\PdoApprovalService;
use Illuminate\Http\JsonResponse;

class PdoApprovalController extends Controller
{
    public function __construct(private readonly PdoApprovalService $service) {}

    public function submit(SubmitPdoRequest $request, PdoHeader $pdo): JsonResponse
    {
        $updated = $this->service->submit($pdo, $request->input('submission_date'), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO berhasil disubmit untuk review.']);
    }

    public function approve(ApprovePdoRequest $request, PdoHeader $pdo): JsonResponse
    {
        $updated = $this->service->approve($pdo, $request->input('reason'), $request->user());

        $message = $updated->status === PdoHeader::STATUS_FINAL
            ? 'PDO disetujui dan transfer otomatis telah dibuat.'
            : 'PDO berhasil disetujui dan diteruskan ke tahap berikutnya.';

        return response()->json(['success' => true, 'data' => $updated, 'message' => $message]);
    }

    public function reject(RejectPdoRequest $request, PdoHeader $pdo): JsonResponse
    {
        $updated = $this->service->reject($pdo, $request->input('reason'), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO dikembalikan ke KERANI untuk direvisi.']);
    }

    public function history(PdoHeader $pdo): JsonResponse
    {
        $logs = $this->service->history($pdo);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /** Placeholder — PDO Tambahan diimplementasikan di sprint berikutnya */
    public function submitSupplementary(): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Fitur ini belum tersedia.']], 501);
    }

    public function approveSupplementary(): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Fitur ini belum tersedia.']], 501);
    }

    public function rejectSupplementary(): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Fitur ini belum tersedia.']], 501);
    }
}
