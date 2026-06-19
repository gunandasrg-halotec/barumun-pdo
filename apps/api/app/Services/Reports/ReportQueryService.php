<?php

namespace App\Services\Reports;

use App\Models\RealizationEntry;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportQueryService
{
    /**
     * Query gabungan PDO + detail + transfer + realisasi.
     * FR-07: Laporan Realisasi Dana
     */
    public function getRealizationData(array $filters): Collection
    {
        $companyId = $filters['company_id'] ?? null;

        // Ambil threshold dari system settings
        $threshold = (int) ($companyId
            ? SystemSetting::getValue($companyId, SystemSetting::KEY_THRESHOLD_PROOF, 1_000_000)
            : 1_000_000);

        $query = DB::table('pdo_headers as ph')
            ->join('pdo_details as pd', 'pd.pdo_header_id', '=', 'ph.id')
            ->join('expense_items as ei', 'ei.id', '=', 'pd.expense_item_id')
            ->join('expense_subcategories as es', 'es.id', '=', 'ei.subcategory_id')
            ->join('expense_categories as ec', 'ec.id', '=', 'es.category_id')
            ->join('plantation_units as pu', 'pu.id', '=', 'ph.plantation_unit_id')
            ->leftJoinSub(
                DB::table('transfer_entries')
                    ->select('pdo_detail_id', DB::raw('COALESCE(SUM(amount), 0) as total_transfer'))
                    ->groupBy('pdo_detail_id'),
                'te', 'te.pdo_detail_id', '=', 'pd.id'
            )
            ->leftJoinSub(
                DB::table('realization_entries')
                    ->select('pdo_detail_id', DB::raw('COALESCE(SUM(amount), 0) as total_realization'))
                    ->groupBy('pdo_detail_id'),
                're', 're.pdo_detail_id', '=', 'pd.id'
            )
            // Flag: apakah ada entry nominal besar tanpa bukti
            ->leftJoinSub(
                DB::table('realization_entries as ren')
                    ->leftJoin('realization_attachments as ra', 'ra.realization_entry_id', '=', 'ren.id')
                    ->select('ren.pdo_detail_id')
                    ->where('ren.amount', '>', $threshold)
                    ->whereNull('ra.id')
                    ->groupBy('ren.pdo_detail_id'),
                'missing_proof', 'missing_proof.pdo_detail_id', '=', 'pd.id'
            )
            ->select([
                'pd.id as detail_id',
                'ph.id as pdo_header_id',
                'ph.pdo_number',
                'ph.period_year',
                'ph.period_month',
                'ph.status as pdo_status',
                'pu.code as unit_code',
                'pu.name as unit_name',
                'ec.id as category_id',
                'ec.code as category_code',
                'ec.name as category_name',
                'es.code as subcategory_code',
                'es.name as subcategory_name',
                'ei.name as item_name',
                'ei.code as item_code',
                'pd.account_number',
                'pd.description',
                'pd.amount',
                DB::raw('COALESCE(te.total_transfer, 0) as total_transfer'),
                DB::raw('COALESCE(re.total_realization, 0) as total_realization'),
                DB::raw('COALESCE(te.total_transfer, 0) - COALESCE(re.total_realization, 0) as saldo'),
                DB::raw('CASE WHEN COALESCE(te.total_transfer, 0) > 0
                              THEN ROUND(COALESCE(re.total_realization, 0)::numeric / COALESCE(te.total_transfer, 0) * 100, 2)
                              ELSE 0 END as realization_pct'),
                'missing_proof.pdo_detail_id as has_missing_proof',
            ]);

        // ── Filters ──────────────────────────────────────────
        if (!empty($filters['unit_id'])) {
            $query->where('ph.plantation_unit_id', $filters['unit_id']);
        }
        if (!empty($filters['period_year'])) {
            $query->where('ph.period_year', $filters['period_year']);
        }
        if (!empty($filters['period_month'])) {
            $query->where('ph.period_month', $filters['period_month']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('ec.id', $filters['category_id']);
        }
        if (!empty($filters['pdo_status'])) {
            $query->where('ph.status', $filters['pdo_status']);
        }

        $query->orderBy('ec.display_order')
              ->orderBy('es.display_order')
              ->orderBy('pd.display_order');

        return $query->get()->map(function ($row) {
            $row->status = $this->resolveStatus($row);
            return $row;
        });
    }

    /**
     * FR-07: Laporan Over Budget — hanya item dengan realisasi > transfer.
     */
    public function getOverBudgetData(array $filters): Collection
    {
        return $this->getRealizationData($filters)->filter(
            fn ($row) => (int) $row->total_realization > (int) $row->total_transfer
        )->values();
    }

    /**
     * FR-07: Laporan Bukti Belum Lengkap.
     */
    public function getMissingProofData(array $filters): Collection
    {
        $companyId = $filters['company_id'] ?? null;
        $threshold = (int) ($companyId
            ? SystemSetting::getValue($companyId, SystemSetting::KEY_THRESHOLD_PROOF, 1_000_000)
            : 1_000_000);

        $query = DB::table('realization_entries as ren')
            ->join('pdo_details as pd', 'pd.id', '=', 'ren.pdo_detail_id')
            ->join('pdo_headers as ph', 'ph.id', '=', 'pd.pdo_header_id')
            ->join('expense_items as ei', 'ei.id', '=', 'pd.expense_item_id')
            ->join('users as u', 'u.id', '=', 'ren.recorded_by')
            ->join('plantation_units as pu', 'pu.id', '=', 'ph.plantation_unit_id')
            ->leftJoin('realization_attachments as ra', 'ra.realization_entry_id', '=', 'ren.id')
            ->whereNull('ra.id')
            ->where('ren.amount', '>', $threshold)
            ->select([
                'ei.name as item_name',
                'pd.description as keterangan',
                'ren.transaction_date',
                'ren.amount',
                'u.full_name as recorded_by',
                'ph.pdo_number',
                'pu.name as unit_name',
            ])
            ->groupBy('ren.id', 'ei.name', 'pd.description', 'ren.transaction_date', 'ren.amount', 'u.full_name', 'ph.pdo_number', 'pu.name');

        if (!empty($filters['unit_id'])) {
            $query->where('ph.plantation_unit_id', $filters['unit_id']);
        }
        if (!empty($filters['period_year'])) {
            $query->where('ph.period_year', $filters['period_year']);
        }
        if (!empty($filters['period_month'])) {
            $query->where('ph.period_month', $filters['period_month']);
        }

        return $query->orderBy('ren.transaction_date', 'desc')->get();
    }

    /**
     * FR-07: Rekapitulasi Digital — struktur hierarkis Kategori → Sub-Kategori → Item.
     */
    public function getRecapData(array $filters): array
    {
        $rows = $this->getRealizationData(array_merge($filters, ['include_in_recap_only' => true]));

        $categories = [];
        $grandTotals = ['amount' => 0, 'transfer' => 0, 'realization' => 0, 'saldo' => 0];
        $catNo = 0;

        $groupedByCat = $rows->groupBy('category_id');

        foreach ($groupedByCat as $catId => $catRows) {
            $first = $catRows->first();
            $catNo++;
            $catTotals = ['amount' => 0, 'transfer' => 0, 'realization' => 0, 'saldo' => 0];
            $subcategories = [];

            foreach ($catRows->groupBy('subcategory_code') as $subRows) {
                $subFirst = $subRows->first();
                $subTotals = ['amount' => 0, 'transfer' => 0, 'realization' => 0, 'saldo' => 0];
                $items = [];
                $itemNo = 0;

                foreach ($subRows as $row) {
                    $itemNo++;
                    $items[] = [
                        'no'               => $itemNo,
                        'item_code'        => $row->item_code,
                        'item_name'        => $row->item_name,
                        'account_number'   => $row->account_number,
                        'amount'           => (int) $row->amount,
                        'total_transfer'   => (int) $row->total_transfer,
                        'total_realization'=> (int) $row->total_realization,
                        'saldo'            => (int) $row->saldo,
                    ];
                    $subTotals['amount']       += (int) $row->amount;
                    $subTotals['transfer']     += (int) $row->total_transfer;
                    $subTotals['realization']  += (int) $row->total_realization;
                    $subTotals['saldo']        += (int) $row->saldo;
                }

                $subcategories[] = [
                    'subcategory_code'       => $subFirst->subcategory_code,
                    'subcategory_name'       => $subFirst->subcategory_name,
                    'subtotal_amount'        => $subTotals['amount'],
                    'subtotal_transfer'      => $subTotals['transfer'],
                    'subtotal_realization'   => $subTotals['realization'],
                    'subtotal_saldo'         => $subTotals['saldo'],
                    'items'                  => $items,
                ];

                $catTotals['amount']      += $subTotals['amount'];
                $catTotals['transfer']    += $subTotals['transfer'];
                $catTotals['realization'] += $subTotals['realization'];
                $catTotals['saldo']       += $subTotals['saldo'];
            }

            $categories[] = [
                'no'                   => $catNo,
                'category_code'        => $first->category_code,
                'category_name'        => $first->category_name,
                'subtotal_amount'      => $catTotals['amount'],
                'subtotal_transfer'    => $catTotals['transfer'],
                'subtotal_realization' => $catTotals['realization'],
                'subtotal_saldo'       => $catTotals['saldo'],
                'subcategories'        => $subcategories,
            ];

            $grandTotals['amount']      += $catTotals['amount'];
            $grandTotals['transfer']    += $catTotals['transfer'];
            $grandTotals['realization'] += $catTotals['realization'];
            $grandTotals['saldo']       += $catTotals['saldo'];
        }

        return [
            'grand_total_amount'       => $grandTotals['amount'],
            'grand_total_transfer'     => $grandTotals['transfer'],
            'grand_total_realization'  => $grandTotals['realization'],
            'grand_total_saldo'        => $grandTotals['saldo'],
            'categories'               => $categories,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveStatus(object $row): string
    {
        $real     = (int) $row->total_realization;
        $transfer = (int) $row->total_transfer;

        if ($real === 0) {
            return 'belum_realisasi';
        }
        if ($real > $transfer) {
            return 'over_budget';
        }
        // Ada entry di atas threshold tanpa bukti
        if (!is_null($row->has_missing_proof)) {
            return 'belum_bukti';
        }
        if ($real === $transfer) {
            return 'sesuai';
        }
        return 'partial';
    }
}
