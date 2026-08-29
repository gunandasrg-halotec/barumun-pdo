<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\DB;

class RecapQueryService
{
    /** Lazy resolve — lihat catatan pola yang sama di WhatsAppNotificationService. */
    private ?CashBookQueryService $cashBookQueryService = null;

    private function cashBook(): CashBookQueryService
    {
        return $this->cashBookQueryService ??= app(CashBookQueryService::class);
    }

    public function getRecapData(array $filters): array
    {
        $year       = $filters['period_year'];
        $month      = $filters['period_month'];
        $unitId     = $filters['unit_id'];
        $categoryId = $filters['category_id'] ?? null;
        $startDate  = $filters['start_date']  ?? null;
        $endDate    = $filters['end_date']    ?? null;
        $kantong    = $filters['kantong']     ?? 'all'; // 'all' | 'kebun' | 'pribadi'

        // Baris untuk tabel (bisa dibatasi ke 1 kategori lewat $categoryId).
        $rows = $this->fetchDetailRows($year, $month, $unitId, $categoryId, $startDate, $endDate);

        // Baris untuk KPI (SELALU seluruh PDO, tidak terpengaruh filter kategori tabel) —
        // kalau tidak ada filter kategori, ini persis sama dengan $rows, jadi tidak perlu
        // query kedua. KPI dihitung dengan SUM manual di sini (bukan query agregat
        // terpisah) supaya KPI dijamin selalu = penjumlahan item, termasuk override
        // realisasi item potongan (lihat DeductionNetting).
        $kpiRows = $categoryId === null
            ? $rows
            : $this->fetchDetailRows($year, $month, $unitId, null, $startDate, $endDate);

        $transferKebun    = 0;
        $transferPribadi  = 0;

        // Akumulasi realisasi & potongan DIPISAH PER KATEGORI, bukan PDO-wide.
        // Potongan Panjar = uang muka yang sudah dibayar periode lalu untuk pekerjaan
        // di kategori itu sendiri, jadi hanya boleh dinetkan terhadap realisasi
        // kategori yang sama. Kategori yang tidak punya potongan TIDAK boleh ikut
        // terpotong hanya karena kategori lain punya panjar — itu akan mengecilkan
        // realisasi item yang tidak ada uang mukanya sama sekali.
        //
        // Skop ini SENGAJA kategori, bukan sub-kategori. Kerani memperkirakan beban
        // pekerjaan di muka, dan perkiraan itu bisa meleset sehingga panjar > biaya
        // riil sub-kategori tsb (kasus Binanga Juli 2026: panjar TANAMAN MENGHASILKAN
        // 600.000 tapi upah babat gawangan cuma 455.000). Pekerja yang sama biasanya
        // juga mengerjakan sub-kategori lain di kategori yang sama, jadi kelebihan
        // panjar wajar diserap sub-kategori tetangga — bukan dibiarkan tertahan
        // sehingga saldo kantong tampil minus padahal tidak ada kas yang negatif.
        //
        // Skop ini sengaja sama persis dengan CashBookQueryService::buildExpenseRows()
        // supaya Rekap Buku Kas dan Buku Kas Harian selalu menghasilkan angka yang sama.
        $rawByCat       = []; // [catId => ['kebun' => int, 'pribadi' => int]]
        $deductionByCat = []; // [catId => ['kebun' => int, 'pribadi' => int]] — negatif

        foreach ($kpiRows as $row) {
            $isDeduction      = (bool) $row->is_deduction;
            $isKasKebunFunded = ($row->funding_option ?? null) === 'kas_kebun';
            $tKebun           = (int) $row->total_transfer_kebun;
            $tPribadi         = (int) $row->total_transfer_pribadi;
            $catId            = $row->category_id;

            $rawByCat[$catId]       ??= ['kebun' => 0, 'pribadi' => 0];
            $deductionByCat[$catId] ??= ['kebun' => 0, 'pribadi' => 0];

            // Item PDOT funding_option=kas_kebun: TransferEntry auto-generated di
            // sini (lihat PdoSupplementaryApprovalService::mergeIntoParent()) BUKAN
            // dana baru masuk — dana sudah ada di kas kebun sebelum PDO ini. Entri
            // itu murni artefak teknis supaya KERANI bisa realisasi
            // (RealizationEntryService masih menghitungnya, TIDAK di sini) — jadi
            // dikecualikan dari akumulasi KPI transfer/deduction supaya tidak
            // menggelembungkan "Saldo Kas Kebun". Baris tabel per-item TETAP
            // menampilkan nilai aslinya (lihat buildHierarchy(), tidak diubah).
            if (! $isKasKebunFunded) {
                $transferKebun   += $tKebun;
                $transferPribadi += $tPribadi;
            }

            if ($isDeduction) {
                // Item potongan tidak pernah punya realization_entries — nilainya
                // hidup sebagai transfer negatif.
                if (! $isKasKebunFunded) {
                    $deductionByCat[$catId]['kebun']   += $tKebun;
                    $deductionByCat[$catId]['pribadi'] += $tPribadi;
                }
                continue;
            }

            $rawByCat[$catId]['kebun']   += (int) $row->total_realization;
            $rawByCat[$catId]['pribadi'] += (int) $row->total_realization_pribadi;
        }

        // Netting potongan di-clamp per (kategori, kantong): kredit tidak boleh
        // melebihi realisasi yang benar-benar sudah tercatat DI KATEGORI ITU.
        // Kalau realisasi satu kategori belum lengkap, kreditnya tertahan (realisasi
        // efektif kategori 0, tidak pernah negatif) sampai realisasinya masuk —
        // bukan ditutup memakai surplus kategori lain.
        //
        // Catatan: karena clamp-nya di level kategori, realisasi efektif satu
        // SUB-kategori boleh negatif (sub yang potongannya melebihi biayanya sendiri
        // ditutup sub-kategori tetangga). Yang dijamin tidak pernah negatif adalah
        // total kategori.
        $realisasiKebun   = 0;
        $realisasiPribadi = 0;
        $creditByCat      = []; // [catId => ['kebun' => int, 'pribadi' => int]] — negatif

        foreach ($rawByCat as $catId => $raw) {
            $ded = $deductionByCat[$catId];

            $realisasiKebun   += DeductionNetting::effectiveRealization($raw['kebun'], $ded['kebun']);
            $realisasiPribadi += DeductionNetting::effectiveRealization($raw['pribadi'], $ded['pribadi']);

            // Kredit yang benar-benar terpakai — dibagikan ke baris potongan di
            // buildHierarchy() supaya penjumlahan baris tetap sama dengan KPI ini.
            $creditByCat[$catId] = [
                'kebun'   => DeductionNetting::usableCredit($raw['kebun'], $ded['kebun']),
                'pribadi' => DeductionNetting::usableCredit($raw['pribadi'], $ded['pribadi']),
            ];
        }

        // Saldo awal kas kebun di AWAL periode PDO ini — KPI tetap, tidak
        // terpengaruh $startDate/$endDate (yang hanya memfilter baris tabel).
        $saldoAwal = $unitId ? $this->cashBook()->openingBalanceForPeriod($unitId, (int) $year, (int) $month) : 0;

        return $this->buildHierarchy(
            $rows, $transferKebun, $transferPribadi, $realisasiKebun, $realisasiPribadi,
            $kantong, $saldoAwal, $creditByCat,
        );
    }

