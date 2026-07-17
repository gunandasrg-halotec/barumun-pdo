<?php

namespace App\Http\Controllers\PDO;

use App\Exports\PdoExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\PDO\StorePdoRequest;
use App\Http\Requests\PDO\UpdatePdoRequest;
use App\Models\PdoHeader;
use App\Services\PDO\PdoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PdoHeaderController extends Controller
{
    public function __construct(private readonly PdoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'period_year', 'period_month', 'plantation_unit_id']);
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
        $data = $request->validated();

        if (!empty($data['source_pdo_id'])) {
            $result  = $this->service->createPdoFromExisting($data, $request->user());
            $message = 'PDO berhasil dibuat — ' . $result['copied_count'] . ' item disalin dari PDO sumber.';
            if ($result['skipped_count'] > 0) {
                $message .= ' ' . $result['skipped_count'] . ' item dilewati karena item biaya tidak aktif di master data.';
            }
            return response()->json([
                'success'       => true,
                'data'          => $result['pdo'],
                'skipped_count' => $result['skipped_count'],
                'message'       => $message,
            ], 201);
        }

        $pdo = $this->service->createPdo($data, $request->user());

        return response()->json(['success' => true, 'data' => $pdo, 'message' => 'PDO berhasil dibuat dengan template item rutin.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        // [E] Response hierarkis: Kategori → Sub-Kategori → Item
        $data = $this->service->findPdoGrouped($id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function pengajuanBreakdown(string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->pengajuanBreakdown($id)]);
    }

    public function update(UpdatePdoRequest $request, string $id): JsonResponse
    {
        $pdo     = $this->service->findPdo($id);
        $updated = $this->service->updatePdo($pdo, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'PDO berhasil diperbarui.']);
    }

    public function export(string $id): BinaryFileResponse
    {
        $data     = $this->service->findPdoGrouped($id);
        $filename = 'PDO-' . ($data['pdo']->pdo_number ?? $id) . '.xlsx';

        return Excel::download(new PdoExport($data), $filename);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pdo = $this->service->findPdo($id);
        $this->service->deletePdo($pdo, $request->user());

        return response()->json(['success' => true, 'message' => 'PDO berhasil dihapus.']);
    }
}
