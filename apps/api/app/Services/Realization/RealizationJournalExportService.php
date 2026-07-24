<?php

namespace App\Services\Realization;

use App\Models\RealizationEntry;
use App\Models\User;
use App\Models\VehicleSparepartWatermark;
use App\Models\VehicleTripLog;

class RealizationJournalExportService
{
    /**
     * Label Tag per kode unit kebun, dipakai untuk mengelompokkan transaksi
     * jurnal umum berdasarkan kebun di Jurnal by Mekari.
     */
    private const UNIT_TAGS = [
        'BN' => 'Kebun Binanga',
        'JM' => 'Kebun JM',
        'KP' => 'Kebun KP',
        'SS' => 'Kebun Sosa',
    ];

    /**
     * Kode akun untuk baris kredit saat funding_source = rekening_utama.
     */
    private const REKENING_UTAMA_ACCOUNT_CODE = '1-10019';

    /**
     * 5 item biaya yang diperlakukan sebagai Persediaan (bukan langsung biaya).
     * Digunakan juga oleh Realization FormRequests untuk validasi vehicle_id.
     */
    public const INVENTORY_ITEM_CODES = [
        'BBM-TRK-001',
        'BBM-TRK-002',
        'PHD-SPK-001',
        'PBB-TRK-001',
        'PBB-TRK-002',
    ];

    /**
     * Mapping item code → jenis persediaan (bbm atau sparepart).
     */
    private const INVENTORY_TYPE_MAP = [
        'BBM-TRK-001' => 'bbm',
        'BBM-TRK-002' => 'bbm',
        'PHD-SPK-001' => 'sparepart',
        'PBB-TRK-001' => 'sparepart',
        'PBB-TRK-002' => 'sparepart',
    ];

    /**
     * Akun Persediaan BBM dan Sparepart — dikosongkan dulu, ditentukan user kemudian.
     */
    private const PERSEDIAAN_ACCOUNT_CODES = [
        'bbm'       => null,
        'sparepart' => null,
    ];

    /**
     * Akun Biaya Produksi (Angkut TBS) dan Biaya Perawatan — dikosongkan dulu.
     */
    private const PRODUKSI_ACCOUNT_CODE  = null;
    private const PERAWATAN_ACCOUNT_CODE = null;

    /**
     * Akun antar-unit untuk kasus kendaraan dipakai unit lain dari unit
     * pembeli persediaan — dikosongkan dulu.
     */
    private const DUE_FROM_UNIT_ACCOUNT_CODE = null; // Piutang Antar Unit (di buku unit pembeli)
    private const DUE_TO_UNIT_ACCOUNT_CODE   = null; // Utang Antar Unit (di buku unit pemakai)

