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
use App\Services\Report\CashBookQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoSupplementaryController extends Controller
{
    public function __construct(
        private readonly PdoSupplementaryService $service,
        private readonly CashBookQueryService $cashBook,
    ) {}

    /** GET /pdo-supplementary/kas-kebun-balance?unit_id= — saldo tersedia untuk opsi "Gunakan Kas Kebun" */
    public function kasKebunBalance(Request $request): JsonResponse
    {
        $request->validate(['unit_id' => ['required', 'uuid', 'exists:plantation_units,id']]);

        return response()->json([
            'success' => true,
            'data'    => ['balance' => $this->cashBook->currentBalance((string) $request->query('unit_id'))],
        ]);
    }

    /** GET /pdo-supplementary?parent_pdo_header_id=&status=&per_page= (status bisa array: status[]=a&status[]=b) */
    public function index(Request $request): JsonResponse
    {
        $filters  = $request->only(['parent_pdo_header_id', 'status', 'plantation_unit_id']);
        $perPage  = in_array((int) $request->query('per_page'), [10, 25, 50], true) ? (int) $request->query('per_page') : 10;
        $result   = $this->service->list($request->user(), $filters, $perPage);

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

    /** PUT /pdo-supplementary/{pdo_supplementary} */
    public function update(UpdatePdoSupplementaryRequest $request, PdoSupplementaryHeader $pdo_supplementary): JsonResponse
    {
        $updated = $this->service->update($pdo_supplementary, $request->validated(), $request->user());

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

    /** DELETE /pdo-supplementary/{pdo_supplementary} — hanya KERANI, hanya status draft */
    public function destroy(Request $request, PdoSupplementaryHeader $pdo_supplementary): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('KERANI')) {
            return response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya Kerani yang dapat menghapus PDO Tambahan.']], 403);
        }

        if ($pdo_supplementary->status !== 'draft') {
            return response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO Tambahan hanya dapat dihapus saat berstatus Draft.']], 422);
        }

        $this->service->delete($pdo_supplementary, $user);

        return response()->json(['success' => true, 'message' => 'PDO Tambahan berhasil dihapus.']);
    }
}
