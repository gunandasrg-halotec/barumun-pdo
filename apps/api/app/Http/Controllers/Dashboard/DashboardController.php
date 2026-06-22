<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    /** GET /dashboard?period_month=&period_year=&plantation_unit_ids[]= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['period_month', 'period_year', 'plantation_unit_ids']);
        $summary = $this->service->summary($request->user(), $filters);

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /** GET /dashboard/category-summary?year=&month=&plantation_unit_ids[]= */
    public function categorySummary(Request $request): JsonResponse
    {
        $filters = $request->only(['year', 'month', 'plantation_unit_ids']);
        $data    = $this->service->categorySummary($request->user(), $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
