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
     * funding_source kas_kebun/rekening_kebun, DITAMBAH penyesuaian item potongan
     * (lihat buildDeductionAdjustments()). Saldo awal dihitung kumulatif sejak
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
        // Tidak difilter oleh tanggal transfer (hanya oleh periode PDO) agar
        // konsisten dengan Rekap Buku Kas yang juga tidak memfilter transfer_date.
        // Jika user set date range eksplisit, filter transfer_date diterapkan.
        $receiptsQuery = TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem');

        if ($startDate || $endDate) {
            $receiptsQuery->whereBetween('transfer_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()]);
        }

        $receipts = $receiptsQuery->get()
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

        $deductionAdjustments = $this->buildDeductionAdjustments($unitId, $year, $month, $startDate, $endDate, $effectiveStart, $effectiveEnd);

        $rows = $receipts->concat($expenses)->concat($deductionAdjustments)
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
                // Baris penyesuaian potongan punya amount NEGATIF (lihat
                // buildDeductionAdjustments()) — balance -= (negatif) menambah balance
                // kembali, dan totalPengeluaran += (negatif) mengurangi total pengeluaran,
                // dengan efek yang sama seperti baris pengeluaran biasa (formula tetap sama).
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

    /**
     * Item potongan (misal POTONGAN PANJAR) merepresentasikan uang muka/panjar yang
     * SUDAH direalisasikan (dibayar tunai) pada periode sebelumnya. Karena sistem
     * tidak bisa membebankan potongan itu ke item spesifik saat kerani mencatat
     * realisasi, kerani tetap mencatat realisasi PENUH sesuai anggaran tiap item,
     * sehingga total realisasi periode ini jadi lebih besar dari transfer riil yang
     * diterima — padahal sebagian dari realisasi itu bukan pengeluaran kas BARU
     * periode ini, melainkan duplikasi pencatatan dari kas yang sudah keluar
     * sebelumnya. Baris penyesuaian ini (pengeluaran NEGATIF, senilai transfer
     * potongan yang juga negatif) menetralkan duplikasi tersebut, supaya Total
     * Pengeluaran & Saldo Akhir Buku Kas Harian konsisten dengan Rekap Buku Kas
     * (lihat RecapQueryService::resolveRealization()).
     */
    private function buildDeductionAdjustments(string $unitId, int $year, int $month, ?string $startDate, ?string $endDate, Carbon $effectiveStart, Carbon $effectiveEnd)
    {
        $query = TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem');

        if ($startDate || $endDate) {
            $query->whereBetween('transfer_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()]);
        }

        return $query->get()->map(fn (TransferEntry $t) => [
            'date'        => $t->transfer_date->toDateString(),
            'type'        => 'pengeluaran',
            'reference'   => null,
            'description' => 'Penyesuaian potongan (sudah direalisasi periode sebelumnya) - ' . ($t->pdoDetail?->expenseItem?->name ?? 'Potongan'),
            'amount'      => (int) $t->amount, // negatif
            'created_at'  => $t->created_at,
        ]);
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

        // Netkan penyesuaian potongan periode-periode sebelumnya juga (lihat
        // buildDeductionAdjustments()), supaya saldo awal konsisten dengan cara
        // saldo berjalan dihitung di periode berjalan.
        $totalDeductionAdjustment = (int) TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->sum('amount'); // negatif

        return $totalReceipts - ($totalExpenses + $totalDeductionAdjustment);
    }
}
