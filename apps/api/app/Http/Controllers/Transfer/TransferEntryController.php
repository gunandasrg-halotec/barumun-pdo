<?php

namespace App\Http\Controllers\Transfer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\StoreTransferEntryRequest;
use App\Http\Requests\Transfer\UpdateTransferEntryRequest;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\TransferEntry;
use App\Services\Transfer\TransferEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferEntryController extends Controller
{
    public function __construct(private readonly TransferEntryService $service) {}

    /** GET /transfer-entries — semua transfer dalam perusahaan */
    public function all(Request $request): JsonResponse
    {
        $entries = $this->service->listAll($request->user());

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /** GET /pdo-details/{detail}/transfers */
    public function index(PdoDetail $detail): JsonResponse
    {
        $entries = $this->service->listByDetail($detail);

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /** POST /pdo-details/{detail}/transfers */
    public function store(StoreTransferEntryRequest $request, PdoDetail $detail): JsonResponse
    {
        $entry = $this->service->store($detail, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $entry, 'message' => 'Transfer berhasil dicatat.'], 201);
    }

    /** PUT /transfer-entries/{entry} */
    public function update(UpdateTransferEntryRequest $request, TransferEntry $entry): JsonResponse
    {
        $updated = $this->service->update($entry, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Transfer berhasil diperbarui.']);
    }

    /** GET /pdo/{pdo}/transfers — summary semua detail dalam satu PDO */
    public function summaryByPdo(PdoHeader $pdo): JsonResponse
    {
        $summary = $this->service->summaryByPdo($pdo);

        return response()->json(['success' => true, 'data' => $summary]);
    }
}
