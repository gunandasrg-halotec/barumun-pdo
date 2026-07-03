<?php

namespace App\Http\Controllers\Realization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Realization\StoreRealizationEntryRequest;
use App\Http\Requests\Realization\UpdateRealizationEntryRequest;
use App\Models\PdoHeader;
use App\Models\RealizationEntry;
use App\Services\Realization\RealizationEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealizationEntryController extends Controller
{
    public function __construct(private readonly RealizationEntryService $service) {}

    /** GET /realization-entries?pdo_detail_id= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['pdo_detail_id']);
        $data    = $this->service->list($request->user(), $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** POST /realization-entries */
    public function store(StoreRealizationEntryRequest $request): JsonResponse
    {
        $entry = $this->service->store($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $entry, 'message' => 'Realisasi berhasil dicatat.'], 201);
    }

    /** PUT /realization-entries/{entry} */
    public function update(UpdateRealizationEntryRequest $request, RealizationEntry $entry): JsonResponse
    {
        $updated = $this->service->update($entry, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Realisasi berhasil diperbarui.']);
    }

    /** DELETE /realization-entries/{entry} */
    public function destroy(Request $request, RealizationEntry $entry): JsonResponse
    {
        $this->service->destroy($entry, $request->user());

        return response()->json(['success' => true, 'message' => 'Realisasi berhasil dihapus.']);
    }

    /** GET /pdo/{pdo}/realizations — summary total per detail */
    public function summaryByPdo(PdoHeader $pdo): JsonResponse
    {
        $summary = $this->service->summaryByPdo($pdo);

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /** GET /pdo/{pdo}/realizations/items — daftar lengkap dengan bukti */
    public function itemsByPdo(PdoHeader $pdo): JsonResponse
    {
        $items = $this->service->itemsByPdo($pdo);

        return response()->json(['success' => true, 'data' => $items]);
    }

    /** GET /pdo/{pdo}/realizations/available — item yang boleh direalisasi actor + saldo kantong */
    public function availableByPdo(Request $request, PdoHeader $pdo): JsonResponse
    {
        $items = $this->service->availableItemsForActor($pdo, $request->user());

        return response()->json(['success' => true, 'data' => $items]);
    }
}
