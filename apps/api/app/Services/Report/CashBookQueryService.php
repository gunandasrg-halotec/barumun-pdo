<?php

namespace App\Services\Report;

use App\Models\RealizationEntry;
use App\Models\TransferEntry;
use Carbon\Carbon;

class CashBookQueryService
{
    private const RECEIPT_DESTINATIONS = [TransferEntry::DEST_REK_KEBUN];
    private const EXPENSE_FUNDING_SOURCES = [
        RealizationEntry::FUNDING_KAS_KEBUN,
        RealizationEntry::FUNDING_REKENING_KEBUN,
    ];

    /**
     * Buku kas harian kronologis untuk "kantong" kas kebun 1 unit dalam 1 periode PDO.
     *
     * Penerimaan = TransferEntry ke rek_kebun; pengeluaran = RealizationEntry dengan
     * funding_source kas_kebun/rekening_kebun. Saldo awal dihitung kumulatif sejak
     * transaksi paling pertama unit ini (lintas periode), bukan reset tiap bulan,
     * supaya saldo berjalan (running balance) mencerminkan kas kebun yang sesungguhnya.
     */
    public function getCashBookData(array $filters): array
    {
        $year      = (int) $filters['period_year'];
        $month     = (int) $filters['period_month'];
        $unitId    = $filters['unit_id'];
        $startDate = $filters['start_date'] ?? null;
        $endDate   = $filters['end_date']   ?? null;

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();

        $effectiveStart = $startDate ? Carbon::parse($startDate) : $periodStart;
        $effectiveEnd   = $endDate   ? Carbon::parse($endDate)   : $periodEnd;

        $openingBalance = $this->cumulativeBalanceBefore($unitId, $effectiveStart);

        // Penerimaan digabung per tanggal transfer — 1 baris per tanggal, jumlah
        // dijumlahkan, dan uraian mendaftar semua item biaya yang didanai hari itu.
        $receipts = TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->whereBetween('transfer_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
            ->with('pdoDetail.expenseItem')
            ->get()
            ->groupBy(fn (TransferEntry $t) => $t->transfer_date->toDateString())
            ->map(function ($group, $date) {
                $itemNames = $group
                    ->map(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->name ?? $t->notes ?? 'Transfer Dana')
                    ->unique()
                    ->values();

                return [
                    'date'        => $date,
                    'type'        => 'penerimaan',
                    'reference'   => null,
                    'description' => 'Terima transfer dari HO untuk : ' . $itemNames->implode(', '),
                    'amount'      => (int) $group->sum('amount'),
                    'created_at'  => $group->min('created_at'),
                ];
            })
            ->values();

        $expenses = RealizationEntry::query()
            ->whereIn('funding_source', self::EXPENSE_FUNDING_SOURCES)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->whereBetween('transaction_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
            ->with('pdoDetail.expenseItem')
            ->get()
            ->map(fn (RealizationEntry $r) => [
                'date'        => $r->transaction_date->toDateString(),
                'type'        => 'pengeluaran',
                'reference'   => $r->proof_number,
                'description' => $this->buildExpenseDescription($r),
                'amount'      => (int) $r->amount,
                'created_at'  => $r->created_at,
            ]);

        $rows = $receipts->concat($expenses)
            ->sortBy([['date', 'asc'], ['created_at', 'asc']])
            ->values();

        $balance = $openingBalance;
        $totalPenerimaan  = 0;
        $totalPengeluaran = 0;

        $rows = $rows->map(function (array $row) use (&$balance, &$totalPenerimaan, &$totalPengeluaran) {
            if ($row['type'] === 'penerimaan') {
                $balance += $row['amount'];
                $totalPenerimaan += $row['amount'];
            } else {
                $balance -= $row['amount'];
                $totalPengeluaran += $row['amount'];
            }
            unset($row['created_at']);
            $row['balance'] = $balance;

            return $row;
        })->values()->all();

        return [
            'opening_balance'   => $openingBalance,
            'closing_balance'   => $balance,
            'total_penerimaan'  => $totalPenerimaan,
            'total_pengeluaran' => $totalPengeluaran,
            'rows'              => $rows,
        ];
    }

    /** Uraian pengeluaran = kode item + nama item + catatan (jika ada). */
    private function buildExpenseDescription(RealizationEntry $r): string
    {
        $item = $r->pdoDetail?->expenseItem;

        $parts = array_filter([
            $item?->code,
            $item?->name,
            $r->explanation,
        ], fn ($v) => filled($v));

        return $parts ? implode(' - ', $parts) : 'Realisasi';
    }

    /**
     * Saldo kumulatif kas kebun unit ini dari seluruh transaksi SEBELUM $before
     * (lintas semua periode PDO), dipakai sebagai saldo awal (opening balance).
     */
    private function cumulativeBalanceBefore(string $unitId, Carbon $before): int
    {
        $totalReceipts = (int) TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->sum('amount');

        $totalExpenses = (int) RealizationEntry::query()
            ->whereIn('funding_source', self::EXPENSE_FUNDING_SOURCES)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transaction_date', '<', $before->toDateString())
            ->sum('amount');

        return $totalReceipts - $totalExpenses;
    }
}
