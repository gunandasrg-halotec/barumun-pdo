<?php

namespace App\Http\Controllers\PdoSupplementary;

use App\Http\Controllers\Controller;
use App\Models\PdoSupplementaryHeader;
use App\Services\PdoSupplementary\PdoSupplementaryMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdoSupplementaryMergeController extends Controller
{
    public function __construct(private readonly PdoSupplementaryMergeService $service) {}

    /**
     * POST /pdo-supplementary/{supplementary}/merge
     * Salin semua item PDO Tambahan ke pdo_details PDO Bulanan induk.
     */
    public function merge(Request $request, PdoSupplementaryHeader $supplementary): JsonResponse
    {
        $merged = $this->service->merge($supplementary, $request->user());

        return response()->json([
            'success' => true,
            'data'    => $merged,
            'message' => 'PDO Tambahan berhasil di-merge ke PDO Bulanan induk.',
        ]);
    }
}
