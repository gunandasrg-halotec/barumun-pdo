<?php

namespace App\Services\Dashboard;

use App\Models\PdoHeader;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Ringkasan utama dashboard:
     * - Jumlah PDO per status
     * - Total transfer & realisasi bulan berjalan
     * - PDO menunggu approval milik user
     */
    public function summary(User $user): array
    {
        $companyId = $user->company_id;
        $now       = now();

        $pdoByStatus = PdoHeader::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Total anggaran, transfer, realisasi, dan item tanpa bukti bulan ini (seluruh unit)
        $monthlyStats = DB::selectOne("
            SELECT
                COALESCE(SUM(pd.amount), 0)  AS total_amount,
                COALESCE(SUM(te.amount), 0)  AS total_transferred,
                COALESCE(SUM(re.amount), 0)  AS total_realized,
                COUNT(DISTINCT CASE WHEN re.proof_number IS NULL OR re.proof_number = '' THEN re.id END) AS items_without_proof
            FROM pdo_headers ph
            LEFT JOIN pdo_details pd ON pd.pdo_header_id = ph.id
            LEFT JOIN transfer_entries te ON te.pdo_detail_id = pd.id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_month = ?
              AND ph.period_year  = ?
        ", [$companyId, $now->month, $now->year]);

        $totalTransferred = (int) $monthlyStats->total_transferred;
        $totalRealized    = (int) $monthlyStats->total_realized;

        // PDO yang sedang menunggu persetujuan dari role user
        $pendingForUser = $this->pendingPdoCount($user);

        return [
            'period'              => ['month' => $now->month, 'year' => $now->year],
            'pdo_by_status'       => $pdoByStatus,
            'total_amount'        => (int) $monthlyStats->total_amount,
            'total_transferred'   => $totalTransferred,
            'total_realized'      => $totalRealized,
            'balance'             => $totalTransferred - $totalRealized,
            'items_without_proof' => (int) $monthlyStats->items_without_proof,
            'pending_pdo_count'   => $pendingForUser,
        ];
    }

    /**
     * Breakdown realisasi per kategori biaya untuk periode tertentu.
     * GET /dashboard/category-summary?year=&month=&unit_id=
     */
    public function categorySummary(User $user, array $filters = []): array
    {
        $companyId = $user->company_id;
        $year      = $filters['year']  ?? now()->year;
        $month     = $filters['month'] ?? now()->month;

        $rows = DB::select("
            SELECT
                ec.id            AS category_id,
                ec.code          AS category_code,
                ec.name          AS category_name,
                ec.include_in_recap,
                COALESCE(SUM(pd.amount), 0)  AS total_budget,
                COALESCE(SUM(te.amount), 0)  AS total_transferred,
                COALESCE(SUM(re.amount), 0)  AS total_realized
            FROM expense_categories ec
            JOIN expense_subcategories esc ON esc.category_id = ec.id
            JOIN expense_items ei ON ei.subcategory_id = esc.id
            JOIN pdo_details pd ON pd.expense_item_id = ei.id
            JOIN pdo_headers ph ON ph.id = pd.pdo_header_id
            LEFT JOIN transfer_entries te ON te.pdo_detail_id = pd.id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
            WHERE ec.company_id = ?
              AND ph.period_year  = ?
              AND ph.period_month = ?
              " . (isset($filters['unit_id']) ? 'AND ph.plantation_unit_id = ?' : '') . "
            GROUP BY ec.id, ec.code, ec.name, ec.include_in_recap
            ORDER BY ec.display_order, ec.code
        ", array_filter([$companyId, $year, $month, $filters['unit_id'] ?? null]));

        $grandTotal = array_sum(array_column($rows, 'total_budget'));

        return array_map(fn ($row) => [
            'category_id'       => $row->category_id,
            'category_code'     => $row->category_code,
            'category_name'     => $row->category_name,
            'include_in_recap'  => (bool) $row->include_in_recap,
            'total_amount'      => (int) $row->total_budget,
            'total_budget'      => (int) $row->total_budget,
            'total_transferred' => (int) $row->total_transferred,
            'total_realized'    => (int) $row->total_realized,
            'percentage'        => $grandTotal > 0
                ? round(($row->total_budget / $grandTotal) * 100, 1)
                : 0,
            'absorption_rate'   => $row->total_budget > 0
                ? round(($row->total_realized / $row->total_budget) * 100, 2)
                : 0,
        ], $rows);
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────

    private function pendingPdoCount(User $user): int
    {
        $statusMap = [
            'ASISTEN_KEBUN'     => PdoHeader::STATUS_SUBMITTED,
            'MANAJER_KEBUN'     => PdoHeader::STATUS_REVIEWED_ASISTEN,
            'MANAJER_KEUANGAN'  => PdoHeader::STATUS_IN_REVIEW_MANAGER,
            'DIREKTUR_KEUANGAN' => PdoHeader::STATUS_IN_REVIEW_DIREKTUR,
        ];

        $roleCode      = $user->role?->code;
        $pendingStatus = $statusMap[$roleCode] ?? null;

        if (! $pendingStatus) {
            return 0;
        }

        return PdoHeader::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('status', $pendingStatus)
            ->count();
    }
}
