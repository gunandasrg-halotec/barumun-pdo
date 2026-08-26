<?php

namespace App\Services\Realization;

use App\Models\AuditLog;
use App\Models\ExpenseItem;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\RealizationEntry;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Report\CashBookQueryService;
use App\Services\Report\DeductionNetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RealizationEntryService
{
    /** Sentinel pdo_detail_id untuk item PENGEMBALIAN SISA DANA BULAN LALU (tidak punya transfer). */
    public const FUND_RETURN_SENTINEL = 'FUND_RETURN';

    public function __construct(
        private ?CashBookQueryService $cashBook = null,
    ) {
        $this->cashBook ??= new CashBookQueryService();
    }

    /**
     * BR-AUTH-001: true kalau PDO ini di luar unit yang boleh diakses actor
     * (row-level security). Actor tanpa plantation_unit_id (role cross-unit)
     * selalu lolos. Pakai app('current_unit_ids') yang di-resolve
     * EnsureUnitAccess (unit sendiri + unit yang di-link, mis. Sosa
     * Replanting) kalau tersedia; fallback ke unit actor sendiri untuk
     * konteks non-HTTP (mis. test/tinker) yang tidak lewat middleware.
     */
    private function unitMismatch(PdoHeader $pdo, User $actor): bool
    {
        if (! $actor->plantation_unit_id) {
            return false;
        }

        $allowedUnitIds = app()->bound('current_unit_ids')
            ? app('current_unit_ids')
            : [$actor->plantation_unit_id];

        return ! in_array($pdo->plantation_unit_id, $allowedUnitIds, true);
    }

    /**
     * Daftar semua entri realisasi (dengan filter opsional).
     * Scoped by company_id; unit-bound roles also scoped by unit.
     */
    public function list(User $actor, array $filters = []): Collection
    {
        return RealizationEntry::with(['pdoDetail.pdoHeader', 'pdoDetail.expenseItem.subcategory.category', 'recorder', 'attachments', 'vehicle', 'pettyCashVoucherLine.pettyCashVoucher'])
            ->whereHas('pdoDetail.pdoHeader', fn ($q) => $q->where('company_id', $actor->company_id))
            ->when($actor->plantation_unit_id, fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('plantation_unit_id', $actor->plantation_unit_id)))
            ->when(!empty($filters['unit_ids']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->whereIn('plantation_unit_id', $filters['unit_ids'])))
            ->when(isset($filters['unit_id']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('plantation_unit_id', $filters['unit_id'])))
            ->when(isset($filters['pdo_detail_id']), fn ($q) => $q->where('pdo_detail_id', $filters['pdo_detail_id']))
            ->when(isset($filters['period_year']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('period_year', $filters['period_year'])))
            ->when(isset($filters['period_month']), fn ($q) => $q->whereHas('pdoDetail.pdoHeader', fn ($qq) => $qq->where('period_month', $filters['period_month'])))
            ->when(!empty($filters['funding_source']), fn ($q) => $q->whereIn('funding_source', $filters['funding_source']))
            ->when(isset($filters['start_date']), fn ($q) => $q->whereDate('transaction_date', '>=', $filters['start_date']))
            ->when(isset($filters['end_date']), fn ($q) => $q->whereDate('transaction_date', '<=', $filters['end_date']))
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * Summary realisasi per PDO (total per detail).
     * GET /pdo/{pdo}/realizations
     */
    public function summaryByPdo(PdoHeader $pdo): Collection
    {
        return $pdo->details()
            ->with(['expenseItem', 'realizationEntries.attachments'])
            ->get()
            ->map(fn ($detail) => [
                'pdo_detail_id'    => $detail->id,
                'expense_item'     => $detail->expenseItem?->only(['id', 'code', 'name']),
                'description'      => $detail->description,
                'amount_approved'  => $detail->amount,
                'total_transferred'=> $detail->total_transferred,
                'total_realized'   => $detail->total_realized,
                'sisa_realisasi'   => $detail->amount - $detail->total_realized,
            ]);
    }

    /**
     * Daftar item realisasi lengkap per PDO (dengan bukti).
     * GET /pdo/{pdo}/realizations/items
     */
    public function itemsByPdo(PdoHeader $pdo): Collection
    {
        return RealizationEntry::with(['pdoDetail.expenseItem', 'recorder', 'attachments'])
            ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * Daftar item yang boleh direalisasi oleh actor + sisa kantong PDO-level.
     *
     * Saldo per item = anggaran − total_realized (bisa negatif jika over-budget).
     * Tampilkan semua item non-deduction; item dengan saldo ≤ 0 tetap ditampilkan
     * agar user bisa melihat status over-budget, tapi tidak bisa dipilih (saldo ≤ 0).
     *
     * remaining_kantong = saldo awal + total transfer kantong actor − total realisasi
     * kantong actor (PDO-level). Ini adalah hard ceiling: realisasi baru tidak boleh
     * melebihi remaining_kantong. Saldo awal hanya berlaku untuk kantong Kas Kebun —
     * lihat openingBalanceForGroup().
     *
     * GET /pdo/{pdo}/realizations/available
     */
    public function availableItemsForActor(PdoHeader $pdo, User $actor): array
    {
        $group = $actor->realizationSettlementGroup();
        if (! $group) {
            return ['items' => [], 'remaining_kantong' => 0];
        }

        $details = $pdo->details()
            ->with(['expenseItem.subcategory.category', 'transferEntries', 'realizationEntries'])
            ->get();

        // Saldo awal kantong (kebun saja) — sisa kas bulan lalu yang masih dipegang.
        $saldoAwal = $this->openingBalanceForGroup($pdo, $group);

        // Hitung kantong PDO-level untuk group ini
        $totalKantong = $this->totalKantongForGroup($pdo, $group);

        // Total realisasi seluruh item untuk group ini (PDO-level), sudah dinetkan
        // dengan potongan (lihat totalRealizedForGroup()).
        $totalRealizedGroup = $this->totalRealizedForGroup($pdo, $group);

        $remainingKantong = $saldoAwal + $totalKantong - $totalRealizedGroup;

        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        $result = [];
        foreach ($details as $detail) {
            if ($detail->expenseItem?->is_deduction) {
                continue;
            }

            // Item hanya tersedia untuk actor jika ada transfer ke kantong actor
            // (transfer_destination per item menentukan kantong mana yang mendanai item ini).
            $hasTransferToActorKantong = $detail->transferEntries->contains(
                fn ($t) => in_array($t->transfer_destination, $destinations, true)
            );
            if (! $hasTransferToActorKantong) {
                continue;
            }

            // Saldo per item: anggaran − total_realized untuk item ini (bisa negatif)
            $totalRealized = (int) $detail->realizationEntries->sum('amount');
            $saldo         = $detail->amount - $totalRealized;

            $item = $detail->expenseItem;
            $result[] = [
                'pdo_detail_id'  => $detail->id,
                'expense_item'   => $item ? [
                    'id'   => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'subcategory' => $item->subcategory ? [
                        'id'   => $item->subcategory->id,
                        'name' => $item->subcategory->name,
                        'category' => $item->subcategory->category ? [
                            'id'   => $item->subcategory->category->id,
                            'name' => $item->subcategory->category->name,
                        ] : null,
                    ] : null,
                ] : null,
                'description'    => $detail->description,
                'bucket'         => $detail->amount,
                'realized_group' => $totalRealized,
                'saldo'          => $saldo,
            ];
        }

        // Item PENGEMBALIAN SISA DANA BULAN LALU: hanya untuk kantong Kas Kebun,
        // tidak punya transfer (dananya dari saldo bulan lalu), jadi ditambahkan
        // sebagai entri virtual terpisah dari loop pdo_details di atas.
        if ($group === RealizationEntry::SETTLEMENT_KEBUN && $pdo->isFinal()) {
            $fundReturnItem = ExpenseItem::where('code', 'PSD-KAS-001')->first();
            if ($fundReturnItem) {
                $result[] = [
                    'pdo_detail_id'  => self::FUND_RETURN_SENTINEL,
                    'expense_item'   => [
                        'id'   => $fundReturnItem->id,
                        'code' => $fundReturnItem->code,
                        'name' => $fundReturnItem->name,
                        'subcategory' => null,
                    ],
                    'description'    => $fundReturnItem->name,
                    'bucket'         => null,
                    'realized_group' => $this->fundReturnRealized($pdo, $fundReturnItem),
                    'saldo'          => $this->fundReturnRemaining($pdo, $fundReturnItem),
                    'is_fund_return' => true,
                ];
            }
        }

        return [
            'items'             => $result,
            'remaining_kantong' => $remainingKantong,
            'total_kantong'     => $totalKantong,
            'saldo_awal'        => $saldoAwal,
        ];
    }

    /**
     * Total pengembalian sisa dana bulan lalu yang SUDAH tercatat di PDO ini.
     */
    public function fundReturnRealized(PdoHeader $pdo, ExpenseItem $fundReturnItem): int
    {
        return (int) RealizationEntry::whereHas('pdoDetail', fn ($q) => $q
            ->where('pdo_header_id', $pdo->id)
            ->where('expense_item_id', $fundReturnItem->id)
        )->sum('amount');
    }

    /**
     * Sisa dana bulan lalu yang MASIH bisa dikembalikan = saldo awal periode
     * dikurangi pengembalian yang sudah tercatat, tidak pernah negatif.
     *
     * Saldo awal dipakai (bukan currentBalance) karena yang dikembalikan adalah
     * sisa dana BULAN LALU, bukan saldo berjalan yang sudah tercampur transaksi
     * bulan ini. Tanpa pengurangan ini, dropdown Input Realisasi tetap menampilkan
     * saldo penuh walau pengembaliannya sudah dicatat — dan kerani bisa mencatat
     * pengembalian berulang kali melebihi dana yang benar-benar dibawa dari bulan lalu.
     */
    public function fundReturnRemaining(PdoHeader $pdo, ExpenseItem $fundReturnItem): int
    {
        $openingBalance = $this->cashBook->openingBalanceForPeriod(
            $pdo->plantation_unit_id, $pdo->period_year, $pdo->period_month
        );

        return max(0, $openingBalance - $this->fundReturnRealized($pdo, $fundReturnItem));
    }

    /**
     * Nomor bukti berikutnya untuk kode item tertentu DALAM SATU PDO header.
     * Format: {PDO_Number}/{Item_code}/{seq}. Sequence diteruskan lintas semua
     * pdo_detail dengan kode item yang sama dalam header ini — termasuk detail
     * yang berasal dari PDO Tambahan yang sudah di-merge — bukan direset per
     * pdo_detail_id, karena satu kode item bisa punya beberapa baris pdo_detail
     * berbeda dalam PDO yang sama.
     *
     * Dipakai untuk prefill di frontend (endpoint terpisah) dan sebagai fallback
     * generator saat proof_number kosong di store(). Pemanggil yang butuh
     * jaminan bebas race harus memanggil ini di dalam transaksi setelah
     * mengunci baris PdoHeader (lihat store()).
     */
    public function nextProofNumber(PdoHeader $pdo, string $itemCode): string
    {
        $existing = RealizationEntry::whereHas(
            'pdoDetail',
            fn ($q) => $q->where('pdo_header_id', $pdo->id)
                ->whereHas('expenseItem', fn ($qq) => $qq->where('code', $itemCode))
        )->pluck('proof_number');

        $maxSeq = 0;
        foreach ($existing as $proofNumber) {
            if (preg_match('/\/(\d+)$/', (string) $proofNumber, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }

        return "{$pdo->pdo_number}/{$itemCode}/" . ($maxSeq + 1);
    }

    /**
     * True jika proof_number sudah dipakai entri LAIN dalam PDO header yang sama.
     * Duplikat dicek per-PDO (bukan per pdo_detail_id) karena nomor bukti harus
     * unik di seluruh PDO — lihat catatan pada nextProofNumber().
     */
    public function proofNumberExists(PdoHeader $pdo, string $proofNumber, ?string $excludeEntryId = null): bool
    {
        return RealizationEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->where('proof_number', $proofNumber)
            ->when($excludeEntryId, fn ($q) => $q->where('id', '!=', $excludeEntryId))
            ->exists();
    }

    /**
     * Saldo awal kantong ini di awal periode PDO.
     *
     * HANYA kantong Kas Kebun yang punya saldo awal: kas kebun memegang uang
     * fisik, sehingga sisa bulan lalu benar-benar masih ada di tangan kerani dan
     * sah dipakai membiayai realisasi bulan ini. Kantong Pribadi/Vendor tidak
     * pernah menyimpan kas — HO mentransfer langsung ke rekening orang/rekanan
     * per item — jadi tetap per-periode (bandingkan DashboardService, yang juga
     * memperlakukan pribadi/vendor per-periode).
     */
    public function openingBalanceForGroup(PdoHeader $pdo, string $group): int
    {
        if ($group !== RealizationEntry::SETTLEMENT_KEBUN) {
            return 0;
        }

        return $this->cashBook->openingBalanceForPeriod(
            $pdo->plantation_unit_id, $pdo->period_year, $pdo->period_month
        );
    }

    /**
     * Sisa dana kantong actor = saldo awal + transfer − realisasi (efektif).
     *
     * Satu-satunya sumber kebenaran "Sisa Dana"/plafon BR-REAL-002: dipakai
     * availableItemsForActor(), store(), PettyCashVoucherService, dan
     * AutoRealizationService supaya angka di form Realisasi, form Voucher, dan
     * pesan error selalu sama. Rumusnya sengaja identik dengan saldo di Buku Kas
     * Harian, Rekap Buku Kas, Daftar PDO, dan Dashboard.
     */
    public function remainingKantongForGroup(PdoHeader $pdo, string $group): int
    {
        return $this->openingBalanceForGroup($pdo, $group)
            + $this->totalKantongForGroup($pdo, $group)
            - $this->totalRealizedForGroup($pdo, $group);
    }

    /**
     * Total transfer ke kantong milik group ini untuk seluruh PDO.
     * Kebun = rek_kebun; pribadi_vendor = pribadi + vendor.
     */
    public function totalKantongForGroup(PdoHeader $pdo, string $group): int
    {
        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        return (int) TransferEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->whereIn('transfer_destination', $destinations)
            ->sum('amount');
    }

    /**
     * Total realisasi kantong milik group ini untuk seluruh PDO, dinetkan dengan
     * item potongan (mis. POTONGAN PANJAR) — down payment yang SUDAH direalisasikan
     * (dibayar tunai) pada periode sebelumnya. Karena sistem tidak bisa membebankan
     * potongan itu ke item spesifik saat kerani mencatat realisasi, kerani mencatat
     * realisasi PENUH sesuai anggaran tiap item, sehingga realization_entries
     * mendata lebih besar dari kas yang benar-benar keluar BARU periode ini.
     *
     * Potongan direpresentasikan sebagai TransferEntry NEGATIF (bukan
     * RealizationEntry), sehingga tidak pernah ikut ter-sum di query
     * RealizationEntry di atas — perlu ditambahkan manual di sini.
     *
     * Netting dilakukan PER KATEGORI dan di-clamp 0, skop yang sama persis dengan
     * RecapQueryService dan CashBookQueryService::buildExpenseRows() — supaya
     * "Sisa Dana" di form Realisasi/Voucher, "Saldo" di Rekap, dan saldo akhir di
     * Buku Kas Harian selalu menghasilkan angka yang sama.
     *
     * Panjar hanya boleh dinetkan terhadap realisasi kategorinya sendiri: kredit
     * yang belum terpakai tertahan sampai kerani melengkapi realisasi kategori itu
     * — bukan ditambal surplus kategori lain (yang akan mengecilkan realisasi item
     * yang tidak punya uang muka sama sekali).
     *
     * Skopnya KATEGORI, bukan sub-kategori, karena kerani memperkirakan beban
     * pekerjaan di muka dan perkiraan itu bisa meleset sehingga panjar satu
     * sub-kategori melebihi biaya riilnya (kasus Binanga Juli 2026). Pekerja yang
     * sama umumnya juga mengerjakan sub-kategori lain di kategori yang sama, jadi
     * kelebihan panjar wajar diserap sub-kategori tetangga daripada tertahan dan
     * membuat saldo kantong tampil minus padahal tidak ada kas yang negatif.
     *
     * Clamp di level kategori TIDAK memblokir kerani mencatat realisasi penuh:
     * begitu realisasi kategori yang punya panjar mulai masuk, kreditnya ikut
     * terlepas sebesar realisasi itu, sehingga plafon otomatis melebar mengikuti
     * kebutuhan. Tanpa clamp, hasilnya bisa negatif dan membuat "Sisa Dana" tampil
     * lebih besar dari total dana yang benar-benar ditransfer.
     */
    public function totalRealizedForGroup(PdoHeader $pdo, string $group): int
    {
        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        // Realisasi per kategori (pdo_detail → expense_item → subcategory → category).
        $realizedByCat = RealizationEntry::query()
            ->join('pdo_details', 'pdo_details.id', '=', 'realization_entries.pdo_detail_id')
            ->join('expense_items', 'expense_items.id', '=', 'pdo_details.expense_item_id')
            ->join('expense_subcategories', 'expense_subcategories.id', '=', 'expense_items.subcategory_id')
            ->where('pdo_details.pdo_header_id', $pdo->id)
            ->where('realization_entries.settlement_group', $group)
            ->groupBy('expense_subcategories.category_id')
            ->selectRaw('expense_subcategories.category_id AS cat_id, SUM(realization_entries.amount) AS total')
            ->pluck('total', 'cat_id');

        // Potongan per kategori — TransferEntry negatif, bukan RealizationEntry.
        $deductionByCat = TransferEntry::query()
            ->join('pdo_details', 'pdo_details.id', '=', 'transfer_entries.pdo_detail_id')
            ->join('expense_items', 'expense_items.id', '=', 'pdo_details.expense_item_id')
            ->join('expense_subcategories', 'expense_subcategories.id', '=', 'expense_items.subcategory_id')
            ->where('pdo_details.pdo_header_id', $pdo->id)
            ->whereIn('transfer_entries.transfer_destination', $destinations)
            ->where('expense_items.is_deduction', true)
            ->groupBy('expense_subcategories.category_id')
            ->selectRaw('expense_subcategories.category_id AS cat_id, SUM(transfer_entries.amount) AS total')
            ->pluck('total', 'cat_id'); // negatif

        $total = 0;
        foreach ($realizedByCat->keys()->merge($deductionByCat->keys())->unique() as $catId) {
            $total += DeductionNetting::effectiveRealization(
                (int) ($realizedByCat[$catId] ?? 0),
                (int) ($deductionByCat[$catId] ?? 0),
            );
        }

        return $total;
    }

    /**
     * Catat realisasi baru.
     * BR-REAL-001: hanya saat PDO berstatus final.
     * BR-REAL-002: total realisasi PER KANTONG (PDO-level) tidak boleh melebihi transfer ke
     * kantong itu. Untuk kantong Kas Kebun ini satu-satunya hard ceiling — anggaran per item
     * (pdo_detail.amount) bukan batas keras, realisasi 1 item boleh melebihi anggarannya
     * sendiri selama total kantong PDO masih cukup (realokasi antar item diizinkan).
     * BR-REAL-006: khusus kantong pribadi_vendor, ADA hard ceiling tambahan PER ITEM —
     * realisasi 1 item tidak boleh melebihi transfer yang diterima item itu sendiri ke
     * destinasi pribadi/vendor (tidak boleh realokasi antar item, beda dari Kas Kebun).
     * BR-REAL-005: KERANI hanya boleh realisasi kantong rek_kebun; STAFF_PURCHASING
     * & MANAJER_KEUANGAN hanya kantong pribadi+vendor. Item potongan tidak bisa direalisasi.
     */
    public function store(array $data, User $actor): RealizationEntry
    {
        $isFundReturn = $data['pdo_detail_id'] === self::FUND_RETURN_SENTINEL;

        if ($isFundReturn) {
            $pdo    = PdoHeader::findOrFail($data['pdo_header_id']);
            $detail = null;
        } else {
            $detail = PdoDetail::with('expenseItem')->findOrFail($data['pdo_detail_id']);
            $pdo    = $detail->pdoHeader;
        }

        // BR-AUTH-001: Verify PDO belongs to user's unit (row-level security)
        if ($this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa dicatat untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        // BR-REAL-001
        if (! $pdo->isFinal()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => 'Realisasi hanya bisa dicatat saat PDO berstatus final.'],
            ], 409));
        }

        // PDO Tambahan "Gunakan Kas Kebun": item ini tidak punya dana transfer dari HO,
        // jadi funding_source dipaksa kas_kebun terlepas dari pilihan di request — dicek
        // sebelum BR-REAL-004 supaya larangan role tetap berlaku untuk nilai efektifnya.
        // Dipakai lagi di bawah untuk mengecualikan item ini dari plafon BR-REAL-002.
        $isKasKebunFunded = $detail && $detail->funding_option === PdoSupplementaryHeader::FUNDING_KAS_KEBUN;

        if ($isKasKebunFunded) {
            $data['funding_source'] = RealizationEntry::FUNDING_KAS_KEBUN;
        }

        // BR-REAL-004: STAFF_PURCHASING tidak boleh menggunakan kas_kebun sebagai sumber dana
        if (($actor->role?->code === 'STAFF_PURCHASING') && (($data['funding_source'] ?? '') === 'kas_kebun')) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FUNDING_SOURCE_FORBIDDEN', 'message' => 'Role STAFF_PURCHASING tidak diizinkan menggunakan sumber dana kas_kebun.'],
            ], 403));
        }

        // BR-REAL-005: tentukan kantong role aktor
        $group = $actor->realizationSettlementGroup();
        if (! $group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak mencatat realisasi.'],
            ], 403));
        }

        // BR-PCV-001: realisasi kantong kebun dengan sumber dana kas_kebun (tunai) WAJIB
        // lewat Petty Cash Voucher. Pengembalian sisa dana bulan lalu dikecualikan
        // (voucher tidak menampung item ini — lihat FUND_RETURN_NOT_IN_VOUCHER di
        // PettyCashVoucherService). Flag _from_petty_cash_voucher tidak bisa dipalsukan
        // dari HTTP: controller memanggil store($request->validated()) dan validated()
        // hanya mengembalikan key yang dideklarasikan di StoreRealizationEntryRequest::rules()
        // — key ini sengaja tidak ditambahkan ke sana.
        if ($group === RealizationEntry::SETTLEMENT_KEBUN
            && ($data['funding_source'] ?? '') === RealizationEntry::FUNDING_KAS_KEBUN
            && ! $isFundReturn
            && empty($data['_from_petty_cash_voucher'])) {
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'REALIZATION_REQUIRES_VOUCHER',
                    'message' => 'Realisasi tunai dari Kas Kebun harus dicatat lewat Petty Cash Voucher. Buat voucher, cetak, minta tanda tangan, lalu unggah scan-nya.',
                ],
            ], 422));
        }

        // Item potongan tidak bisa direalisasi
        if ($detail && $detail->expenseItem?->is_deduction) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'DEDUCTION_NOT_REALIZABLE', 'message' => 'Item potongan tidak bisa direalisasi.'],
            ], 403));
        }

        // Pengembalian sisa dana bulan lalu: hanya kantong Kas Kebun, hanya funding_source
        // kas_kebun/rekening_kebun — dananya dari saldo bulan lalu, bukan transfer bulan ini.
        if ($isFundReturn) {
            if ($group !== RealizationEntry::SETTLEMENT_KEBUN) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'FUND_RETURN_KEBUN_ONLY', 'message' => 'Pengembalian sisa dana hanya berlaku untuk kantong Kas Kebun.'],
                ], 403));
            }
            if (! in_array($data['funding_source'], [RealizationEntry::FUNDING_KAS_KEBUN, RealizationEntry::FUNDING_REKENING_KEBUN], true)) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'FUND_RETURN_FUNDING_SOURCE_INVALID', 'message' => 'Sumber dana pengembalian harus Kas Kebun/Rekening Kebun.'],
                ], 422));
            }
        }

        return DB::transaction(function () use ($detail, $data, $actor, $group, $pdo, $isFundReturn, $isKasKebunFunded) {
            // Lock PDO header row untuk menyerialkan pembuatan/validasi proof_number
            // dalam PDO ini — mencegah dua request bersamaan menghasilkan nomor yang
            // sama (baik lewat auto-generate maupun input manual yang kebetulan bentrok).
            $pdo = PdoHeader::lockForUpdate()->findOrFail($pdo->id);

            if ($isFundReturn) {
                $fundReturnItem = ExpenseItem::where('code', 'PSD-KAS-001')->firstOrFail();
                $detail = PdoDetail::firstOrCreate(
                    ['pdo_header_id' => $pdo->id, 'expense_item_id' => $fundReturnItem->id],
                    [
                        'account_number' => $fundReturnItem->default_account_number,
                        'description'    => $fundReturnItem->name,
                        'amount'         => 0,
                        'display_order'  => 999,
                    ]
                )->load('expenseItem');
            }

            // Lock detail row to prevent race condition on cumulative validation
            $detail = PdoDetail::lockForUpdate()->findOrFail($detail->id);

            $itemCode    = $detail->expenseItem?->code ?? 'NOITEM';
            $proofNumber = trim((string) ($data['proof_number'] ?? ''));

            if ($proofNumber === '') {
                $proofNumber = $this->nextProofNumber($pdo, $itemCode);
            } elseif ($this->proofNumberExists($pdo, $proofNumber)) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'PROOF_NUMBER_DUPLICATE',
                        'message' => "No. referensi \"{$proofNumber}\" sudah dipakai entri realisasi lain pada PDO ini. Gunakan nomor lain.",
                    ],
                ], 422));
            }

            if ($isFundReturn) {
                // Bukan BR-REAL-002 (plafon transfer) — dananya dari saldo bulan lalu.
                // Batas 1: Saldo Kas Kebun yang benar-benar tersedia saat ini.
                $currentBalance = $this->cashBook->currentBalance($pdo->plantation_unit_id);
                if ($data['amount'] > $currentBalance) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'FUND_RETURN_EXCEEDS_BALANCE',
                            'message' => "Jumlah pengembalian (Rp " . number_format($data['amount'], 0, ',', '.') . ") melebihi Saldo Kas Kebun saat ini (Rp " . number_format($currentBalance, 0, ',', '.') . ").",
                        ],
                    ], 422));
                }

                // Batas 2: sisa dana bulan lalu yang belum dikembalikan. Tanpa ini,
                // pengembalian bisa dicatat berulang kali melebihi dana yang benar-benar
                // dibawa masuk dari bulan lalu (saldo awal periode) — selama saldo kas
                // berjalan masih cukup, cek pertama saja tidak menangkapnya.
                $remaining = $this->fundReturnRemaining($pdo, $detail->expenseItem);
                if ($data['amount'] > $remaining) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'FUND_RETURN_EXCEEDS_REMAINING',
                            'message' => "Jumlah pengembalian (Rp " . number_format($data['amount'], 0, ',', '.') . ") melebihi sisa dana bulan lalu yang belum dikembalikan (Rp " . number_format($remaining, 0, ',', '.') . ").",
                        ],
                    ], 422));
                }
            } else {
                // BR-REAL-006: khusus kantong pribadi_vendor, realisasi PER ITEM tidak boleh
                // melebihi transfer yang diterima item itu sendiri ke destinasi pribadi/vendor.
                // Beda dengan kantong Kas Kebun (BR-REAL-002 saja, realokasi antar item dalam
                // 1 kantong diizinkan) — kantong pribadi/vendor terikat ke pembelian/rekanan
                // spesifik per item, jadi tidak boleh "meminjam" dana transfer item lain.
                // Ditambahkan setelah insiden PBB-PRR-001 (PDO-2026-08-SS-001): 1 item yang
                // transfer-nya di-split ke 2 kantong (rek_kebun + vendor) direalisasikan penuh
                // ke kantong pribadi_vendor saja, menggerus jatah item pribadi/vendor lain.
                if ($group === RealizationEntry::SETTLEMENT_PRIBADI_VENDOR) {
                    $itemTransferred = (int) TransferEntry::where('pdo_detail_id', $detail->id)
                        ->whereIn('transfer_destination', ['pribadi', 'vendor'])
                        ->sum('amount');
                    $itemRealized = (int) RealizationEntry::where('pdo_detail_id', $detail->id)
                        ->where('settlement_group', RealizationEntry::SETTLEMENT_PRIBADI_VENDOR)
                        ->sum('amount');
                    $itemNewTotal = $itemRealized + $data['amount'];

                    if ($itemNewTotal > $itemTransferred) {
                        $sisaItem = $itemTransferred - $itemRealized;
                        abort(response()->json([
                            'success' => false,
                            'error'   => [
                                'code'    => 'REALIZATION_EXCEEDS_ITEM_TRANSFER',
                                'message' => "Realisasi item ini (Rp " . number_format($itemNewTotal, 0, ',', '.') . ") melebihi transfer ke item ini di kantong pribadi/vendor (Rp " . number_format($itemTransferred, 0, ',', '.') . "). Sisa: Rp " . number_format($sisaItem, 0, ',', '.') . ".",
                            ],
                        ], 422));
                    }
                }

                // BR-REAL-002: total realisasi kantong actor (PDO-level) tidak boleh melebihi
                // total transfer ke kantong tersebut (saldo kas kebun / saldo pribadi-vendor).
                //
                // DIKECUALIKAN untuk item PDO Tambahan "Gunakan Kas Kebun": dananya berasal
                // dari saldo kas kebun yang SUDAH ada, bukan dari transfer PDO ini, dan
                // TransferEntry penandanya bernominal 0 (lihat
                // PdoSupplementaryApprovalService::mergeIntoParent()). Tanpa pengecualian ini
                // item tsb mustahil direalisasikan di PDO yang tidak punya transfer lain —
                // plafonnya 0.
                //
                // Kecukupan dananya sudah divalidasi DI DEPAN saat PDOT dibuat: sistem
                // menolak pengajuan yang melebihi saldo kas kebun, dan kerani diberi tahu
                // hanya boleh memakai jalur ini bila yakin saldo tsb memang sisa — bukan
                // dana yang sudah terikat untuk item PDO existing yang belum direalisasi
                // (jumlah terikat itu tidak bisa dihitung sistem, karena realisasinya
                // memang belum ada).
                //
                // Realisasinya TETAP dihitung di totalRealizedForGroup() — uangnya keluar
                // dari pot kas yang sama, jadi memang benar mengurangi sisa dana item lain.
                if (! $isKasKebunFunded) {
                    // Plafon = saldo awal + transfer − realisasi, sama persis dengan
                    // "Sisa Dana" yang ditampilkan di form (remainingKantongForGroup()).
                    // Saldo awal hanya menambah plafon kantong Kas Kebun; kantong
                    // Pribadi/Vendor tetap murni transfer − realisasi.
                    $sisa = $this->remainingKantongForGroup($pdo, $group);

                    if ($data['amount'] > $sisa) {
                        $plafon = $this->openingBalanceForGroup($pdo, $group)
                            + $this->totalKantongForGroup($pdo, $group);

                        abort(response()->json([
                            'success' => false,
                            'error'   => [
                                'code'    => 'REALIZATION_EXCEEDS_KANTONG',
                                'message' => "Realisasi ini (Rp " . number_format($data['amount'], 0, ',', '.') . ") melebihi sisa dana kantong (Rp " . number_format($sisa, 0, ',', '.') . ") dari total dana tersedia Rp " . number_format($plafon, 0, ',', '.') . " (saldo awal + transfer).",
                            ],
                        ], 422));
                    }
                }
            }

            $entry = RealizationEntry::create([
                'pdo_detail_id'    => $detail->id,
                'vehicle_id'       => $data['vehicle_id'] ?? null,
                'recorded_by'      => $actor->id,
                'transaction_date' => $data['transaction_date'],
                'amount'           => $data['amount'],
                'payment_method'   => $data['payment_method'],
                'proof_number'     => $proofNumber,
                'funding_source'   => $data['funding_source'],
                'explanation'      => $data['explanation'] ?? null,
                'settlement_group' => $group,
            ]);

            AuditLog::record(
                actor: $actor,
                entityType: 'realization_entries',
                entityId: $entry->id,
                action: 'INSERT',
                oldValues: null,
                newValues: $entry->toArray()
            );

            return $entry->load(['pdoDetail.expenseItem', 'recorder']);
        });
    }

    /**
     * Koreksi entri realisasi.
     * Hanya bisa ubah saat PDO masih final (belum closed).
     */
    public function update(RealizationEntry $entry, array $data, User $actor): RealizationEntry
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        // BR-AUTH-001: Verify PDO belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa diubah untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa diubah setelah PDO ditutup.'],
            ], 409));
        }

        // PDO Tambahan "Gunakan Kas Kebun": funding_source item ini tetap dipaksa kas_kebun
        // saat koreksi, sama seperti store().
        if ($entry->pdoDetail->funding_option === PdoSupplementaryHeader::FUNDING_KAS_KEBUN) {
            $data['funding_source'] = RealizationEntry::FUNDING_KAS_KEBUN;
        }

        // BR-REAL-004: STAFF_PURCHASING tidak boleh menggunakan kas_kebun sebagai sumber dana
        if (isset($data['funding_source']) && ($actor->role?->code === 'STAFF_PURCHASING') && $data['funding_source'] === 'kas_kebun') {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FUNDING_SOURCE_FORBIDDEN', 'message' => 'Role STAFF_PURCHASING tidak diizinkan menggunakan sumber dana kas_kebun.'],
            ], 403));
        }

        // BR-REAL-005: hanya role dengan kantong yang sama boleh mengedit entri ini
        if ($actor->realizationSettlementGroup() !== $entry->settlement_group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak mengubah realisasi kantong ini.'],
            ], 403));
        }

        // Realisasi hasil Petty Cash Voucher yang sudah posted: jumlah terkunci (sudah
        // tercetak di kertas bertanda tangan), tapi penjelasan/tanggal masih boleh
        // diperbaiki (keputusan produk #11).
        if ($entry->pettyCashVoucherLine?->pettyCashVoucher?->isPosted()
            && array_key_exists('amount', $data)
            && (int) $data['amount'] !== (int) $entry->amount) {
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'REALIZATION_AMOUNT_LOCKED_BY_VOUCHER',
                    'message' => 'Jumlah tidak bisa diubah karena sudah tercetak di voucher bertanda tangan. Penjelasan dan tanggal masih bisa diperbaiki.',
                ],
            ], 422));
        }

        return DB::transaction(function () use ($entry, $data, $actor, $pdo) {
            if (isset($data['proof_number'])) {
                $newProofNumber = trim((string) $data['proof_number']);
                if ($newProofNumber !== $entry->proof_number && $this->proofNumberExists($pdo, $newProofNumber, $entry->id)) {
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'PROOF_NUMBER_DUPLICATE',
                            'message' => "No. referensi \"{$newProofNumber}\" sudah dipakai entri realisasi lain pada PDO ini. Gunakan nomor lain.",
                        ],
                    ], 422));
                }
                $data['proof_number'] = $newProofNumber;
            }

            $old = $entry->toArray();
            $entry->update($data);

            AuditLog::record(
                actor: $actor,
                entityType: 'realization_entries',
                entityId: $entry->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $entry->fresh()->toArray()
            );

            return $entry->fresh()->load(['pdoDetail.expenseItem', 'recorder']);
        });
    }

    /**
     * Hapus entri realisasi.
     * Hanya bisa saat PDO belum closed.
     */
    public function destroy(RealizationEntry $entry, User $actor): void
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        // BR-AUTH-001: Verify PDO belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($this->unitMismatch($pdo, $actor)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Realisasi hanya bisa dihapus untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Realisasi tidak bisa dihapus setelah PDO ditutup.'],
            ], 409));
        }

        // BR-REAL-005: hanya role dengan kantong yang sama boleh menghapus entri ini.
        // Pengecualian: entri otomatis (dibuat saat transfer ditandai "sudah ditransfer")
        // boleh dihapus oleh Direktur Keuangan meski bukan pemilik kantong — supaya tidak
        // terkunci selamanya, karena tidak ada actor "manusia" pemilik kantong ini_vendor
        // untuk entri yang dibuat sistem atas nama proses transfer.
        $isAutoAndAuthorized = $entry->is_auto_generated && $actor->canMarkTransferExecuted();
        if (! $isAutoAndAuthorized && $actor->realizationSettlementGroup() !== $entry->settlement_group) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'REALIZATION_ROLE_FORBIDDEN', 'message' => 'Role Anda tidak berhak menghapus realisasi kantong ini.'],
            ], 403));
        }

        // Realisasi hasil Petty Cash Voucher yang sudah posted (scan ter-upload) terkunci
        // — sudah tercetak di kertas bertanda tangan, koreksi harus lewat voucher pengganti.
        if ($entry->pettyCashVoucherLine?->pettyCashVoucher?->isPosted()) {
            $voucherNumber = $entry->pettyCashVoucherLine->pettyCashVoucher->voucher_number;
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'REALIZATION_LOCKED_BY_VOUCHER',
                    'message' => "Realisasi ini berasal dari voucher {$voucherNumber} yang sudah ditandatangani. Voucher terkunci — koreksi harus lewat voucher pengganti.",
                ],
            ], 422));
        }

        $old = $entry->toArray();
        $entry->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'realization_entries',
            entityId: $entry->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }
}
