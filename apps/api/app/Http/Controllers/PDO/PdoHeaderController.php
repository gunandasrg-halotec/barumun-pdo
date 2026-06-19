<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Http\Requests\PDO\StorePdoRequest;
use App\Http\Requests\PDO\UpdatePdoRequest;
use App\Models\PdoHeader;
use App\Services\PDO\PdoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoHeaderController extends Controller
{
    public function __construct(private readonly PdoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'period_year', 'period_month']);
        $result  = $this->service->listPdo($filters);

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

    public function store(StorePdoRequest $request): JsonResponse
    {
        $pdo = $this->service->createPdo($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $pdo, 'message' => 'PDO berhasil dibuat dengan template item rutin.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $pdo = $this->service->findPdo($id);

        return response()->json(['success' => true, 'data' => $pdo]);
    }

    public function update(UpdatePdoRequest $request, string $id): JsonResponse
    {
        $pdo     = $this->service->findPdo($id);
        $updated = $this->service->updatePdo($pdo, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pdo = $this->service->findPdo($id);
        $this->service->deletePdo($pdo, $request->user());

        return response()->json(['success' => true, 'message' => 'PDO berhasil dihapus.']);
    }
}
