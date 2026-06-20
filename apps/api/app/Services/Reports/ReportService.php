<?php

namespace App\Services\Reports;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Laporan realisasi lengkap: anggaran vs realisasi vs transfer per unit/periode.
     * GET /reports/realization?year=&month=&unit_id=&category_id=
     */
    public function realization(User $user, array $filters): array
    {
        $params   = [$user->company_id, $filters['year'], $filters['month']];
        $unitWhere = '';

        if (! empty($filters['unit_id'])) {
            $unitWhere = 'AND ph.plantation_unit_id = ?';
            $params[]  = $filters['unit_id'];
        }
        if (! empty($filters['category_id'])) {
            $unitWhere .= ' AND ec.id = ?';
            $params[]   = $filters['category_id'];
        }

        return DB::select("
            SELECT
                pu.code          AS unit_code,
                pu.name          AS unit_name,
                ph.pdo_number,
                ph.status        AS pdo_status,
                ec.code          AS category_code,
                ec.name          AS category_name,
                esc.code         AS subcategory_code,
                esc.name         AS subcategory_name,
                ei.code          AS item_code,
                ei.name          AS item_name,
                pd.description,
                pd.amount        AS budget,
                COALESCE(SUM(DISTINCT te.amount), 0) AS total_transferred,
                COALESCE(SUM(DISTINCT re.amount), 0) AS total_realized,
                pd.amount - COALESCE(SUM(DISTINCT re.amount), 0) AS sisa
            FROM pdo_headers ph
            JOIN plantation_units pu ON pu.id = ph.plantation_unit_id
            JOIN pdo_details pd ON pd.pdo_header_id = ph.id
            JOIN expense_items ei ON ei.id = pd.expense_item_id
            JOIN expense_subcategories esc ON esc.id = ei.subcategory_id
            JOIN expense_categories ec ON ec.id = esc.category_id
            LEFT JOIN transfer_entries te ON te.pdo_detail_id = pd.id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_year  = ?
              AND ph.period_month = ?
              {$unitWhere}
            GROUP BY pu.code, pu.name, ph.pdo_number, ph.status,
                     ec.code, ec.name, esc.code, esc.name,
                     ei.code, ei.name, pd.description, pd.amount
            ORDER BY pu.code, ec.display_order, esc.display_order, pd.display_order
        ", $params);
    }

    /**
     * Item yang total realisasi melebihi total transfer (over-spending).
     * GET /reports/over-budget?year=&month=&unit_id=
     */
    public function overBudget(User $user, array $filters): array
    {
        $params = [$user->company_id, $filters['year'], $filters['month']];
        $where  = '';

        if (! empty($filters['unit_id'])) {
            $where    = 'AND ph.plantation_unit_id = ?';
            $params[] = $filters['unit_id'];
        }

        return DB::select("
            SELECT
                pu.code       AS unit_code,
                ph.pdo_number,
                ei.code       AS item_code,
                ei.name       AS item_name,
                pd.amount     AS budget,
                COALESCE(SUM(re.amount), 0) AS total_realized,
                COALESCE(SUM(re.amount), 0) - pd.amount AS over_amount
            FROM pdo_details pd
            JOIN pdo_headers ph ON ph.id = pd.pdo_header_id
            JOIN plantation_units pu ON pu.id = ph.plantation_unit_id
            JOIN expense_items ei ON ei.id = pd.expense_item_id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
            WHERE ph.company_id = ?
              AND ph.period_year  = ?
              AND ph.period_month = ?
              {$where}
            GROUP BY pu.code, ph.pdo_number, ei.code, ei.name, pd.amount
            HAVING COALESCE(SUM(re.amount), 0) > pd.amount
            ORDER BY over_amount DESC
        ", $params);
    }

    /**
     * Item realisasi di atas threshold yang belum ada bukti lampiran.
     * Threshold diambil dari system_settings (threshold_proof_amount).
     * GET /reports/missing-proof?year=&month=&unit_id=
     */
    public function missingProof(User $user, array $filters): array
    {
        $threshold = (int) SystemSetting::getValue(
            $user->company_id,
            SystemSetting::KEY_THRESHOLD_PROOF,
            1000000
        );

        $params = [$user->company_id, $filters['year'], $filters['month'], $threshold];
        $where  = '';

        if (! empty($filters['unit_id'])) {
            $where    = 'AND ph.plantation_unit_id = ?';
            $params[] = $filters['unit_id'];
        }

        return DB::select("
            SELECT
                pu.code          AS unit_code,
                ph.pdo_number,
                ei.name          AS item_name,
                re.transaction_date,
                re.amount,
                re.payment_method,
                re.proof_number,
                COUNT(ra.id)     AS attachment_count
            FROM realization_entries re
            JOIN pdo_details pd ON pd.id = re.pdo_detail_id
            JOIN pdo_headers ph ON ph.id = pd.pdo_header_id
            JOIN plantation_units pu ON pu.id = ph.plantation_unit_id
            JOIN expense_items ei ON ei.id = pd.expense_item_id
            LEFT JOIN realization_attachments ra ON ra.realization_entry_id = re.id
            WHERE ph.company_id = ?
              AND ph.period_year  = ?
              AND ph.period_month = ?
              AND re.amount >= ?
              {$where}
            GROUP BY pu.code, ph.pdo_number, ei.name,
                     re.transaction_date, re.amount, re.payment_method, re.proof_number
            HAVING COUNT(ra.id) = 0
            ORDER BY re.amount DESC
        ", $params);
    }

    /**
     * Rekap PDO per kategori (untuk laporan manajemen).
     * GET /reports/recap?year=&month=
     */
    public function recap(User $user, array $filters): array
    {
        $params = [$user->company_id, $filters['year'], $filters['month']];

        return DB::select("
            SELECT
                ec.code             AS category_code,
                ec.name             AS category_name,
                ec.include_in_recap,
                COALESCE(SUM(pd.amount), 0)  AS total_budget,
                COALESCE(SUM(te.amount), 0)  AS total_transferred,
                COALESCE(SUM(re.amount), 0)  AS total_realized,
                CASE WHEN SUM(pd.amount) > 0
                     THEN ROUND(SUM(re.amount)::NUMERIC / SUM(pd.amount) * 100, 2)
                     ELSE 0 END     AS absorption_pct
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
              AND ec.include_in_recap = TRUE
            GROUP BY ec.id, ec.code, ec.name, ec.include_in_recap, ec.display_order
            ORDER BY ec.display_order, ec.code
        ", $params);
    }
}
