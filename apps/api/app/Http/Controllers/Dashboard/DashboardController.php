<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    /** GET /dashboard */
    public function index(Request $request): JsonResponse
    {
        $summary = $this->service->summary($request->user());

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /** GET /dashboard/category-summary?year=&month=&unit_id= */
    public function categorySummary(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month', 'unit_id']);
        $data    = $this->service->categorySummary($request->user(), $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
