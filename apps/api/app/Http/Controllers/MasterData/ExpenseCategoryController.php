<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreExpenseCategoryRequest;
use App\Http\Requests\MasterData\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['is_active']);
        $data    = $this->service->listCategories($request->user()->company_id, $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $validated             = $request->validated();
        $validated['company_id'] = $request->user()->company_id;

        $category = $this->service->createCategory($validated, $request->user());

        return response()->json(['success' => true, 'data' => $category, 'message' => 'Kategori biaya berhasil dibuat.'], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $category = $this->service->findCategory($id, $request->user()->company_id);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function update(UpdateExpenseCategoryRequest $request, string $id): JsonResponse
    {
        $category = $this->service->findCategory($id, $request->user()->company_id);
        $category = $this->service->updateCategory($category, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $category, 'message' => 'Kategori biaya berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['ADMIN', 'STAFF_KEUANGAN'])) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'],
            ], 403);
        }

        $category = $this->service->findCategory($id, $request->user()->company_id);
        $this->service->deleteCategory($category, $request->user());

        return response()->json(['success' => true, 'message' => 'Kategori biaya berhasil dihapus.']);
    }
}