    /**
     * Bangun baris-baris jurnal tahap 1 (debit Persediaan + kredit Kas) untuk
     * setiap realisasi terpilih.
     * Untuk 5 item persediaan: baris debit menggunakan akun Persediaan (kosong dulu).
     * Untuk item lain: baris debit menggunakan account_number dari pdo_detail.
     */
    public function buildRows(array $entryIds, User $actor): array
    {
        $entries = RealizationEntry::whereIn('id', $entryIds)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->with(['pdoDetail.expenseItem', 'pdoDetail.pdoHeader.plantationUnit'])
            ->get()
            ->keyBy('id');

        $ordered = collect($entryIds)
            ->map(fn ($id) => $entries->get($id))
            ->filter();

        $rows = [];

        foreach ($ordered as $entry) {
            $pdoDetail      = $entry->pdoDetail;
            $pdoHeader      = $pdoDetail?->pdoHeader;
            $expenseItem    = $pdoDetail?->expenseItem;
            $plantationUnit = $pdoHeader?->plantationUnit;

            $pdoNumber = $pdoHeader?->pdo_number ?? '—';
            $itemCode  = $expenseItem?->code ?? $pdoDetail?->id;
            $itemName  = $expenseItem?->name ?? $pdoDetail?->description ?? '—';
            $unitName  = $plantationUnit?->name ?? '—';
            $tag       = self::UNIT_TAGS[$plantationUnit?->code] ?? '';

            $transactionNumber = $entry->proof_number;
            $transactionDate   = $entry->transaction_date->format('d/m/Y');

            $memo = "{$pdoNumber} - {$itemCode}  {$itemName}";
            if ($entry->explanation) {
                $memo .= " - {$entry->explanation}";
            }

            $isRekeningUtama   = $entry->funding_source === RealizationEntry::FUNDING_REKENING_UTAMA;
            $creditAccountCode = $isRekeningUtama
                ? self::REKENING_UTAMA_ACCOUNT_CODE
                : ($plantationUnit?->account_code_kas_kebun ?: null);
            $creditDescription = $isRekeningUtama
                ? 'Bank BRI 01220101002851560 (an Sofi Hana Nasution)'
                : "Kas Kebun {$unitName}";

            // Untuk 5 item persediaan: akun debit = Persediaan (kosong dulu)
            $isInventoryItem = in_array($itemCode, self::INVENTORY_ITEM_CODES, true);
            $inventoryType   = self::INVENTORY_TYPE_MAP[$itemCode] ?? null;
            $debitAccountCode = $isInventoryItem
                ? (self::PERSEDIAAN_ACCOUNT_CODES[$inventoryType] ?? null)
                : ($pdoDetail?->account_number ?: null);

            $rows[] = [
                'realization_entry_id' => $entry->id,
                'transaction_number'   => $transactionNumber,
                'transaction_date'     => $transactionDate,
                'debit_row' => [
                    'account_code' => $debitAccountCode,
                    'description'  => "{$itemCode} - {$itemName}",
                    'debit'        => $entry->amount,
                    'credit'       => null,
                ],
                'credit_row' => [
                    'account_code' => $creditAccountCode,
                    'description'  => $creditDescription,
                    'debit'        => null,
                    'credit'       => $entry->amount,
                ],
                'memo'                => $memo,
                'tag'                 => $tag,
                'is_inventory_item'   => $isInventoryItem,
                'inventory_type'      => $inventoryType,
                'already_exported'    => $entry->exported_to_journal_at !== null,
                'already_exported_at' => $entry->exported_to_journal_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Bangun baris-baris jurnal tahap 2 (biaya atas pemakaian persediaan).
     * Hanya untuk 5 item persediaan. BBM dan sparepart memakai model window
     * yang berbeda — lihat masing-masing method di bawah.
     *
     * @param  bool  $commit  true hanya saat CSV benar-benar diunduh (bukan
     *                        preview) — mengontrol apakah watermark sparepart dimajukan.
     * @return array{rows: array, skipped_entry_ids: array}
     */
    public function buildStage2Rows(array $entryIds, User $actor, bool $commit = false): array
    {
        $entries = RealizationEntry::whereIn('id', $entryIds)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->with(['pdoDetail.expenseItem', 'pdoDetail.pdoHeader.plantationUnit', 'vehicle'])
            ->get()
            ->keyBy('id');

        $ordered = collect($entryIds)
            ->map(fn ($id) => $entries->get($id))
            ->filter()
            ->filter(fn ($entry) => in_array(
                $entry->pdoDetail?->expenseItem?->code,
                self::INVENTORY_ITEM_CODES,
                true
            ));

        $bbmEntries       = $ordered->filter(fn ($e) => (self::INVENTORY_TYPE_MAP[$e->pdoDetail->expenseItem->code] ?? null) === 'bbm');
        $sparepartEntries = $ordered->filter(fn ($e) => (self::INVENTORY_TYPE_MAP[$e->pdoDetail->expenseItem->code] ?? null) === 'sparepart');

        $bbmResult       = $this->buildBbmStage2Rows($bbmEntries);
        $sparepartResult = $this->buildSparepartStage2Rows($sparepartEntries, $actor, $commit);

        return [
            'rows'              => [...$bbmResult['rows'], ...$sparepartResult['rows']],
            'skipped_entry_ids' => [...$bbmResult['skipped_entry_ids'], ...$sparepartResult['skipped_entry_ids']],
        ];
    }

    /**
     * Tahap 2 untuk BBM: 1 baris per realisasi. Window pemakaian 1 "tangki"
     * = dari tanggal realisasi ini sampai realisasi berikutnya untuk
     * kendaraan yang sama (FIFO), lintas PDO/unit — karena kendaraan bisa
     * berpindah unit sebelum tangki berikutnya dibeli. Window juga dibatasi
     * oleh tanggal tutup PDO unit pembeli. Trip yang dicatat oleh unit lain
     * dari unit pembeli menghasilkan posting antar-unit (piutang/utang antar
     * unit) agar biaya tetap dibebankan ke unit yang benar-benar memakai
     * kendaraan tsb, bukan ke unit yang membayar kas.
     *
     * Entry tanpa trip log sama sekali dalam window di-skip — tahap 1 tetap
     * normal, tahap 2 bisa menyusul di export berikutnya begitu log trip tersedia.
     *
     * @return array{rows: array, skipped_entry_ids: array}
     */
    private function buildBbmStage2Rows(\Illuminate\Support\Collection $entries): array
    {
        $rows    = [];
        $skipped = [];

        foreach ($entries as $entry) {
            $pdoDetail      = $entry->pdoDetail;
            $pdoHeader      = $pdoDetail?->pdoHeader;
            $expenseItem    = $pdoDetail?->expenseItem;
            $plantationUnit = $pdoHeader?->plantationUnit;
            $itemCode       = $expenseItem?->code;

            $vehicleId = $entry->vehicle_id;
            if (! $vehicleId) {
                $skipped[] = $entry->id;
                continue;
            }

            $inventoryType = self::INVENTORY_TYPE_MAP[$itemCode] ?? null;

            $nextPurchaseDate = RealizationEntry::where('vehicle_id', $vehicleId)
                ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->whereIn('code', $this->itemCodesForType($inventoryType)))
                ->where('transaction_date', '>', $entry->transaction_date)
                ->orderBy('transaction_date')
                ->orderBy('created_at')
                ->value('transaction_date');

            // Window juga dibatasi oleh tanggal tutup PDO unit pembeli — kalau PDO
            // pembeli sudah ditutup sebelum ada pembelian berikutnya, biaya BBM
            // dibebankan berdasarkan trip s/d tanggal tutup tsb, tidak menunggu
            // pembelian baru. closed_at sendiri termasuk dalam window, jadi cap
            // eksklusif-nya adalah hari setelah closed_at.
            $pdoClosedCap = $pdoHeader?->status === \App\Models\PdoHeader::STATUS_CLOSED && $pdoHeader->closed_at
                ? $pdoHeader->closed_at->copy()->addDay()
                : null;

            $windowEnd = collect([$nextPurchaseDate, $pdoClosedCap])->filter()->sort()->first();

            $split = VehicleTripLog::usageSplitForWindow($vehicleId, $entry->transaction_date, $windowEnd);

            if ($split === null) {
                $skipped[] = $entry->id;
                continue;
            }

            $persediaanAccount = self::PERSEDIAAN_ACCOUNT_CODES[$inventoryType] ?? null;
            $buyerUnit         = $plantationUnit;
            $pdoNumber         = $pdoHeader?->pdo_number ?? '—';
            $amount            = $entry->amount;

            $memo = "{$pdoNumber} - {$itemCode} - Pemakaian Persediaan";

            $byUnit = $this->allocateGroupsByUnit($split['groups'], $amount);

            $rows[] = [
                'realization_entry_id' => $entry->id,
                'transaction_number'   => $entry->proof_number,
                'transaction_date'     => $entry->transaction_date->format('d/m/Y'),
                'memo'                 => $memo,
                'postings'             => $this->buildPostingsFromUsage($byUnit, $buyerUnit, $persediaanAccount),
            ];
        }

        return ['rows' => $rows, 'skipped_entry_ids' => $skipped];
    }

    /**
     * Tahap 2 untuk sparepart: dipool per kendaraan + periode PDO (bulan
     * kalender berdasarkan periode PDO pembeli), bukan per realisasi.
     * Setiap kali export tahap 2 sparepart dijalankan (untuk kendaraan+periode
     * yg sama), hanya slice waktu SEJAK export terakhir yang dihitung
     * (watermark) — dibatasi juga sampai akhir bulan periode tsb. Watermark
     * hanya dimajukan saat $commit true (CSV benar-benar diunduh).
     *
     * @return array{rows: array, skipped_entry_ids: array}
     */
    private function buildSparepartStage2Rows(\Illuminate\Support\Collection $entries, User $actor, bool $commit): array
    {
        $rows    = [];
        $skipped = [];

        // Kelompokkan entry terpilih per (vehicle_id, period_year, period_month)
        // berdasarkan periode PDO pembeli.
        $groupedEntries = $entries
            ->filter(fn ($e) => $e->vehicle_id && $e->pdoDetail?->pdoHeader)
            ->groupBy(fn ($e) => $e->vehicle_id . '|' . $e->pdoDetail->pdoHeader->period_year . '|' . $e->pdoDetail->pdoHeader->period_month);

        // Entry sparepart tanpa vehicle_id / pdoHeader (seharusnya tidak terjadi
        // krn validasi wajib) tetap di-skip agar tidak hilang diam-diam.
        $skipped = $entries
            ->reject(fn ($e) => $e->vehicle_id && $e->pdoDetail?->pdoHeader)
            ->pluck('id')
            ->all();

        foreach ($groupedEntries as $key => $groupEntries) {
            [$vehicleId, $periodYear, $periodMonth] = explode('|', $key);
            $periodYear  = (int) $periodYear;
            $periodMonth = (int) $periodMonth;

            $periodStart = \Illuminate\Support\Carbon::create($periodYear, $periodMonth, 1)->startOfDay();
            $periodEndExclusive = $periodStart->copy()->addMonth();

            $watermark  = VehicleSparepartWatermark::lastCoveredDate($vehicleId, $periodYear, $periodMonth);
            $sliceStart = $watermark ? $watermark->copy()->addDay() : $periodStart;
            $today      = now()->startOfDay();
            $sliceEndExclusive = $today->lessThan($periodEndExclusive) ? $today : $periodEndExclusive;

            $groupEntryIds = $groupEntries->pluck('id')->all();

            if ($sliceStart->greaterThanOrEqualTo($sliceEndExclusive)) {
                // Slice ini sudah pernah dibebankan sepenuhnya di export sebelumnya.
                array_push($skipped, ...$groupEntryIds);
                continue;
            }

            // Pool total pembelian sparepart kendaraan ini dalam slice, dari
            // SEMUA unit (bukan hanya yang dipilih), karena beberapa unit bisa
            // sama-sama membeli sparepart utk truk yang sama dlm bulan yg sama.
            $poolAmount = (int) RealizationEntry::where('vehicle_id', $vehicleId)
                ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->whereIn('code', $this->itemCodesForType('sparepart')))
                ->where('transaction_date', '>=', $sliceStart)
                ->where('transaction_date', '<', $sliceEndExclusive)
                ->sum('amount');

            if ($poolAmount <= 0) {
                array_push($skipped, ...$groupEntryIds);
                continue;
            }

            $split = VehicleTripLog::usageSplitForWindow($vehicleId, $sliceStart, $sliceEndExclusive);

            if ($split === null) {
                // Belum ada trip log sama sekali dalam slice — tunggu sampai ada,
                // jangan majukan watermark supaya slice ini tetap "terbuka".
                array_push($skipped, ...$groupEntryIds);
                continue;
            }

            $vehicle   = $groupEntries->first()->vehicle;
            $plateInfo = $vehicle?->nomor_polisi ?? '—';

            $byUnit = $this->allocateGroupsByUnit($split['groups'], $poolAmount);

            $persediaanAccount = self::PERSEDIAAN_ACCOUNT_CODES['sparepart'] ?? null;

            // Tidak ada 1 "unit pembeli" tunggal utk pool (bisa lintas unit),
            // jadi tiap unit yg terlibat dianggap unit pembeli utk porsinya
            // sendiri (tidak ada posting antar-unit di sisi sparepart — biaya
            // Persediaan Sparepart di buku unit yg sama yg beli, dikurangi
            // proporsional; lihat catatan di allocateGroupsByUnit/buildPostingsFromUsage
            // — di sini kita perlakukan tiap unit sbg "buyer" utk porsinya sendiri
            // karena sparepart dipool lintas unit, bukan 1 realisasi tunggal).
            $postings = [];
            foreach ($byUnit as $usage) {
                $unitTotal = $usage['produksi'] + $usage['perawatan'];
                if ($unitTotal <= 0) {
                    continue;
                }

                $costDebitRows = [];
                if ($usage['produksi'] > 0) {
                    $costDebitRows[] = [
                        'account_code' => self::PRODUKSI_ACCOUNT_CODE,
                        'description'  => 'Biaya Produksi - Angkut TBS',
                        'debit'        => $usage['produksi'],
                        'credit'       => null,
                    ];
                }
                if ($usage['perawatan'] > 0) {
                    $costDebitRows[] = [
                        'account_code' => self::PERAWATAN_ACCOUNT_CODE,
                        'description'  => 'Biaya Perawatan',
                        'debit'        => $usage['perawatan'],
                        'credit'       => null,
                    ];
                }

                $postings[] = [
                    'tag'        => $usage['tag'],
                    'debit_rows' => $costDebitRows,
                    'credit_row' => [
                        'account_code' => $persediaanAccount,
                        'description'  => "Pemakaian Persediaan Sparepart ({$usage['name']})",
                        'debit'        => null,
                        'credit'       => $unitTotal,
                    ],
                ];
            }

            $periodLabel = $periodStart->translatedFormat('F Y');
            $rangeLabel  = $sliceStart->format('d/m/Y') . ' - ' . $sliceEndExclusive->copy()->subDay()->format('d/m/Y');

            $rows[] = [
                'realization_entry_id' => "vehicle:{$vehicleId}:{$periodYear}-{$periodMonth}",
                'transaction_number'   => $plateInfo,
                'transaction_date'     => $sliceEndExclusive->copy()->subDay()->format('d/m/Y'),
                'memo'                 => "{$plateInfo} - Pemakaian Persediaan Sparepart {$periodLabel} ({$rangeLabel})",
                'postings'             => $postings,
            ];

            if ($commit) {
                VehicleSparepartWatermark::advance(
                    $vehicleId,
                    $periodYear,
                    $periodMonth,
                    $sliceEndExclusive->copy()->subDay(),
                    $actor
                );
            }
        }

        return ['rows' => $rows, 'skipped_entry_ids' => $skipped];
    }

    /**
     * Bagi $amount ke tiap kelompok (pdo_header_id, trip_type) menurut rasio,
     * kelompok terakhir menyerap sisa pembulatan, lalu kelompokkan per unit
     * kebun (produksi/perawatan).
     *
     * @return array<string, array{tag: string, name: string, produksi: int, perawatan: int}>
     */
    private function allocateGroupsByUnit(array $groups, int $amount): array
    {
        $lastIndex = count($groups) - 1;
        $allocated = 0;
        $portions  = [];
        foreach ($groups as $i => $group) {
            $portion = ($i === $lastIndex)
                ? $amount - $allocated
                : (int) round($amount * $group['ratio']);
            $allocated += $portion;
            $portions[] = $group + ['amount' => $portion];
        }

        $pdoHeaderIds = collect($portions)->pluck('pdo_header_id')->unique()->values();
        $unitsByPdo = \App\Models\PdoHeader::whereIn('id', $pdoHeaderIds)
            ->with('plantationUnit')
            ->get()
            ->keyBy('id');

        $byUnit = [];
        foreach ($portions as $p) {
            $otherPdoHeader = $unitsByPdo->get($p['pdo_header_id']);
            $otherUnit      = $otherPdoHeader?->plantationUnit;
            $unitKey        = $otherUnit?->id ?? 'unknown';
            $byUnit[$unitKey] ??= [
                'tag'       => self::UNIT_TAGS[$otherUnit?->code] ?? '',
                'name'      => $otherUnit?->name ?? '—',
                'produksi'  => 0,
                'perawatan' => 0,
            ];
            if ($p['trip_type'] === VehicleTripLog::TRIP_TYPE_ANGKUT_TBS) {
                $byUnit[$unitKey]['produksi'] += $p['amount'];
            } else {
                $byUnit[$unitKey]['perawatan'] += $p['amount'];
            }
        }

        return $byUnit;
    }

    /**
     * Bangun postings dari peta usage-per-unit untuk 1 realisasi dengan 1
     * unit pembeli tunggal (dipakai oleh BBM). Unit yang sama dengan pembeli
     * dibebankan langsung; unit lain menghasilkan posting antar-unit
     * (piutang/utang) di kedua sisi.
     *
     * @param  array<string, array{tag: string, name: string, produksi: int, perawatan: int}>  $byUnit
     */
    private function buildPostingsFromUsage(array $byUnit, ?\App\Models\PlantationUnit $buyerUnit, ?string $persediaanAccount): array
    {
        $buyerUnitId = $buyerUnit?->id;
        $buyerTag    = self::UNIT_TAGS[$buyerUnit?->code] ?? '';
        $postings    = [];

        foreach ($byUnit as $unitKey => $usage) {
            $unitTotal = $usage['produksi'] + $usage['perawatan'];
            if ($unitTotal <= 0) {
                continue;
            }

            $costDebitRows = [];
            if ($usage['produksi'] > 0) {
                $costDebitRows[] = [
                    'account_code' => self::PRODUKSI_ACCOUNT_CODE,
                    'description'  => 'Biaya Produksi - Angkut TBS',
                    'debit'        => $usage['produksi'],
                    'credit'       => null,
                ];
            }
            if ($usage['perawatan'] > 0) {
                $costDebitRows[] = [
                    'account_code' => self::PERAWATAN_ACCOUNT_CODE,
                    'description'  => 'Biaya Perawatan',
                    'debit'        => $usage['perawatan'],
                    'credit'       => null,
                ];
            }

            if ($unitKey === ($buyerUnitId ?? 'unknown')) {
                $postings[] = [
                    'tag'        => $buyerTag,
                    'debit_rows' => $costDebitRows,
                    'credit_row' => [
                        'account_code' => $persediaanAccount,
                        'description'  => 'Pemakaian Persediaan',
                        'debit'        => null,
                        'credit'       => $unitTotal,
                    ],
                ];
            } else {
                $postings[] = [
                    'tag' => $buyerTag,
                    'debit_rows' => [[
                        'account_code' => self::DUE_FROM_UNIT_ACCOUNT_CODE,
                        'description'  => "Piutang Antar Unit - {$usage['name']}",
                        'debit'        => $unitTotal,
                        'credit'       => null,
                    ]],
                    'credit_row' => [
                        'account_code' => $persediaanAccount,
                        'description'  => "Pemakaian Persediaan ({$usage['name']})",
                        'debit'        => null,
                        'credit'       => $unitTotal,
                    ],
                ];
                $postings[] = [
                    'tag'        => $usage['tag'],
                    'debit_rows' => $costDebitRows,
                    'credit_row' => [
                        'account_code' => self::DUE_TO_UNIT_ACCOUNT_CODE,
                        'description'  => "Utang Antar Unit - {$buyerUnit?->name}",
                        'debit'        => null,
                        'credit'       => $unitTotal,
                    ],
                ];
            }
        }

        return $postings;
    }

    /**
     * Kode-kode item yang termasuk jenis persediaan tertentu (bbm/sparepart),
     * dipakai untuk menentukan window "tangki" antar realisasi berurutan.
     */
    private function itemCodesForType(?string $inventoryType): array
    {
        return array_keys(array_filter(
            self::INVENTORY_TYPE_MAP,
            fn ($type) => $type === $inventoryType
        ));
    }

    /**
     * Konversi baris-baris jurnal (tahap 1 dan opsional tahap 2) menjadi CSV
     * sesuai template Jurnal by Mekari.
     */
    public function toCsv(array $stage1Rows, array $stage2Rows = []): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            '*TransactionNumber', '*TransactionDate', '*AccountCode', 'Description',
            '*Debit', '*Credit', 'Memo', 'Tags', 'Currency', 'RateToBase',
        ]);

        $this->writeCsvLines($handle, $stage1Rows, false);
        $this->writeCsvLines($handle, $stage2Rows, true);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param resource $handle
     */
    private function writeCsvLines($handle, array $rows, bool $isStage2): void
    {
        foreach ($rows as $row) {
            if ($isStage2) {
                // Tahap 2: tiap realisasi bisa menghasilkan >1 posting (satu per
                // unit yang terlibat), masing-masing punya tag & baris sendiri.
                foreach ($row['postings'] as $posting) {
                    foreach ($posting['debit_rows'] as $debitLine) {
                        fputcsv($handle, [
                            "'" . $row['transaction_number'],
                            "'" . $row['transaction_date'],
                            $debitLine['account_code'] ?? '',
                            $debitLine['description'],
                            $debitLine['debit'] ?? '',
                            '',
                            $row['memo'],
                            $posting['tag'],
                            '',
                            '',
                        ]);
                    }
                    $creditLine = $posting['credit_row'];
                    fputcsv($handle, [
                        "'" . $row['transaction_number'],
                        "'" . $row['transaction_date'],
                        $creditLine['account_code'] ?? '',
                        $creditLine['description'],
                        '',
                        $creditLine['credit'] ?? '',
                        $row['memo'],
                        $posting['tag'],
                        '',
                        '',
                    ]);
                }
            } else {
                // Tahap 1: 1 baris debit + 1 baris kredit
                foreach (['debit_row', 'credit_row'] as $side) {
                    $line = $row[$side];
                    fputcsv($handle, [
                        "'" . $row['transaction_number'],
                        "'" . $row['transaction_date'],
                        $line['account_code'] ?? '',
                        $line['description'],
                        $line['debit'] ?? '',
                        $line['credit'] ?? '',
                        $row['memo'],
                        $row['tag'],
                        '',
                        '',
                    ]);
                }
            }
        }
    }

    /**
     * Tandai entry sebagai sudah di-export ke jurnal. Dipanggil hanya saat
     * download final (bukan saat preview).
     */
    public function markExported(array $entryIds, User $actor): void
    {
        RealizationEntry::whereIn('id', $entryIds)
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->update([
                'exported_to_journal_at' => now(),
                'exported_to_journal_by' => $actor->id,
            ]);
    }
}
