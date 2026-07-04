<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\DB;

class RecapQueryService
{
    public function getRecapData(array $filters): array
    {
        $year       = $filters['period_year'];
        $month      = $filters['period_month'];
        $unitId     = $filters['unit_id'];
        $categoryId = $filters['category_id'] ?? null;
        $startDate  = $filters['start_date']  ?? null;
        $endDate    = $filters['end_date']    ?? null;

        $rows = DB::select('
            SELECT
                ec.id            AS category_id,
                ec.code          AS category_code,
                ec.name          AS category_name,
                ec.display_order AS category_order,
                es.id            AS subcategory_id,
                es.code          AS subcategory_code,
                es.name          AS subcategory_name,
                es.display_order AS subcategory_order,
                ei.id            AS item_id,
                ei.code          AS item_code,
                ei.name          AS item_name,
                pd.id            AS pdo_detail_id,
                pd.account_number,
                pd.description,
                CASE WHEN ei.is_deduction THEN -pd.amount ELSE pd.amount END AS pengajuan,
                ei.is_deduction,
                COALESCE(SUM(DISTINCT te.amount), 0) AS total_transfer,
                COALESCE(SUM(DISTINCT re.amount), 0) AS total_realization
            FROM pdo_details pd
            JOIN pdo_headers ph             ON ph.id = pd.pdo_header_id
            JOIN expense_items ei           ON ei.id = pd.expense_item_id
            JOIN expense_subcategories es   ON es.id = ei.subcategory_id
            JOIN expense_categories ec      ON ec.id = es.category_id
            LEFT JOIN transfer_entries te   ON te.pdo_detail_id = pd.id
            LEFT JOIN realization_entries re ON re.pdo_detail_id = pd.id
                AND (CAST(:start_date AS date) IS NULL OR re.transaction_date >= CAST(:start_date2 AS date))
                AND (CAST(:end_date   AS date) IS NULL OR re.transaction_date <= CAST(:end_date2   AS date))
            WHERE ph.period_year  = :year
              AND ph.period_month = :month
              AND ph.plantation_unit_id = :unit_id
              AND ec.include_in_recap = TRUE
              AND (CAST(:category_id AS uuid) IS NULL OR ec.id = CAST(:category_id2 AS uuid))
            GROUP BY
                ec.id, ec.code, ec.name, ec.display_order,
                es.id, es.code, es.name, es.display_order,
                ei.id, ei.code, ei.name, ei.is_deduction,
                pd.id, pd.account_number, pd.description, pd.amount
            ORDER BY ec.display_order, es.display_order, ei.id
        ', [
            'year'        => $year,
            'month'       => $month,
            'unit_id'     => $unitId,
            'category_id'  => $categoryId,
            'category_id2' => $categoryId,
            'start_date'   => $startDate,
            'start_date2'  => $startDate,
            'end_date'     => $endDate,
            'end_date2'    => $endDate,
        ]);

        return $this->buildHierarchy($rows);
    }

    private function buildHierarchy(array $rows): array
    {
        $categories  = [];
        $catIndex    = [];
        $subIndex    = [];
        $itemCounter = 0;

        $grandTotalAmount      = 0;
        $grandTotalTransfer    = 0;
        $grandTotalRealization = 0;

        foreach ($rows as $row) {
            $catId = $row->category_id;
            $subId = $row->subcategory_id;

            // ── Category ──────────────────────────────────────────────────────
            if (!isset($catIndex[$catId])) {
                $catIndex[$catId] = count($categories);
                $categories[]     = [
                    'no'                   => count($categories) + 1,
                    'category_code'        => $row->category_code,
                    'category_name'        => $row->category_name,
                    'subtotal_amount'      => 0,
                    'subtotal_transfer'    => 0,
                    'subtotal_realization' => 0,
                    'subtotal_saldo'       => 0,
                    'subcategories'        => [],
                ];
                $subIndex[$catId] = [];
            }

            $catPos = $catIndex[$catId];

            // ── Sub-Category ──────────────────────────────────────────────────
            if (!isset($subIndex[$catId][$subId])) {
                $subIndex[$catId][$subId] = count($categories[$catPos]['subcategories']);
                $categories[$catPos]['subcategories'][] = [
                    'subcategory_code'      => $row->subcategory_code,
                    'subcategory_name'      => $row->subcategory_name,
                    'subtotal_amount'       => 0,
                    'subtotal_transfer'     => 0,
                    'subtotal_realization'  => 0,
                    'subtotal_saldo'        => 0,
                    'items'                 => [],
                ];
            }

            $subPos    = $subIndex[$catId][$subId];
            $pengajuan = (int) $row->pengajuan;
            $transfer  = (int) $row->total_transfer;
            $real      = (int) $row->total_realization;
            $saldo     = $transfer - $real;

            // ── Item ──────────────────────────────────────────────────────────
            $itemCounter++;
            $categories[$catPos]['subcategories'][$subPos]['items'][] = [
                'no'               => $itemCounter,
                'pdo_detail_id'    => $row->pdo_detail_id,
                'item_code'        => $row->item_code,
                'item_name'        => $row->item_name,
                'account_number'   => $row->account_number,
                'description'      => $row->description,
                'amount'           => $pengajuan,
                'total_transfer'   => $transfer,
                'total_realization'=> $real,
                'saldo'            => $saldo,
            ];

            // Roll-up sub-category
            $categories[$catPos]['subcategories'][$subPos]['subtotal_amount']       += $pengajuan;
            $categories[$catPos]['subcategories'][$subPos]['subtotal_transfer']     += $transfer;
            $categories[$catPos]['subcategories'][$subPos]['subtotal_realization']  += $real;
            $categories[$catPos]['subcategories'][$subPos]['subtotal_saldo']        += $saldo;

            // Roll-up category
            $categories[$catPos]['subtotal_amount']       += $pengajuan;
            $categories[$catPos]['subtotal_transfer']     += $transfer;
            $categories[$catPos]['subtotal_realization']  += $real;
            $categories[$catPos]['subtotal_saldo']        += $saldo;

            // Grand total
            $grandTotalAmount      += $pengajuan;
            $grandTotalTransfer    += $transfer;
            $grandTotalRealization += $real;
        }

        return [
            'grand_total_amount'       => $grandTotalAmount,
            'grand_total_transfer'     => $grandTotalTransfer,
            'grand_total_realization'  => $grandTotalRealization,
            'grand_total_saldo'        => $grandTotalTransfer - $grandTotalRealization,
            'categories'               => $categories,
        ];
    }
}