    /**
     * Ambil baris per pdo_detail dengan agregat transfer/realisasi (kebun & pribadi
     * scope terpisah) + flag is_deduction. $categoryId null = semua kategori (dipakai
     * untuk hitung KPI PDO-wide, terlepas dari filter kategori tabel).
     */
    private function fetchDetailRows(int $year, int $month, string $unitId, ?string $categoryId, ?string $startDate, ?string $endDate): array
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        $dateFilterSql = $isSqlite
            ? 'AND (:start_date IS NULL OR re.transaction_date >= :start_date2)
                AND (:end_date IS NULL OR re.transaction_date <= :end_date2)'
            : 'AND (CAST(:start_date AS date) IS NULL OR re.transaction_date >= CAST(:start_date2 AS date))
                AND (CAST(:end_date AS date) IS NULL OR re.transaction_date <= CAST(:end_date2 AS date))';

        // Subquery re_pribadi_agg pakai placeholder terpisah (start_date3/end_date3) —
        // sama-sama muncul dalam 1 query string dengan re_agg, dan PDO tidak reliable
        // untuk named parameter yang diulang lintas subquery berbeda dalam 1 statement.
        $dateFilterSqlPribadi = $isSqlite
            ? 'AND (:start_date3 IS NULL OR re.transaction_date >= :start_date4)
                AND (:end_date3 IS NULL OR re.transaction_date <= :end_date4)'
            : 'AND (CAST(:start_date3 AS date) IS NULL OR re.transaction_date >= CAST(:start_date4 AS date))
                AND (CAST(:end_date3 AS date) IS NULL OR re.transaction_date <= CAST(:end_date4 AS date))';

