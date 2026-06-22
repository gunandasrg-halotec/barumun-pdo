<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreExpenseSubcategoryRequest;
use App\Http\Requests\MasterData\UpdateExpenseSubcategoryRequest;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseSubcategoryController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'is_active']);
        $data    = $this->service->listSubcategories($filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreExpenseSubcategoryRequest $request): JsonResponse
    {
        $sub = $this->service->createSubcategory($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $sub, 'message' => 'Sub-kategori berhasil dibuat.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $sub = $this->service->findSubcategory($id);

        return response()->json(['success' => true, 'data' => $sub]);
    }

    public function update(UpdateExpenseSubcategoryRequest $request, string $id): JsonResponse
    {
        $sub = $this->service->findSubcategory($id);
        $sub = $this->service->updateSubcategory($sub, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $sub, 'message' => 'Sub-kategori berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['ADMIN', 'STAFF_KEUANGAN'])) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'],
            ], 403);
        }

        $sub = $this->service->findSubcategory($id);
        $this->service->deleteSubcategory($sub, $request->user());

        return response()->json(['success' => true, 'message' => 'Sub-kategori berhasil dihapus.']);
    }
}
