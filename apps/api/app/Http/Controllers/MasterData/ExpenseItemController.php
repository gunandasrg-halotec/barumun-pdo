<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreExpenseItemRequest;
use App\Http\Requests\MasterData\UpdateExpenseItemRequest;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseItemController extends Controller
{
    public function __construct(private readonly MasterDataService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['subcategory_id', 'is_routine', 'is_active']);
        $data    = $this->service->listItems($filters);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** BR-MASTER-005: daftar item rutin untuk template PDO bulanan */
    public function routine(): JsonResponse
    {
        $data = $this->service->listRoutineItems();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreExpenseItemRequest $request): JsonResponse
    {
        $item = $this->service->createItem($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $item, 'message' => 'Item biaya berhasil dibuat.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = $this->service->findItem($id);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function update(UpdateExpenseItemRequest $request, string $id): JsonResponse
    {
        $item = $this->service->findItem($id);
        $item = $this->service->updateItem($item, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $item, 'message' => 'Item biaya berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasRole('ADMIN')) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'],
            ], 403);
        }

        $item = $this->service->findItem($id);
        $this->service->deleteItem($item, $request->user());

        return response()->json(['success' => true, 'message' => 'Item biaya berhasil dihapus.']);
    }
}
