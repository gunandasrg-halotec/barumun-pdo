<?php

namespace App\Services\Report;

use App\Models\RealizationEntry;
use App\Models\TransferEntry;
use App\Models\UnitOpeningBalance;
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
     * Penerimaan = TransferEntry ke rek_kebun, digabung per tanggal. Pengeluaran =
     * RealizationEntry dengan funding_source kas_kebun/rekening_kebun, digabung per
     * (subkategori, tanggal transaksi) — lihat buildExpenseRows() — supaya baris
     * tidak terlalu banyak, dan item Potongan Panjar (down payment yang sudah
     * direalisasikan periode sebelumnya) dinetkan ke grup tanggal paling awal dalam
     * subkategori yang sama. Saldo awal dihitung kumulatif sejak transaksi paling
     * pertama unit ini (lintas periode), bukan reset tiap bulan.
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
        // Transfer item Potongan Panjar (is_deduction) TETAP ikut dijumlahkan di sini
        // (raw, negatif) — ini sesuai data akuntansi riil, yang mencatat dana
        // ditransfer per tanggal SUDAH termasuk potongan tsb (bukan dipisah).
        // Deduction ini JUGA dinetkan ke baris pengeluaran (buildExpenseRows) —
        // itu bukan double counting: satu representasi "dana masuk lebih kecil dari
        // biasa" (di sini), satu representasi "pengeluaran lebih kecil dari laporan
        // kerani" (di baris pengeluaran) — keduanya sama-sama valid & saling
        // mengoreksi supaya saldo berjalan tetap akurat (lihat cumulativeBalanceBefore
        // yang membuktikan secara matematis kedua sisi saling menetralkan).
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

        $expenseRows = $this->buildExpenseRows($unitId, $year, $month, $effectiveStart, $effectiveEnd);

        $rows = $receipts->concat($expenseRows)
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

    /**
     * Saldo kas kebun unit ini di AWAL periode PDO (year/month) — dipakai sebagai
     * KPI "Saldo Awal" di halaman Rekap, TIDAK terpengaruh filter tanggal
     * (start_date/end_date) yang dipakai di tabel Buku Kas Harian.
     */
    public function openingBalanceForPeriod(string $unitId, int $year, int $month): int
    {
        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();

        return $this->cumulativeBalanceBefore($unitId, $periodStart);
    }

    /**
     * Saldo kas kebun kumulatif unit ini per AKHIR periode PDO (year/month) —
     * dipakai untuk KPI "Saldo Kas Kebun" di Dashboard, supaya konsisten dengan
     * saldo akhir (closing balance) di Buku Kas Harian bulan yang sama.
     */
    public function closingBalanceForPeriod(string $unitId, int $year, int $month): int
    {
        $nextMonthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth()->addMonthNoOverflow();

        return $this->cumulativeBalanceBefore($unitId, $nextMonthStart);
    }

    /**
     * Baris pengeluaran Buku Kas Harian, digabung per (subkategori, tanggal
     * transaksi) supaya tabel tidak terlalu panjang — item-item dalam 1
     * subkategori yang direalisasikan di tanggal yang sama (mis. GAJI + CATU
     * BERAS) jadi 1 baris.
     *
     * Item Potongan Panjar (down payment yang SUDAH direalisasikan periode
     * sebelumnya — direpresentasikan sebagai TransferEntry negatif, bukan
     * RealizationEntry, sehingga tidak pernah muncul sebagai baris pengeluaran
     * sendiri) dinetkan (dikurangkan) HANYA ke grup tanggal PALING AWAL dalam
     * subkategori yang sama — mewakili "biaya bulan lalu yang di-settle bulan
     * ini". Grup tanggal berikutnya dalam subkategori yang sama (mis. Panjar
     * Gaji yang direalisasikan pertengahan bulan) tidak lagi dikurangi.
     */
    private function buildExpenseRows(string $unitId, int $year, int $month, Carbon $effectiveStart, Carbon $effectiveEnd): array
    {
        $entries = RealizationEntry::query()
            ->whereIn('funding_source', self::EXPENSE_FUNDING_SOURCES)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->whereBetween('transaction_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
            ->with('pdoDetail.expenseItem.subcategory.category')
            ->get();

        $deductionBySubcategory = TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem')
            ->get()
            ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory_id)
            ->map(fn ($group) => (int) $group->sum('amount')); // negatif

        $rows = [];

        $entries
            ->groupBy(fn (RealizationEntry $r) => $r->pdoDetail?->expenseItem?->subcategory_id ?? 'unknown')
            ->each(function ($subcategoryEntries, $subcategoryId) use (&$rows, $deductionBySubcategory) {
                $deductionRemaining = (int) ($deductionBySubcategory[$subcategoryId] ?? 0); // negatif atau 0

                $dateGroups = $subcategoryEntries
                    ->groupBy(fn (RealizationEntry $r) => $r->transaction_date->toDateString())
                    ->sortKeys(); // tanggal paling awal duluan

                foreach ($dateGroups as $date => $group) {
                    $lines = $group->map(function (RealizationEntry $r) {
                        $item = $r->pdoDetail?->expenseItem;
                        $cat  = $item?->subcategory?->category?->name;
                        $sub  = $item?->subcategory?->name;
                        $name = $item?->name ?? 'Realisasi';

                        $label = implode(' — ', array_filter([$cat, $sub, $name]));
                        if (! empty($r->explanation)) {
                            $label .= ' (' . $r->explanation . ')';
                        }

                        return $label;
                    })->values();

                    $references = $group
                        ->map(fn (RealizationEntry $r) => $r->proof_number)
                        ->filter()
                        ->unique()
                        ->values();

                    $amount = (int) $group->sum('amount');

                    // Netkan potongan HANYA ke grup tanggal paling awal dalam
                    // subkategori ini, lalu "habiskan" supaya tidak dipakai lagi
                    // untuk grup tanggal berikutnya.
                    if ($deductionRemaining !== 0) {
                        $amount += $deductionRemaining; // deductionRemaining negatif
                        $deductionRemaining = 0;
                    }

                    $rows[] = [
                        'date'        => $date,
                        'type'        => 'pengeluaran',
                        'reference'   => $references->isNotEmpty() ? $references->implode("\n") : null,
                        'description' => $lines->implode("\n"),
                        'amount'      => $amount,
                        'created_at'  => $group->min('created_at'),
                    ];
                }
            });

        return $rows;
    }

    /**
     * Saldo kumulatif kas kebun unit ini dari seluruh transaksi SEBELUM $before
     * (lintas semua periode PDO), dipakai sebagai saldo awal (opening balance).
     *
     * Ditambah saldo awal (seed) per unit — saldo kas kebun akhir Juni 2026
     * sebelum sistem PDO dipakai, lihat UnitOpeningBalance — supaya saldo
     * berjalan akurat sejak titik mulai pemakaian sistem, bukan mulai dari nol.
     *
     * Potongan periode-periode sebelumnya juga dinetkan di sini (mengurangi
     * totalExpenses, karena dana itu sebenarnya sudah keluar sebelum periode
     * tsb, bukan pengeluaran baru) — supaya saldo berjalan tetap akurat lintas
     * periode, konsisten dengan penetapan di buildExpenseRows().
     */
    private function cumulativeBalanceBefore(string $unitId, Carbon $before): int
    {
        $seed = UnitOpeningBalance::amountForUnit($unitId);

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

        $totalDeduction = (int) TransferEntry::query()
            ->whereIn('transfer_destination', self::RECEIPT_DESTINATIONS)
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->sum('amount'); // negatif

        return $seed + $totalReceipts - ($totalExpenses + $totalDeduction);
    }
}
