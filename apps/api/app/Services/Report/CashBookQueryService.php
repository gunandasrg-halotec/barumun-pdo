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
                    'notes'       => null,
                    'vouchers'    => null,
                    'amount'      => (int) $group->sum('amount'),
                    'created_at'  => $group->min('created_at'),
                ];
            })
            ->values();

        $expenseRows = $this->buildExpenseRows($unitId, $year, $month, $effectiveStart, $effectiveEnd, $kantong);

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
     * sendiri) dinetkan (dikurangkan) mulai dari grup tanggal PALING AWAL dalam
     * KATEGORI yang sama — mewakili "biaya bulan lalu yang di-settle bulan ini".
     * Grup tanggal berikutnya baru ikut dikurangi kalau kreditnya belum habis.
     *
     * Skop kredit sengaja KATEGORI, bukan subkategori: kerani memperkirakan beban
     * pekerjaan di muka dan perkiraan itu bisa meleset, sehingga panjar satu
     * subkategori bisa melebihi biaya riilnya (kasus Binanga Juli 2026). Pekerja
     * yang sama umumnya juga mengerjakan subkategori lain di kategori yang sama,
     * jadi kelebihan panjar wajar diserap subkategori tetangga. Skop ini WAJIB
     * sama dengan RecapQueryService supaya Buku Kas Harian dan Rekap Buku Kas
     * selalu menghasilkan angka yang sama.
     */
    private function buildExpenseRows(string $unitId, int $year, int $month, Carbon $effectiveStart, Carbon $effectiveEnd, string $kantong = 'kebun'): array
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

        $deductionByCategory = TransferEntry::query()
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q
                ->where('plantation_unit_id', $unitId)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->with('pdoDetail.expenseItem.subcategory')
            ->get()
            ->groupBy(fn (TransferEntry $t) => $t->pdoDetail?->expenseItem?->subcategory?->category_id)
            ->map(fn ($group) => (int) $group->sum('amount')); // negatif

        $rows = [];

        $entries
            ->groupBy(fn (RealizationEntry $r) => $r->pdoDetail?->expenseItem?->subcategory?->category_id ?? 'unknown')
            ->each(function ($categoryEntries, $categoryId) use (&$rows, $deductionByCategory) {
                $deductionRemaining = (int) ($deductionByCategory[$categoryId] ?? 0); // negatif atau 0

                // Baris tetap digabung per (subkategori, tanggal) seperti sebelumnya —
                // yang berubah hanya SKOP kredit potongan. Tanggal ditaruh di depan key
                // supaya sortKeys() mengurutkan per tanggal lintas subkategori, sehingga
                // kredit selalu dikonsumsi dari pengeluaran paling awal di kategori ini.
                $dateGroups = $categoryEntries
                    ->groupBy(fn (RealizationEntry $r) => $r->transaction_date->toDateString()
                        .'|'.($r->pdoDetail?->expenseItem?->subcategory_id ?? 'unknown'))
                    ->sortKeys();

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

                    $amount = (int) $group->sum('amount');

                    // Netkan potongan mulai dari grup tanggal paling awal dalam
                    // KATEGORI ini. Kredit dibatasi sebesar nilai grup itu supaya
                    // baris tidak pernah jadi negatif; sisanya diteruskan ke grup
                    // tanggal berikutnya (boleh subkategori lain). Kalau kategori ini
                    // tidak punya realisasi sama sekali, loop ini tidak jalan dan
                    // potongan tidak dikreditkan — konsisten dengan clamp di
                    // cumulativeBalanceBefore().
                    if ($deductionRemaining !== 0 && $amount > 0) {
                        $applied = -min($amount, abs($deductionRemaining));
                        $amount += $applied;
                        $deductionRemaining -= $applied;
                    }

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
     * Seed ini MURNI kas fisik kas kebun (hasil hitung tunai saat cutover),
     * jadi HANYA disertakan kalau kantong mencakup kebun ('kebun'/'all').
     * Kantong 'pribadi' murni tidak pernah menyimpan kas fisik — dana
     * ditransfer langsung HO ke rekening pribadi/vendor dan dibelanjakan,
     * tidak pernah "dipegang" siapa pun — sehingga tidak punya saldo awal.
     *
     * Potongan (transfer negatif) mengurangi total pengeluaran, tapi HANYA
     * sebatas realisasi yang benar-benar sudah tercatat pada PDO yang sama —
     * lihat DeductionNetting. Dikelompokkan per PDO HEADER (bukan lintas semua
     * riwayat) karena tiap PDO punya potongannya sendiri; tanpa itu, realisasi
     * PDO bulan lalu bisa keliru dianggap "mengkompensasi" potongan bulan ini.
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

        $realizationByPdo = RealizationEntry::query()
            ->whereIn('funding_source', $this->expenseFundingSourcesFor($kantong))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transaction_date', '<', $before->toDateString())
            ->join('pdo_details', 'pdo_details.id', '=', 'realization_entries.pdo_detail_id')
            ->selectRaw('pdo_details.pdo_header_id as pdo_id, SUM(realization_entries.amount) as total')
            ->groupBy('pdo_details.pdo_header_id')
            ->pluck('total', 'pdo_id');

        $deductionByPdo = TransferEntry::query()
            ->whereIn('transfer_destination', $this->receiptDestinationsFor($kantong))
            ->whereHas('pdoDetail', fn ($q) => $this->excludingKasKebunFunded($q))
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('plantation_unit_id', $unitId))
            ->where('transfer_date', '<', $before->toDateString())
            ->join('pdo_details', 'pdo_details.id', '=', 'transfer_entries.pdo_detail_id')
            ->selectRaw('pdo_details.pdo_header_id as pdo_id, SUM(transfer_entries.amount) as total')
            ->groupBy('pdo_details.pdo_header_id')
            ->pluck('total', 'pdo_id'); // negatif

        $totalExpenses = 0;
        foreach ($realizationByPdo->keys()->merge($deductionByPdo->keys())->unique() as $pdoId) {
            $totalExpenses += DeductionNetting::effectiveRealization(
                (int) ($realizationByPdo[$pdoId] ?? 0),
                (int) ($deductionByPdo[$pdoId] ?? 0),
            );
        }

        return $seed + $totalReceipts - $totalExpenses;
    }
}
