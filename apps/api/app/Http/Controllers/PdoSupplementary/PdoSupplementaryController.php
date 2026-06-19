<?php

namespace App\Http\Controllers\PdoSupplementary;

use App\Http\Controllers\Controller;
use App\Http\Requests\PdoSupplementary\StorePdoSupplementaryDetailRequest;
use App\Http\Requests\PdoSupplementary\StorePdoSupplementaryRequest;
use App\Http\Requests\PdoSupplementary\UpdatePdoSupplementaryDetailRequest;
use App\Http\Requests\PdoSupplementary\UpdatePdoSupplementaryRequest;
use App\Models\PdoSupplementaryDetail;
use App\Models\PdoSupplementaryHeader;
use App\Services\PdoSupplementary\PdoSupplementaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoSupplementaryController extends Controller
{
    public function __construct(private readonly PdoSupplementaryService $service) {}

    /** GET /pdo-supplementary?parent_pdo_header_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['parent_pdo_header_id', 'status', 'plantation_unit_id']);
        $result  = $this->service->list($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => [
                'current_page' => $result->currentPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
                'last_page'    => $result->lastPage(),
            ],
        ]);
    }

    /** POST /pdo-supplementary */
    public function store(StorePdoSupplementaryRequest $request): JsonResponse
    {
        $supp = $this->service->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $supp, 'message' => 'PDO Tambahan berhasil dibuat.'], 201);
    }

    /** GET /pdo-supplementary/{supplementary} */
    public function show(string $id): JsonResponse
    {
        $supp = $this->service->find($id);

        return response()->json(['success' => true, 'data' => $supp]);
    }

    /** PUT /pdo-supplementary/{supplementary} */
    public function update(UpdatePdoSupplementaryRequest $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $updated = $this->service->update($supplementary, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO Tambahan berhasil diperbarui.']);
    }

    // ─────────────────────────────────────────────────────
    // DETAIL ENDPOINTS (nested under /pdo-supplementary/{supplementary}/details)
    // ─────────────────────────────────────────────────────

    /** POST /pdo-supplementary/{supplementary}/details */
    public function storeDetail(StorePdoSupplementaryDetailRequest $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $detail = $this->service->addDetail($supplementary, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $detail, 'message' => 'Item berhasil ditambahkan ke PDO Tambahan.'], 201);
    }

    /** PUT /pdo-supplementary/{supplementary}/details/{detail} */
    public function updateDetail(UpdatePdoSupplementaryDetailRequest $request, PdoSupplementaryHeader $supplementary, PdoSupplementaryDetail $detail): JsonResponse
    {
        // Pastikan detail milik supplementary ini
        if ($detail->pdo_supplementary_header_id !== $supplementary->id) {
            return response()->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Item tidak ditemukan dalam PDO Tambahan ini.']], 404);
        }

        $updated = $this->service->updateDetail($supplementary, $detail, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Item PDO Tambahan berhasil diperbarui.']);
    }

    /** DELETE /pdo-supplementary/{supplementary}/details/{detail} */
    public function destroyDetail(Request $request, PdoSupplementaryHeader $supplementary, PdoSupplementaryDetail $detail): JsonResponse
    {
        if ($detail->pdo_supplementary_header_id !== $supplementary->id) {
            return response()->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Item tidak ditemukan dalam PDO Tambahan ini.']], 404);
        }

        $this->service->deleteDetail($supplementary, $detail, $request->user());

        return response()->json(['success' => true, 'message' => 'Item PDO Tambahan berhasil dihapus.']);
    }
}
