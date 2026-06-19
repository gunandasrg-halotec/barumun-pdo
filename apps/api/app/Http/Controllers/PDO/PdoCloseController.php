<?php

namespace App\Http\Controllers\PDO;

use App\Exceptions\PdoNotFinalException;
use App\Http\Controllers\Controller;
use App\Services\PDO\PdoCloseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoCloseController extends Controller
{
    public function __construct(private readonly PdoCloseService $closeService) {}

    /**
     * POST /api/v1/pdo/{id}/close
     * BR-CLOSE-002: Penutupan manual — hanya MANAJER_KEUANGAN
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'closed_date'   => ['required', 'date', 'after_or_equal:today'],
            'closure_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $pdo = $this->closeService->closeManual($id, $request->user(), $validated);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => $e->getMessage()],
            ], 403);
        } catch (PdoNotFinalException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => $e->getMessage()],
            ], 422);
        }

        $closedBy = $pdo->closer;

        return response()->json([
            'success' => true,
            'data'    => [
                'pdo_number'   => $pdo->pdo_number,
                'status'       => $pdo->status,
                'closure_type' => $pdo->closure_type,
                'closed_at'    => $pdo->closed_at?->toIso8601String(),
                'closed_by'    => $closedBy ? ['id' => $closedBy->id, 'full_name' => $closedBy->full_name] : null,
            ],
            'message' => 'PDO berhasil ditutup.',
        ]);
    }
}
