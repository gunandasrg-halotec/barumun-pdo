<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Http\Requests\PDO\StorePdoDetailRequest;
use App\Http\Requests\PDO\UpdatePdoDetailRequest;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Services\PDO\PdoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoDetailController extends Controller
{
    public function __construct(private readonly PdoService $service) {}

    public function index(PdoHeader $pdo): JsonResponse
    {
        $details = $this->service->listDetails($pdo);

        return response()->json(['success' => true, 'data' => $details]);
    }

    public function store(StorePdoDetailRequest $request, PdoHeader $pdo): JsonResponse
    {
        $detail = $this->service->addDetail($pdo, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $detail, 'message' => 'Item berhasil ditambahkan ke PDO.'], 201);
    }

    public function update(UpdatePdoDetailRequest $request, PdoHeader $pdo, PdoDetail $detail): JsonResponse
    {
        $updated = $this->service->updateDetail($pdo, $detail, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Item PDO berhasil diperbarui.']);
    }

    public function destroy(Request $request, PdoHeader $pdo, PdoDetail $detail): JsonResponse
    {
        $this->service->deleteDetail($pdo, $detail, $request->user());

        return response()->json(['success' => true, 'message' => 'Item PDO berhasil dihapus.']);
    }

    public function pullExternalCost(Request $request, PdoHeader $pdo, PdoDetail $detail): JsonResponse
    {
        $updated = $this->service->pullExternalCost($pdo, $detail, $request->user());

        return response()->json([
            'success' => true,
            'data' => $updated,
            'grand_total' => $pdo->fresh()->grand_total_amount,
            'message' => 'Data Payroll berhasil diambil.',
        ]);
    }

    public function bulkPullExternalCost(Request $request, PdoHeader $pdo): JsonResponse
    {
        $result = $this->service->bulkPullExternalCost($pdo, $request->user());

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Bulk External Cost Pull selesai.',
        ]);
    }
}
