<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Http\Requests\PDO\ApprovePdoRequest;
use App\Http\Requests\PDO\RejectPdoRequest;
use App\Http\Requests\PDO\SubmitPdoRequest;
use App\Http\Requests\PdoSupplementary\SubmitPdoSupplementaryRequest;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Services\PDO\PdoApprovalService;
use App\Services\PdoSupplementary\PdoSupplementaryApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoApprovalController extends Controller
{
    public function __construct(
        private readonly PdoApprovalService $service,
        private readonly PdoSupplementaryApprovalService $suppService = new PdoSupplementaryApprovalService(),
    ) {}

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

    public function submitSupplementary(SubmitPdoSupplementaryRequest $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $updated = $this->suppService->submit($supplementary, $request->input('submission_date'), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO Tambahan berhasil disubmit untuk review.']);
    }

    public function approveSupplementary(ApprovePdoRequest $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $updated = $this->suppService->approve($supplementary, $request->input('reason'), $request->user());

        $isFinal = $updated->status === PdoSupplementaryHeader::STATUS_FINAL_MERGED;
        $message = $isFinal
            ? 'PDO Tambahan disetujui Direktur. Silakan lakukan merge ke PDO Bulanan.'
            : 'PDO Tambahan berhasil disetujui dan diteruskan ke tahap berikutnya.';

        return response()->json(['success' => true, 'data' => $updated, 'message' => $message]);
    }

    public function rejectSupplementary(RejectPdoRequest $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $updated = $this->suppService->reject($supplementary, $request->input('reason'), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO Tambahan ditolak. KERANI dapat merevisi dan resubmit.']);
    }
}
