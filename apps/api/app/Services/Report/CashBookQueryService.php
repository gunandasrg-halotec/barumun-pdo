<?php

namespace App\Services\Report;

use App\Models\RealizationEntry;
use App\Models\TransferEntry;
use App\Models\UnitOpeningBalance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CashBookQueryService
{
    private const RECEIPT_DESTINATIONS_KEBUN   = [TransferEntry::DEST_REK_KEBUN];
    private const RECEIPT_DESTINATIONS_PRIBADI = [TransferEntry::DEST_PRIBADI, TransferEntry::DEST_VENDOR];
    private const EXPENSE_FUNDING_SOURCES_KEBUN = [
        RealizationEntry::FUNDING_KAS_KEBUN,
        RealizationEntry::FUNDING_REKENING_KEBUN,
    ];
    private const EXPENSE_FUNDING_SOURCES_PRIBADI = [RealizationEntry::FUNDING_REKENING_UTAMA];

    /**
     * Semua metode LAIN di file ini (openingBalanceForPeriod, closingBalanceForPeriod,
     * currentBalance — dipakai validasi saldo kas kebun & KPI Dashboard/Recap) SENGAJA
     * tidak menerima parameter kantong: makna aslinya memang murni kas fisik kas kebun,
     * bukan "kantong" yang bisa dipilih user. Hanya getCashBookData() (Buku Kas Harian)
     * yang mendukung kantong pribadi/vendor/semua — HO (role tanpa unit kebun) ingin
     * melihat dana yang ditransfer LANGSUNG oleh HO ke pribadi/vendor tanpa transit di
     * kas kebun. Formula saldo berjalan tetap sama persis (seed + penerimaan − realisasi),
     * hanya himpunan transaksi yang mendasarinya yang berbeda — secara akuntansi semua
     * tetap "dana operasional kebun", kantong hanya membedakan jalur transfernya.
     */
    private function receiptDestinationsFor(string $kantong): array
    {
        return match ($kantong) {
            'pribadi' => self::RECEIPT_DESTINATIONS_PRIBADI,
            'kebun'   => self::RECEIPT_DESTINATIONS_KEBUN,
            default   => [...self::RECEIPT_DESTINATIONS_KEBUN, ...self::RECEIPT_DESTINATIONS_PRIBADI],
        };
    }

    private function expenseFundingSourcesFor(string $kantong): array
    {
        return match ($kantong) {
            'pribadi' => self::EXPENSE_FUNDING_SOURCES_PRIBADI,
            'kebun'   => self::EXPENSE_FUNDING_SOURCES_KEBUN,
            default   => [...self::EXPENSE_FUNDING_SOURCES_KEBUN, ...self::EXPENSE_FUNDING_SOURCES_PRIBADI],
        };
    }

    /**
     * TransferEntry auto-generated untuk PDOT funding_option=kas_kebun BUKAN uang
     * baru masuk — dana sudah ada di kas kebun sebelum PDO ini (lihat
     * PdoSupplementaryApprovalService::mergeIntoParent()). Dikecualikan dari semua
     * agregasi "penerimaan" di file ini supaya tidak menggelembungkan saldo kas.
     * TIDAK memengaruhi RealizationEntryService — entri ini tetap dihitung di sana
     * supaya KERANI bisa merealisasikannya.
     */
    private function excludingKasKebunFunded(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('funding_option')->orWhere('funding_option', '!=', 'kas_kebun'));
    }

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
        $kantong   = $filters['kantong']    ?? 'kebun'; // 'kebun' | 'pribadi' | 'all'

        // 'subcategory' (default) = tampilan Buku Kas Harian yang lama, apa adanya.
        // 'item' = tampilan Buku Kas Harian Detail: baris pengeluaran dipecah per
        // (baris PDO, tanggal) dan Potongan Panjar ditampilkan sebagai baris tersendiri
        // di kedua sisi, bukan dilarutkan diam-diam. Whitelist di-normalisasi di sini,
        // bukan hanya di controller, karena service ini juga dipanggil langsung dari test.
        $groupBy = ($filters['group_by'] ?? 'subcategory') === 'item' ? 'item' : 'subcategory';

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();

        $effectiveStart = $startDate ? Carbon::parse($startDate) : $periodStart;
        $effectiveEnd   = $endDate   ? Carbon::parse($endDate)   : $periodEnd;

        $openingBalance = $this->cumulativeBalanceBefore($unitId, $effectiveStart, $kantong);

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
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem');

        if ($startDate || $endDate) {
            $receiptsQuery->whereBetween('transfer_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()]);
        }

        $receiptEntries = $receiptsQuery->get();

        // Mode 'item': entri Potongan Panjar dikeluarkan dari baris penerimaan supaya
        // baris itu menampilkan nilai BRUTO (sama dengan nilai PDO), lalu potongannya
        // muncul sebagai satu baris tersendiri tepat di bawahnya. Neto keduanya = dana
        // yang benar-benar masuk rekening, sehingga baris penerimaan bisa dicocokkan
        // langsung dengan rekening koran. Total penerimaan tidak berubah karena baris
        // potongan tetap bertipe 'penerimaan' dengan nilai negatif.
        $isReceiptDeduction = fn (TransferEntry $t) => (bool) $t->pdoDetail?->expenseItem?->is_deduction;
        $receiptDeductions  = $groupBy === 'item' ? $receiptEntries->filter($isReceiptDeduction) : collect();

        $receipts = ($groupBy === 'item' ? $receiptEntries->reject($isReceiptDeduction) : $receiptEntries)
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
                    'notes'       => null,
                    'vouchers'    => null,
                    'amount'      => (int) $group->sum('amount'),
                    'created_at'  => $group->min('created_at'),
                ];
            })
            ->values();

        $expenseRows = $this->buildExpenseRows($unitId, $year, $month, $effectiveStart, $effectiveEnd, $kantong, $groupBy);

        if ($receiptDeductions->isNotEmpty()) {
            $receipts = $this->withReceiptDeductionRow(
                $receipts,
                $receiptDeductions,
                collect($expenseRows)->min('date') ?? $effectiveStart->toDateString(),
            );
        }

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
     * Sisipkan SATU baris Potongan Panjar (tipe penerimaan, nilai negatif) tepat setelah
     * baris penerimaan paling awal — hanya dipakai mode detail.
     *
     * Ditempel di tanggal penerimaan pertama karena panjar dipotong sekali di awal bulan,
     * saat dana pertama dicairkan: baris bruto lalu baris potongan, sehingga neto tanggal
     * itu = nominal yang benar-benar masuk rekening kebun.
     *
     * `created_at` disamakan dengan baris induknya; `sortBy` PHP stabil sehingga urutan
     * penyisipan (induk dulu, potongan menyusul) tetap terjaga setelah pengurutan.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $receipts
     * @param  \Illuminate\Support\Collection<int, TransferEntry>  $deductionEntries
     */
    private function withReceiptDeductionRow($receipts, $deductionEntries, string $fallbackDate)
    {
        $names = $deductionEntries
            ->map(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->name)
            ->filter()
            ->unique()
            ->values();

        $row = [
            'date'        => $fallbackDate,
            'type'        => 'penerimaan',
            'reference'   => null,
            'description' => 'Potongan Panjar : ' . ($names->isNotEmpty() ? $names->implode(', ') : 'uang muka periode sebelumnya'),
            'notes'       => null,
            'vouchers'    => null,
            'amount'      => (int) $deductionEntries->sum('amount'), // negatif
            'created_at'  => $deductionEntries->min('created_at'),
        ];

        if ($receipts->isEmpty()) {
            return $receipts->push($row)->values();
        }

        $anchorDate  = $receipts->min('date');
        $anchorIndex = $receipts->search(fn (array $r) => $r['date'] === $anchorDate);

        $row['date']       = $anchorDate;
        $row['created_at'] = $receipts[$anchorIndex]['created_at'];

        $all = $receipts->values()->all();
        array_splice($all, $anchorIndex + 1, 0, [$row]);

        return collect($all);
    }

    /**
     * Varian PRO-RATA dari allocateDeductionCredit() — khusus mode detail (group_by=item).
     *
     * Bedanya hanya CARA MEMBAGI, bukan total: di mode lama kredit panjar dihabiskan dari
     * grup terbesar lebih dulu (cocok untuk baris gabungan per sub-kategori), sedangkan di
     * mode detail tiap baris adalah satu item sehingga panjar dibagi sebanding nilai biaya
     * masing-masing. Sub-kategori yang hanya berisi satu item otomatis menerima potongan penuh.
     *
     * Dua fase, sama seperti versi lama:
     *   1. Tiap panjar dibagi pro-rata ke grup-grup di SUB-KATEGORI-nya sendiri.
     *   2. Sisa yang tidak tertampung dikumpulkan jadi pool KATEGORI, lalu dibagi pro-rata
     *      ke sisa kapasitas grup mana pun di kategori itu.
     *
     * Karena tiap fase mengisi tepat min(kredit, sisa kapasitas), total kredit terpakai per
     * kategori = min(|panjar|, total realisasi kategori) — identik dengan versi lama, itulah
     * yang menjamin total & saldo Buku Kas Harian Detail sama persis dengan Buku Kas Harian.
     *
     * Invarian itu mengandaikan realization_entries.amount SELALU > 0 (divalidasi min:1 di
     * StoreRealizationEntryRequest/UpdateRealizationEntryRequest). Kalau suatu saat ada entri
     * bernilai <= 0, memecah grup lebih halus bisa mengubah total kapasitas kategori sehingga
     * kedua mode tidak lagi menghasilkan total yang sama.
     *
     * Sub-kategori tiap grup dibaca dari entri di dalamnya, BUKAN dari string key — jadi
     * fungsi ini tidak ikut terikat pada format key seperti allocateDeductionCredit().
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, RealizationEntry>>  $dateGroups
     * @param  array<string, int>  $deductionBySubcategory  panjar KATEGORI INI saja, nilai negatif
     * @return array<string, int>  key grup => kredit terpakai (positif)
     */
    private function allocateDeductionCreditProRata($dateGroups, array $deductionBySubcategory): array
    {
        $capacity = [];
        $subOf    = [];
        foreach ($dateGroups as $key => $group) {
            $amount = (int) $group->sum('amount');
            if ($amount > 0) {
                $capacity[$key] = $amount;
                $subOf[$key]    = (string) ($group->first()?->pdoDetail?->expenseItem?->subcategory_id ?? 'unknown');
            }
        }

        if (empty($capacity)) {
            return [];
        }

        $applied = [];

        // Bagi $credit ke $keys sebanding SISA kapasitas tiap grup. Pembagian dibulatkan ke
        // bawah, lalu sisa rupiahnya dibagikan dengan metode largest remainder (tie-break:
        // sisa kapasitas terbesar, lalu key) supaya jumlah porsi persis sama dengan kredit
        // yang dialokasikan — tidak ada rupiah yang hilang atau berlebih karena pembulatan.
        $distribute = function (array $keys, int $credit) use (&$applied, $capacity): int {
            $room = [];
            foreach ($keys as $key) {
                $left = $capacity[$key] - ($applied[$key] ?? 0);
                if ($left > 0) {
                    $room[$key] = $left;
                }
            }

            $totalRoom = array_sum($room);
            if ($credit <= 0 || $totalRoom <= 0) {
                return $credit;
            }

            if ($credit >= $totalRoom) {
                foreach ($room as $key => $left) {
                    $applied[$key] = ($applied[$key] ?? 0) + $left;
                }

                return $credit - $totalRoom;
            }

            $share = [];
            $frac  = [];
            $given = 0;
            foreach ($room as $key => $left) {
                $exact       = $credit * $left / $totalRoom;
                $share[$key] = (int) floor($exact);
                $frac[$key]  = $exact - $share[$key];
                $given      += $share[$key];
            }

            $rest = $credit - $given;
            if ($rest > 0) {
                $order = array_keys($room);
                usort($order, fn ($a, $b) => [$frac[$b], $room[$b], $a] <=> [$frac[$a], $room[$a], $b]);
                foreach ($order as $key) {
                    if ($rest <= 0) {
                        break;
                    }
                    $share[$key]++;
                    $rest--;
                }
            }

            foreach ($share as $key => $value) {
                if ($value > 0) {
                    $applied[$key] = ($applied[$key] ?? 0) + $value;
                }
            }

            return 0;
        };

        // Fase 1 — tiap panjar dibagi pro-rata di sub-kategorinya sendiri.
        $spillover = 0;
        foreach ($deductionBySubcategory as $subcategoryId => $deduction) {
            $credit = abs((int) $deduction);
            if ($credit === 0) {
                continue;
            }

            $ownKeys = array_values(array_filter(
                array_keys($capacity),
                fn ($key) => $subOf[$key] === (string) $subcategoryId
            ));

            $spillover += $distribute($ownKeys, $credit);
        }

        // Fase 2 — sisanya meluber pro-rata ke sub-kategori tetangga dalam kategori yang sama.
        if ($spillover > 0) {
            $distribute(array_keys($capacity), $spillover);
        }

        return $applied;
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
     * Saldo Kas Kebun unit ini per HARI INI (kumulatif sejak transaksi pertama).
     * Dipakai untuk memvalidasi bahwa pengembalian sisa dana tidak melebihi kas
     * yang benar-benar tersedia.
     */
    public function currentBalance(string $unitId): int
    {
        return $this->cumulativeBalanceBefore($unitId, Carbon::tomorrow());
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
     * sendiri) dinetkan ke baris pengeluaran, dengan urutan konsumsi:
     *
     *   1. SUB-KATEGORI panjarnya sendiri, grup TERBESAR lebih dulu.
     *   2. Sisanya baru meluber ke sub-kategori tetangga dalam KATEGORI yang sama,
     *      juga terbesar lebih dulu.
     *
     * Skop TOTAL-nya tetap KATEGORI (wajib sama dengan RecapQueryService,
     * PdoService::effectiveRealizedSql(), dan cumulativeBalanceBefore()) — kerani
     * memperkirakan beban pekerjaan di muka dan perkiraan itu bisa meleset,
     * sehingga panjar satu sub-kategori bisa melebihi biaya riilnya (kasus Binanga
     * Juli 2026); pekerja yang sama umumnya juga mengerjakan sub-kategori lain di
     * kategori itu, jadi kelebihannya wajar diserap tetangga. Yang berubah hanya
     * URUTAN konsumsinya, bukan totalnya.
     *
     * Urutan lama (grup tanggal paling awal di kategori, tanpa melihat sub-kategori)
     * membuat kredit panjar memakan transaksi yang sama sekali tidak dipanjar —
     * KP Agustus 2026: panjar Supir Truck Harian menghabiskan Upah Muat Pupuk
     * (Rp 395.000, sub-kategori Pemuat) tanggal 1 Agustus, dan panjar kategori
     * Panen menelan seluruh lembur & perobatan Mandor Panen (Rp 921.538) yang tidak
     * punya panjar sama sekali. Baris-baris itu tampil Rp 0 di Buku Kas Harian
     * padahal uangnya benar-benar keluar.
     *
     * "Terbesar lebih dulu" dipilih karena panjar di dunia nyata dilunasi saat
     * pembayaran upah utama (hari gajian), bukan dari biaya kecil harian — dengan
     * begitu pengeluaran insidentil kecil tidak lagi tergerus jadi Rp 0. Hasilnya
     * cocok baris-per-baris dengan buku kas manual kebun.
     */
    private function buildExpenseRows(string $unitId, int $year, int $month, Carbon $effectiveStart, Carbon $effectiveEnd, string $kantong = 'kebun', string $groupBy = 'subcategory'): array
    {
        $entries = RealizationEntry::query()
            ->whereIn('funding_source', $this->expenseFundingSourcesFor($kantong))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->whereBetween('transaction_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
            ->with(['pdoDetail.expenseItem.subcategory.category', 'pettyCashVoucherLine.pettyCashVoucher'])
            ->get();

        $deductions = TransferEntry::query()
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem.subcategory')
            ->get();

        // Panjar per KATEGORI => per SUB-KATEGORI (semuanya negatif). Dikelompokkan
        // dua level supaya kredit satu kategori tidak pernah bocor ke kategori lain
        // saat meluber di fase 2 allocateDeductionCredit().
        $deductionByCategorySubcategory = $deductions
            ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory?->category_id)
            ->map(fn ($group) => $group
                ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory_id)
                ->map(fn ($sub) => (int) $sub->sum('amount'))
                ->all());

        // Nama item panjar per sub-kategori & per kategori — hanya dipakai untuk menulis
        // uraian baris potongan di mode detail.
        $deductionNames = fn ($group) => $group
            ->map(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->name)
            ->filter()->unique()->implode(', ');

        $deductionNamesBySubcategory = $deductions
            ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory_id)
            ->map($deductionNames)->all();

        $deductionNamesByCategory = $deductions
            ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory?->category_id)
            ->map($deductionNames)->all();

        $rows = [];

        $entries
            ->groupBy(fn (RealizationEntry $r) => $r->pdoDetail?->expenseItem?->subcategory?->category_id ?? 'unknown')
            ->each(function ($categoryEntries, $categoryId) use (&$rows, $deductionByCategorySubcategory, $groupBy, $deductionNamesBySubcategory, $deductionNamesByCategory) {
                // Baris tetap digabung per (subkategori, tanggal). Tanggal di depan key
                // supaya sortKeys() menghasilkan urutan TAMPILAN kronologis; urutan
                // KONSUMSI kredit dihitung terpisah di bawah.
                // Segmen 0 (tanggal) dan 1 (subcategory_id) WAJIB tetap di posisinya:
                // di bawah ini tanggal dibaca lewat explode('|',$key)[0], dan
                // allocateDeductionCredit() membaca explode('|',$key)[1] sebagai
                // subcategory_id untuk mengurung kredit panjar di sub-kategorinya sendiri.
                // Segmen ke-3 (pdo_detail_id) HANYA ditambahkan di mode detail — memakai
                // ternary eksplisit supaya mode lama tidak kebagian segmen kosong.
                $dateGroups = $categoryEntries
                    ->groupBy(function (RealizationEntry $r) use ($groupBy) {
                        $key = $r->transaction_date->toDateString()
                            .'|'.($r->pdoDetail?->expenseItem?->subcategory_id ?? 'unknown');

                        return $groupBy === 'item' ? $key.'|'.($r->pdo_detail_id ?? 'unknown') : $key;
                    })
                    ->sortKeys();

                $appliedByGroup = $groupBy === 'item'
                    ? $this->allocateDeductionCreditProRata($dateGroups, $deductionByCategorySubcategory[$categoryId] ?? [])
                    : $this->allocateDeductionCredit($dateGroups, $deductionByCategorySubcategory[$categoryId] ?? []);

                foreach ($dateGroups as $groupKey => $group) {
                    $date = explode('|', $groupKey)[0];

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

                    // Catatan item (pdo_details.notes) — untuk item asal PDO Tambahan ini
                    // sudah berisi teks Justifikasi (disalin saat merge, lihat
                    // PdoSupplementaryApprovalService::mergeIntoParent()). Dikumpulkan
                    // terpisah dari $lines (bukan digabung ke description) supaya
                    // frontend bisa menampilkannya dengan gaya berbeda (italic/muted).
                    $notesLines = $group
                        ->map(fn (RealizationEntry $r) => $r->pdoDetail?->notes)
                        ->filter()
                        ->unique()
                        ->values();

                    $references = $group
                        ->map(fn (RealizationEntry $r) => $r->proof_number)
                        ->filter()
                        ->unique()
                        ->values();

                    // Voucher Petty Cash yang menghasilkan entri-entri di grup ini (§3g
                    // keterlacakan) — pola identik $notesLines, field array terstruktur
                    // terpisah supaya frontend bisa render tiap voucher sebagai chip.
                    $vouchers = $group
                        ->map(fn (RealizationEntry $r) => $r->pettyCashVoucherLine?->pettyCashVoucher)
                        ->filter()
                        ->unique('id')
                        ->map(fn ($v) => ['id' => $v->id, 'voucher_number' => $v->voucher_number])
                        ->values();

                    // Mode lama: kredit panjar dilarutkan ke nilai baris. Mode detail: nilai
                    // baris tetap PENUH sesuai realisasi, dan kreditnya muncul sebagai baris
                    // tersendiri di bawah — jumlah keduanya sama, jadi saldo tidak bergeser.
                    $applied = (int) ($appliedByGroup[$groupKey] ?? 0);
                    $amount  = (int) $group->sum('amount') - ($groupBy === 'item' ? 0 : $applied);

                    $rows[] = [
                        'date'        => $date,
                        'type'        => 'pengeluaran',
                        'reference'   => $references->isNotEmpty() ? $references->implode("\n") : null,
                        'description' => $lines->implode("\n"),
                        'notes'       => $notesLines->isNotEmpty() ? $notesLines->implode("\n") : null,
                        'vouchers'    => $vouchers->isNotEmpty() ? $vouchers->all() : null,
                        'amount'      => $amount,
                        'created_at'  => $group->min('created_at'),
                    ];

                    if ($groupBy === 'item' && $applied > 0) {
                        $subId = (string) ($group->first()?->pdoDetail?->expenseItem?->subcategory_id ?? 'unknown');
                        $own   = $deductionNamesBySubcategory[$subId] ?? '';

                        $rows[] = [
                            'date'        => $date,
                            'type'        => 'pengeluaran',
                            'reference'   => null,
                            'description' => $own !== ''
                                ? 'Potongan Panjar — '.$own
                                : 'Potongan Panjar — '.($deductionNamesByCategory[$categoryId] ?? 'uang muka periode sebelumnya').' (alokasi dari sub-kategori lain)',
                            'notes'       => null,
                            'vouchers'    => null,
                            'amount'      => -$applied,
                            // created_at disamakan dengan baris induk supaya sortBy yang stabil
                            // menempatkan baris ini persis di bawahnya.
                            'created_at'  => $group->min('created_at'),
                        ];
                    }
                }
            });

        return $rows;
    }

    /**
     * Tentukan berapa kredit potongan yang dipakai TIAP grup baris dalam 1 kategori.
     *
     * Dua fase, keduanya mengonsumsi grup TERBESAR lebih dulu (panjar dilunasi saat
     * pembayaran upah utama, bukan dari biaya kecil harian):
     *   1. Tiap panjar dikonsumsi dari grup-grup di SUB-KATEGORI-nya sendiri.
     *   2. Sisa yang tidak terserap sub-kategorinya sendiri dikumpulkan jadi satu pool
     *      kategori, lalu dikonsumsi dari sisa kapasitas grup mana pun di kategori itu.
     *
     * Grup tidak pernah dibuat negatif, sehingga TOTAL kredit terpakai per kategori
     * otomatis = min(|panjar kategori|, total realisasi kategori) — persis
     * DeductionNetting::usableCredit() yang dipakai RecapQueryService, PdoService,
     * dan cumulativeBalanceBefore(). Fase 2 memastikan clamp-nya tetap di level
     * KATEGORI, bukan sub-kategori.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, RealizationEntry>>  $dateGroups  key: "{tanggal}|{subcategory_id}"
     * @param  array<string, int>  $deductionBySubcategory  panjar KATEGORI INI saja, nilai negatif
     * @return array<string, int>  key grup => kredit terpakai (positif)
     */
    private function allocateDeductionCredit($dateGroups, array $deductionBySubcategory): array
    {
        // Kapasitas tiap grup = nilai realisasinya (grup nol/negatif tidak menyerap apa pun).
        $capacity = [];
        foreach ($dateGroups as $key => $group) {
            $amount = (int) $group->sum('amount');
            if ($amount > 0) {
                $capacity[$key] = $amount;
            }
        }

        if (empty($capacity)) {
            return [];
        }

        $applied  = [];
        $subOfKey = fn (string $key): string => explode('|', $key)[1] ?? '';

        // Terbesar lebih dulu; key dipakai sebagai tie-breaker supaya hasilnya deterministik.
        $byLargest = function (array $keys) use ($capacity): array {
            usort($keys, fn ($a, $b) => [$capacity[$b], $a] <=> [$capacity[$a], $b]);

            return $keys;
        };

        $consume = function (array $keys, int $credit) use (&$applied, $capacity): int {
            foreach ($keys as $key) {
                if ($credit <= 0) {
                    break;
                }
                $room = $capacity[$key] - ($applied[$key] ?? 0);
                if ($room <= 0) {
                    continue;
                }
                $take           = min($room, $credit);
                $applied[$key]  = ($applied[$key] ?? 0) + $take;
                $credit        -= $take;
            }

            return $credit; // sisa yang belum terserap
        };

        // Fase 1 — tiap panjar diserap sub-kategorinya sendiri.
        $spillover = 0;
        foreach ($deductionBySubcategory as $subcategoryId => $deduction) {
            $credit = abs((int) $deduction);
            if ($credit === 0) {
                continue;
            }

            $ownKeys = $byLargest(array_values(array_filter(
                array_keys($capacity),
                fn ($key) => $subOfKey($key) === (string) $subcategoryId
            )));

            $spillover += $consume($ownKeys, $credit);
        }

        // Fase 2 — sisanya meluber ke sub-kategori tetangga dalam kategori yang sama.
        if ($spillover > 0) {
            $consume($byLargest(array_keys($capacity)), $spillover);
        }

        return $applied;
    }

    /**
     * Saldo kumulatif kas kebun unit ini dari seluruh transaksi SEBELUM $before
     * (lintas semua periode PDO), dipakai sebagai saldo awal (opening balance).
     *
     * Ditambah saldo awal (seed) per unit — saldo kas kebun akhir Juni 2026
     * sebelum sistem PDO dipakai, lihat UnitOpeningBalance — supaya saldo
     * berjalan akurat sejak titik mulai pemakaian sistem, bukan mulai dari nol.
     * Seed ini MURNI kas fisik kas kebun (hasil hitung tunai saat cutover),
     * jadi HANYA disertakan kalau kantong mencakup kebun ('kebun'/'all').
     * Kantong 'pribadi' murni tidak pernah menyimpan kas fisik — dana
     * ditransfer langsung HO ke rekening pribadi/vendor dan dibelanjakan,
     * tidak pernah "dipegang" siapa pun — sehingga tidak punya saldo awal.
     *
     * Potongan (transfer negatif) mengurangi total pengeluaran, tapi HANYA
     * sebatas realisasi yang benar-benar sudah tercatat di (PDO, KATEGORI) yang
     * sama — lihat DeductionNetting.
     *
     * Dikelompokkan per PDO HEADER (bukan lintas seluruh riwayat) karena tiap PDO
     * punya potongannya sendiri; tanpa itu, realisasi PDO bulan lalu bisa keliru
     * dianggap "mengkompensasi" potongan bulan ini. Di dalam satu PDO, skopnya
     * KATEGORI — sama persis dengan buildExpenseRows(), RecapQueryService,
     * PdoService::effectiveRealizedSql(), dan
     * RealizationEntryService::totalRealizedForGroup(). Skop PDO-wide yang lama
     * membuat saldo kumulatif (dipakai Saldo Awal, validasi PDOT Kas Kebun, dan
     * KPI Dashboard) berbeda dari saldo akhir Buku Kas Harian bulan yang sama
     * begitu ada kategori yang panjarnya melebihi realisasi kategori itu
     * sementara kategori lain surplus.
     *
     * Tanpa clamp, potongan dikreditkan balik sebelum realisasi penyeimbangnya
     * tercatat sehingga saldo kelebihan (PDO Agustus Sosa: saldo tampil
     * Rp 24.626.864 padahal seharusnya Rp 20.126.864). Sebaliknya, tanpa netting
     * sama sekali saldo jadi kekurangan pada periode yang realisasinya sudah
     * lengkap (PDO Juli: KP −7.026.778 padahal seharusnya 4.073.222).
     */
    private function cumulativeBalanceBefore(string $unitId, Carbon $before, string $kantong = 'kebun'): int
    {
        $seed = $kantong === 'pribadi' ? 0 : UnitOpeningBalance::amountForUnit($unitId);

        $totalReceipts = (int) TransferEntry::query()
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->sum('amount');

        // Kunci grup = "{pdo_header_id}|{category_id}". toBase() dipakai supaya
        // hasil agregat tidak dihidrasi jadi model RealizationEntry/TransferEntry
        // (kolomnya bukan kolom tabel aslinya).
        $realizationByGroup = RealizationEntry::query()
            ->whereIn('funding_source', $this->expenseFundingSourcesFor($kantong))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transaction_date', '<', $before->toDateString())
            ->join('pdo_details', 'pdo_details.id', '=', 'realization_entries.pdo_detail_id')
            ->join('expense_items', 'expense_items.id', '=', 'pdo_details.expense_item_id')
            ->join('expense_subcategories', 'expense_subcategories.id', '=', 'expense_items.subcategory_id')
            ->selectRaw('pdo_details.pdo_header_id as pdo_id, expense_subcategories.category_id as cat_id, SUM(realization_entries.amount) as total')
            ->groupBy('pdo_details.pdo_header_id', 'expense_subcategories.category_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [$r->pdo_id . '|' . $r->cat_id => (int) $r->total]);

        $deductionByGroup = TransferEntry::query()
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->join('pdo_details', 'pdo_details.id', '=', 'transfer_entries.pdo_detail_id')
            ->join('expense_items', 'expense_items.id', '=', 'pdo_details.expense_item_id')
            ->join('expense_subcategories', 'expense_subcategories.id', '=', 'expense_items.subcategory_id')
            ->selectRaw('pdo_details.pdo_header_id as pdo_id, expense_subcategories.category_id as cat_id, SUM(transfer_entries.amount) as total')
            ->groupBy('pdo_details.pdo_header_id', 'expense_subcategories.category_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [$r->pdo_id . '|' . $r->cat_id => (int) $r->total]); // negatif

        $totalExpenses = 0;
        foreach ($realizationByGroup->keys()->merge($deductionByGroup->keys())->unique() as $key) {
            $totalExpenses += DeductionNetting::effectiveRealization(
                (int) ($realizationByGroup[$key] ?? 0),
                (int) ($deductionByGroup[$key] ?? 0),
            );
        }

        return $seed + $totalReceipts - $totalExpenses;
    }
}
