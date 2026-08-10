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
        return RealizationEntry::with(['pdoDetail.pdoHeader', 'pdoDetail.expenseItem.subcategory.category', 'recorder', 'attachments', 'vehicle'])
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
     * remaining_kantong = total transfer kantong actor − total realisasi kantong actor (PDO-level).
     * Ini adalah hard ceiling: realisasi baru tidak boleh melebihi remaining_kantong.
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

        // Hitung kantong PDO-level untuk group ini
        $totalKantong = $this->totalKantongForGroup($pdo, $group);

        // Total realisasi seluruh item untuk group ini (PDO-level), sudah dinetkan
        // dengan potongan (lihat totalRealizedForGroup()).
        $totalRealizedGroup = $this->totalRealizedForGroup($pdo, $group);

        $remainingKantong = $totalKantong - $totalRealizedGroup;

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
                    'realized_group' => null,
                    // Sisa dana BULAN LALU yang dibawa masuk ke periode PDO ini —
                    // saldo AWAL periode, bukan saldo berjalan hari ini (currentBalance
                    // sudah mencakup transaksi bulan berjalan, jadi tidak tepat di sini).
                    'saldo'          => $this->cashBook->openingBalanceForPeriod($pdo->plantation_unit_id, $pdo->period_year, $pdo->period_month),
                    'is_fund_return' => true,
                ];
            }
        }

        return [
            'items'             => $result,
            'remaining_kantong' => $remainingKantong,
            'total_kantong'     => $totalKantong,
        ];
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
     * ⚠️ SENGAJA TIDAK DI-CLAMP, beda dengan halaman pelaporan (Rekap, Buku Kas,
     * Dashboard, Daftar PDO) yang memakai DeductionNetting::effectiveRealization().
     * Ini PLAFON INPUT ("Sisa Dana" di Form Realisasi), bukan posisi kas: kerani
     * harus bisa mencatat realisasi PENUH sesuai anggaran, termasuk bagian yang
     * dananya berasal dari panjar periode lalu. Contoh PDO Agustus Sosa — transfer
     * bersih 4.394.864 (sudah dipotong panjar 4.500.000) tapi belanja riilnya
     * 8.894.864; kalau plafon ikut di-clamp, kerani terblokir di 4.394.864.
     * Karena itu angka "Sisa Dana" memang berbeda dari "Saldo" di Rekap, dan itu
     * BUKAN bug — begitu realisasi penuh tercatat, Saldo di Rekap otomatis jadi 0.
     * Sudah dikonfirmasi pemilik produk; jangan "disamakan" tanpa membahas ulang.
     */
    public function totalRealizedForGroup(PdoHeader $pdo, string $group): int
    {
        $trueRealized = (int) RealizationEntry::whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->where('settlement_group', $group)
            ->sum('amount');

        $destinations = $group === RealizationEntry::SETTLEMENT_KEBUN
            ? ['rek_kebun']
            : ['pribadi', 'vendor'];

        $deductionAdjustment = (int) TransferEntry::whereIn('transfer_destination', $destinations)
            ->whereHas('pdoDetail', fn ($q) => $q->where('pdo_header_id', $pdo->id))
            ->whereHas('pdoDetail.expenseItem', fn ($q) => $q->where('is_deduction', true))
            ->sum('amount'); // negatif

        return $trueRealized + $deductionAdjustment;
    }

    /**
     * Catat realisasi baru.
     * BR-REAL-001: hanya saat PDO berstatus final.
     * BR-REAL-002: total realisasi PER KANTONG (PDO-level) tidak boleh melebihi transfer ke
     * kantong itu — ini satu-satunya hard ceiling. Anggaran per item (pdo_detail.amount)
     * tidak lagi jadi batas keras: realisasi 1 item boleh melebihi anggarannya sendiri
     * selama total kantong PDO masih cukup (realokasi antar item dalam 1 kantong diizinkan).
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
        if ($detail && $detail->funding_option === PdoSupplementaryHeader::FUNDING_KAS_KEBUN) {
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

        return DB::transaction(function () use ($detail, $data, $actor, $group, $pdo, $isFundReturn) {
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
                // Batasnya adalah Saldo Kas Kebun yang benar-benar tersedia saat ini.
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
            } else {
                // BR-REAL-002: total realisasi kantong actor (PDO-level) tidak boleh melebihi
                // total transfer ke kantong tersebut (saldo kas kebun / saldo pribadi-vendor).
                $totalKantong       = $this->totalKantongForGroup($pdo, $group);
                $totalRealizedGroup = $this->totalRealizedForGroup($pdo, $group);
                $newGroupTotal = $totalRealizedGroup + $data['amount'];

                if ($newGroupTotal > $totalKantong) {
                    $sisa = $totalKantong - $totalRealizedGroup;
                    abort(response()->json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'REALIZATION_EXCEEDS_KANTONG',
                            'message' => "Total realisasi kantong ini (Rp " . number_format($newGroupTotal, 0, ',', '.') . ") melebihi saldo kantong (Rp " . number_format($totalKantong, 0, ',', '.') . "). Sisa: Rp " . number_format($sisa, 0, ',', '.') . ".",
                        ],
                    ], 422));
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