        $categoryFilterSql = $isSqlite
            ? 'AND (:category_id IS NULL OR ec.id = :category_id2)'
            : 'AND (CAST(:category_id AS uuid) IS NULL OR ec.id = CAST(:category_id2 AS uuid))';

        // total_transfer/total_realization are pre-aggregated per pdo_detail_id in subqueries
        // (not joined directly) to avoid a join fan-out: joining transfer_entries AND
        // realization_entries onto pdo_details in one query multiplies rows whenever a detail
        // has more than one row on either side, and SUM(DISTINCT amount) — the previous
        // workaround — silently drops legitimate duplicate-amount entries instead of
        // duplicate rows, undercounting totals like "3x Rp 120.000 => Rp 120.000".
        return DB::select("
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
                pd.notes,
                pd.funding_option,
                CASE WHEN ei.is_deduction THEN -pd.amount ELSE pd.amount END AS pengajuan,
                ei.is_deduction,
                ei.is_fund_return,
                COALESCE(te_agg.total_transfer, 0)      AS total_transfer,
                COALESCE(te_kebun_agg.total_transfer_kebun, 0)     AS total_transfer_kebun,
                COALESCE(te_pribadi_agg.total_transfer_pribadi, 0) AS total_transfer_pribadi,
                COALESCE(re_agg.total_realization, 0)   AS total_realization,
                COALESCE(re_pribadi_agg.total_realization_pribadi, 0) AS total_realization_pribadi
            FROM pdo_details pd
            JOIN pdo_headers ph             ON ph.id = pd.pdo_header_id
            JOIN expense_items ei           ON ei.id = pd.expense_item_id
            JOIN expense_subcategories es   ON es.id = ei.subcategory_id
            JOIN expense_categories ec      ON ec.id = es.category_id
            LEFT JOIN (
                SELECT pdo_detail_id, SUM(amount) AS total_transfer
                FROM transfer_entries
                WHERE status = 'committed'
                GROUP BY pdo_detail_id
            ) te_agg ON te_agg.pdo_detail_id = pd.id
            LEFT JOIN (
                SELECT pdo_detail_id, SUM(amount) AS total_transfer_kebun
                FROM transfer_entries
                WHERE status = 'committed' AND transfer_destination = 'rek_kebun'
                GROUP BY pdo_detail_id
            ) te_kebun_agg ON te_kebun_agg.pdo_detail_id = pd.id
            LEFT JOIN (
                SELECT pdo_detail_id, SUM(amount) AS total_transfer_pribadi
                FROM transfer_entries
                WHERE status = 'committed' AND transfer_destination IN ('pribadi', 'vendor')
                GROUP BY pdo_detail_id
            ) te_pribadi_agg ON te_pribadi_agg.pdo_detail_id = pd.id
            LEFT JOIN (
                SELECT re.pdo_detail_id, SUM(re.amount) AS total_realization
                FROM realization_entries re
                WHERE re.funding_source IN ('kas_kebun', 'rekening_kebun')
                    {$dateFilterSql}
                GROUP BY re.pdo_detail_id
            ) re_agg ON re_agg.pdo_detail_id = pd.id
            LEFT JOIN (
                SELECT re.pdo_detail_id, SUM(re.amount) AS total_realization_pribadi
                FROM realization_entries re
                WHERE re.funding_source = 'rekening_utama'
                    {$dateFilterSqlPribadi}
                GROUP BY re.pdo_detail_id
            ) re_pribadi_agg ON re_pribadi_agg.pdo_detail_id = pd.id
            WHERE ph.period_year  = :year
              AND ph.period_month = :month
              AND ph.plantation_unit_id = :unit_id
              AND ec.include_in_recap = TRUE
              {$categoryFilterSql}
            -- Mengikuti urutan Master Data: kategori & sub-kategori pakai display_order
            -- lalu code, item pakai code. Sebelumnya kunci ketiga adalah ei.id (UUID),
            -- sehingga urutan item di dalam sub-kategori praktis acak.
            ORDER BY ec.display_order, ec.code, es.display_order, es.code, ei.code
        ", [
            'start_date'   => $startDate,
            'start_date2'  => $startDate,
            'end_date'     => $endDate,
            'end_date2'    => $endDate,
            'start_date3'  => $startDate,
            'start_date4'  => $startDate,
            'end_date3'    => $endDate,
            'end_date4'    => $endDate,
            'year'        => $year,
            'month'       => $month,
            'unit_id'     => $unitId,
            'category_id'  => $categoryId,
            'category_id2' => $categoryId,
        ]);
    }

    private function buildHierarchy(array $rows, int $transferKebun, int $transferPribadi, int $realisasiKebun, int $realisasiPribadi, string $kantong = 'all', int $saldoAwal = 0, array $creditByCat = []): array
    {
        $categories  = [];
        $catIndex    = [];
        $subIndex    = [];
        $itemCounter = 0;

        $grandTotalAmount      = 0;
        $grandTotalTransfer    = 0;
        $grandTotalRealization = 0;

        // Sisa kredit potongan (positif) PER KATEGORI, dibatasi di getRecapData()
        // sebesar realisasi yang benar-benar ada di kategori itu, lalu dikonsumsi
        // berurutan di sini supaya penjumlahan baris selalu sama dengan KPI.
        $pool = [];
        foreach ($creditByCat as $catId => $credit) {
            $pool[$catId] = ['kebun' => abs($credit['kebun']), 'pribadi' => abs($credit['pribadi'])];
        }

        foreach ($rows as $row) {
            $pengajuan           = (int) $row->pengajuan;
            $transferAll         = (int) $row->total_transfer;
            $transferKebunItem   = (int) $row->total_transfer_kebun;
            $transferPribadiItem = (int) $row->total_transfer_pribadi;
            $isDeduction         = (bool) $row->is_deduction;
            $rowCatId            = $row->category_id;
            $isKasKebunFunded    = ($row->funding_option ?? null) === 'kas_kebun';

            // Item PDO Tambahan "Gunakan Kas Kebun": TransferEntry auto-generated-nya
            // (lihat PdoSupplementaryApprovalService::mergeIntoParent()) hanya artefak
            // teknis supaya KERANI bisa merealisasikannya — BUKAN dana baru masuk.
            // Sudah dikecualikan dari KPI transfer_kebun di getRecapData(); baris tabel
            // WAJIB ikut dinolkan, kalau tidak jumlah baris tidak akan pernah sama
            // dengan KPI-nya sendiri (kasus KP Agustus: tabel 215.400.891 vs KPI
            // 215.275.891, selisih persis 125.000 dari satu item PDOT kas kebun).
            //
            // Saldo dibiarkan memakai rumus normal (Transfer − Realisasi) sehingga bisa
            // MINUS begitu item ini direalisasi — itu memang disengaja: angka minus
            // adalah sinyal bahwa realisasi item ini didanai dari luar transfer item itu
            // sendiri, sama seperti item lain yang direalisasi memakai dana transfer
            // item lain dalam kantong yang sama.
            if ($isKasKebunFunded) {
                $transferAll         = 0;
                $transferKebunItem   = 0;
                $transferPribadiItem = 0;
            }

            if ($isDeduction) {
                $pool[$rowCatId] ??= ['kebun' => 0, 'pribadi' => 0];

                $takenKebun   = min(abs($transferKebunItem), $pool[$rowCatId]['kebun']);
                $takenPribadi = min(abs($transferPribadiItem), $pool[$rowCatId]['pribadi']);
                $pool[$rowCatId]['kebun']   -= $takenKebun;
                $pool[$rowCatId]['pribadi'] -= $takenPribadi;

                $realKebunItem   = -$takenKebun;
                $realPribadiItem = -$takenPribadi;
            } else {
                $realKebunItem   = (int) $row->total_realization;
                $realPribadiItem = (int) $row->total_realization_pribadi;
            }

            // Kolom yang ditampilkan tergantung filter kantong: 'kebun' hanya
            // transfer/realisasi ke rek_kebun, 'pribadi' hanya pribadi/vendor,
            // 'all' menjumlahkan transfer & realisasi dari KEDUA kantong supaya
            // saldo tetap konsisten dengan tampilan per-kantong (item yang saldo
            // 0 di kantong pribadi harus tetap 0 saat filter kantong = semua).
            // Item kas kebun sengaja dikecualikan dari filter "sembunyikan baris kosong":
            // transfer-nya memang 0 by design, jadi tanpa pengecualian ini item tsb
            // hilang dari tabel selama belum direalisasi — padahal ia bagian dari PDO
            // dan punya nilai pengajuan.
            if ($kantong === 'kebun') {
                $transfer = $transferKebunItem;
                $real     = $realKebunItem;
                if ($transfer === 0 && $real === 0 && ! $isKasKebunFunded) {
                    continue;
                }
            } elseif ($kantong === 'pribadi') {
                $transfer = $transferPribadiItem;
                $real     = $realPribadiItem;
                // Tanpa pengecualian di sini: item kas kebun milik kantong kebun,
                // jadi tetap disembunyikan saat filter kantong = Pribadi/Vendor.
                if ($transfer === 0 && $real === 0) {
                    continue;
                }
            } else {
                $transfer = $transferAll;
                $real     = $realKebunItem + $realPribadiItem;
            }
            // Saldo = Transfer - Realisasi seperti biasa. Untuk item potongan, $real
            // adalah kredit yang diambil dari pool di atas — sama dengan $transfer
            // selama kreditnya masih tersedia, sehingga Saldo otomatis 0.
            $saldo = $transfer - $real;

            // Status overbudget SELALU dihitung per-kantong yang benar (transfer_kebun
            // vs realisasi_kebun, dan/atau transfer_pribadi vs realisasi_pribadi),
            // TIDAK berdasarkan kolom Transfer yang ditampilkan (yang di mode 'all'
            // mencampur semua tujuan sebagai informasi saja). Ini supaya item yang
            // overspend di kantong kebun tetap terdeteksi walau total_transfer
            // gabungan (semua tujuan) masih terlihat besar.
            // Item pengembalian sisa dana bulan lalu sengaja tidak punya transfer
            // (dananya dari saldo bulan lalu, bukan transfer bulan ini) — jangan
            // pernah ditandai overbudget karena itu bukan pembengkakan biaya.
            $isFundReturnItem = (bool) ($row->is_fund_return ?? false);
            $isOverbudgetKebun   = ! $isFundReturnItem && $transferKebunItem   < $realKebunItem;
            $isOverbudgetPribadi = ! $isFundReturnItem && $transferPribadiItem < $realPribadiItem;
            $isOverbudget = $kantong === 'kebun'
                ? $isOverbudgetKebun
                : ($kantong === 'pribadi'
                    ? $isOverbudgetPribadi
                    : ($isOverbudgetKebun || $isOverbudgetPribadi));

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

            $subPos = $subIndex[$catId][$subId];

            // ── Item ──────────────────────────────────────────────────────────
            $itemCounter++;
            $categories[$catPos]['subcategories'][$subPos]['items'][] = [
                'no'               => $itemCounter,
                'pdo_detail_id'    => $row->pdo_detail_id,
                'item_code'        => $row->item_code,
                'item_name'        => $row->item_name,
                'account_number'   => $row->account_number,
                'description'      => $row->description,
                'notes'            => $row->notes,
                'amount'           => $pengajuan,
                'total_transfer'   => $transfer,
                'total_realization'=> $real,
                'saldo'            => $saldo,
                'is_deduction'     => $isDeduction,
                'is_overbudget'    => $isOverbudget,
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

        // Saldo awal hanya berlaku kalau kantong yang ditampilkan mencakup Kas Kebun.
        // Saat filter kantong = Pribadi/Vendor, tabelnya tidak memuat transaksi kas
        // kebun sama sekali, jadi menambahkan saldo awal di sana akan salah.
        $saldoAwalTabel = in_array($kantong, ['all', 'kebun'], true) ? $saldoAwal : 0;

        return [
            'grand_total_amount'            => $grandTotalAmount,
            'grand_total_transfer'          => $grandTotalTransfer,
            'grand_total_realization'       => $grandTotalRealization,
            // Baris Grand Total memakai rumus kantong yang sama dengan KPI "Saldo"
            // Kas Kebun: saldo awal + transfer − realisasi. Konsekuensinya baris ini
            // TIDAK lagi sama dengan penjumlahan kolom Saldo per item (yang tetap
            // transfer − realisasi, karena saldo awal milik kantong dan tidak bisa
            // dibagi ke baris item mana pun) — selisihnya persis sebesar saldo awal.
            // Ini disengaja: Grand Total dibaca sebagai posisi kas, bukan sebagai
            // jumlah kolom. Angka "murni PDO" tetap tersedia di KPI "Saldo PDO".
            'grand_total_saldo'             => $saldoAwalTabel + $grandTotalTransfer - $grandTotalRealization,
            'transfer_kebun'                => $transferKebun,
            'transfer_pribadi'              => $transferPribadi,
            'realisasi_kebun'               => $realisasiKebun,
            'realisasi_pribadi'             => $realisasiPribadi,
            // Saldo PDO = transfer − realisasi, TANPA saldo awal: memperlihatkan
            // posisi dana yang berasal dari PDO periode ini saja.
            'saldo_pdo_kebun'               => $transferKebun - $realisasiKebun,
            // Saldo kantong = saldo awal + transfer − realisasi. Saldo awal hanya
            // dimiliki kantong Kas Kebun (kas fisik yang tersisa dari bulan lalu);
            // kantong Pribadi/Vendor tidak pernah menyimpan kas, jadi tetap
            // per-periode. Rumus yang sama dipakai Buku Kas Harian, "Sisa Dana" di
            // form Realisasi/Voucher, Daftar PDO, dan Dashboard.
            'saldo_kebun'                   => $saldoAwal + $transferKebun - $realisasiKebun,
            'saldo_pribadi'                 => $transferPribadi - $realisasiPribadi,
            'saldo_awal'                    => $saldoAwal,
            // Alias historis dari saldo_kebun — dipertahankan supaya klien lama tidak pecah.
            'saldo_kas_kebun_saat_ini'      => $saldoAwal + $transferKebun - $realisasiKebun,
            'categories'                    => $categories,
        ];
    }
}
