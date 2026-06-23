<?php

namespace App\Services\Dashboard;

use App\Models\PdoHeader;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function summary(User $user, array $filters = []): array
    {
        $companyId  = $user->company_id;
        $month      = $filters['period_month'] ?? now()->month;
        $year       = $filters['period_year']  ?? now()->year;
        $unitIds    = $this->resolveUnitIds($filters);

        $pdoQuery = PdoHeader::withoutGlobalScopes()->where('company_id', $companyId);
        if ($unitIds) {
            $pdoQuery->whereIn('plantation_unit_id', $unitIds);
        }
        $pdoByStatus = $pdoQuery
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $unitClause = $unitIds
            ? 'AND ph.plantation_unit_id = ANY(ARRAY[' . implode(',', array_fill(0, count($unitIds), '?')) . ']::uuid[])'
            : '';

        $params = array_merge([$companyId, $month, $year], $unitIds ?? []);

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
              {$unitClause}
        ", $params);

        $totalTransferred = (int) $monthlyStats->total_transferred;
        $totalRealized    = (int) $monthlyStats->total_realized;
        $pendingForUser   = $this->pendingPdoCount($user);

        // Transfer breakdown per destination
        $destRows = DB::select("
            SELECT te.transfer_destination, COALESCE(SUM(te.amount), 0) AS subtotal
            FROM pdo_headers ph
            LEFT JOIN pdo_details pd ON pd.pdo_header_id = ph.id
            LEFT JOIN transfer_entries te ON te.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_month = ?
              AND ph.period_year  = ?
              AND te.id IS NOT NULL
              {$unitClause}
            GROUP BY te.transfer_destination
        ", $params);
        $byDest = collect($destRows)->pluck('subtotal', 'transfer_destination');

        // Pengajuan & realisasi per plantation unit (tanpa transfer agar tidak multiply rows)
        $unitRows = DB::select("
            SELECT
                pu.id   AS unit_id,
                pu.code AS unit_code,
                pu.name AS unit_name,
                COALESCE(SUM(pd.amount), 0) AS total_amount,
                COALESCE(SUM(re.amount), 0) AS total_realized
            FROM pdo_headers ph
            JOIN plantation_units pu ON pu.id = ph.plantation_unit_id
            LEFT JOIN pdo_details pd ON pd.pdo_header_id = ph.id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_month = ?
              AND ph.period_year  = ?
              {$unitClause}
            GROUP BY pu.id, pu.code, pu.name
            ORDER BY pu.code
        ", $params);

        // Transfer per unit per destination
        $unitDestRows = DB::select("
            SELECT
                pu.id AS unit_id,
                te.transfer_destination,
                COALESCE(SUM(te.amount), 0) AS subtotal
            FROM pdo_headers ph
            JOIN plantation_units pu ON pu.id = ph.plantation_unit_id
            LEFT JOIN pdo_details pd ON pd.pdo_header_id = ph.id
            LEFT JOIN transfer_entries te ON te.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_month = ?
              AND ph.period_year  = ?
              AND te.id IS NOT NULL
              {$unitClause}
            GROUP BY pu.id, te.transfer_destination
        ", $params);

        // Index transfer per unit
        $transferByUnit = [];
        foreach ($unitDestRows as $row) {
            $transferByUnit[$row->unit_id][$row->transfer_destination] = (int) $row->subtotal;
        }

        $byUnit = array_map(function ($r) use ($transferByUnit) {
            $t = $transferByUnit[$r->unit_id] ?? [];
            $rekKebun = (int) ($t['rek_kebun'] ?? 0);
            $pribadi  = (int) ($t['pribadi']   ?? 0);
            $vendor   = (int) ($t['vendor']    ?? 0);
            return [
                'unit_id'               => $r->unit_id,
                'unit_code'             => $r->unit_code,
                'unit_name'             => $r->unit_name,
                'total_amount'          => (int) $r->total_amount,
                'total_transferred'     => $rekKebun + $pribadi + $vendor,
                'total_realized'        => (int) $r->total_realized,
                'transferred_rek_kebun' => $rekKebun,
                'transferred_pribadi'   => $pribadi,
                'transferred_vendor'    => $vendor,
            ];
        }, $unitRows);

        return [
            'period'              => ['month' => (int) $month, 'year' => (int) $year],
            'pdo_by_status'       => $pdoByStatus,
            'total_amount'        => (int) $monthlyStats->total_amount,
            'total_transferred'   => $totalTransferred,
            'total_realized'      => $totalRealized,
            'balance'             => $totalTransferred - $totalRealized,
            'items_without_proof' => (int) $monthlyStats->items_without_proof,
            'pending_pdo_count'   => $pendingForUser,
            'transferred_by_destination' => [
                'rek_kebun' => (int) ($byDest['rek_kebun'] ?? 0),
                'pribadi'   => (int) ($byDest['pribadi']   ?? 0),
                'vendor'    => (int) ($byDest['vendor']    ?? 0),
            ],
            'by_unit' => $byUnit,
        ];
    }

    public function categorySummary(User $user, array $filters = []): array
    {
        $companyId = $user->company_id;
        $year      = $filters['year']  ?? now()->year;
        $month     = $filters['month'] ?? now()->month;
        $unitIds   = $this->resolveUnitIds($filters);

        $unitClause = $unitIds
            ? 'AND ph.plantation_unit_id = ANY(ARRAY[' . implode(',', array_fill(0, count($unitIds), '?')) . ']::uuid[])'
            : '';

        $params = array_merge([$companyId, $year, $month], $unitIds ?? []);

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
              {$unitClause}
            GROUP BY ec.id, ec.code, ec.name, ec.include_in_recap
            ORDER BY ec.display_order, ec.code
        ", $params);

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

    /** Resolve plantation_unit_ids dari filter; null = semua unit */
    private function resolveUnitIds(array $filters): ?array
    {
        $ids = $filters['plantation_unit_ids'] ?? null;
        if (empty($ids) || !is_array($ids)) {
            return null;
        }
        $filtered = array_filter($ids, fn ($id) => !empty($id));
        return empty($filtered) ? null : array_values($filtered);
    }

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
