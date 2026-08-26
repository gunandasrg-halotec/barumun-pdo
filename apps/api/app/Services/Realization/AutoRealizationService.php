<?php

namespace App\Services\Realization;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\RealizationEntry;
use App\Models\TransferEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Membuat realisasi otomatis untuk detail PDO yang ditransfer ke kantong
 * pribadi/vendor, dipanggil dari TransferEntryService::markTransferred().
 *
 * Terpisah dari RealizationEntryService::store() karena store() menurunkan
 * settlement_group dari role actor — dan Direktur Keuangan tidak punya
 * kantong (realizationSettlementGroup() => null), sehingga akan ditolak 403.
 * Service ini menetapkan settlement_group secara eksplisit (selalu
 * pribadi_vendor, karena hanya dipanggil untuk transfer ke kantong itu),
 * tapi tetap menegakkan aturan bisnis lain (plafon kantong, blokir item
 * potongan, PDO harus final).
 */
class AutoRealizationService
{
    public function __construct(
        private ?RealizationEntryService $realizationEntryService = null,
    ) {
        $this->realizationEntryService ??= new RealizationEntryService();
    }

    /**
     * Buat realisasi untuk setiap pdo_detail_id yang deltanya (transfer − realisasi
     * pribadi_vendor yang sudah ada) > 0. PDO header HARUS sudah dikunci
     * (lockForUpdate) oleh pemanggil sebelum method ini dipanggil, supaya
     * nomor bukti dan plafon kantong konsisten dalam transaksi yang sama.
     *
     * @param  Collection<int, string>  $detailIds  pdo_detail_id yang tujuannya pribadi/vendor
     * @param  array<string,string>  $vehicleByDetailId  pdo_detail_id => vehicle_id
     * @return int  jumlah realisasi yang dibuat
     */
    public function createForDetails(Collection $detailIds, User $actor, array $vehicleByDetailId = []): int
    {
        $created = 0;

        $details = PdoDetail::with(['expenseItem', 'pdoHeader', 'transferEntries', 'realizationEntries'])
            ->whereIn('id', $detailIds->unique()->values())
            ->get();

        foreach ($details as $detail) {
            if ($detail->expenseItem?->is_deduction) {
                continue;
            }

            $pdo = $detail->pdoHeader;

            if (! $pdo->isFinal()) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'PDO_NOT_FINAL', 'message' => "PDO {$pdo->pdo_number} tidak berstatus final — realisasi tidak bisa dibuat otomatis."],
                ], 409));
            }

            $transferredTotal = (int) $detail->transferEntries
                ->whereIn('transfer_destination', [TransferEntry::DEST_PRIBADI, TransferEntry::DEST_VENDOR])
                ->sum('amount');

            $realizedTotal = (int) $detail->realizationEntries
                ->where('settlement_group', RealizationEntry::SETTLEMENT_PRIBADI_VENDOR)
                ->sum('amount');

            $delta = $transferredTotal - $realizedTotal;

            if ($delta <= 0) {
                continue;
            }

            $itemCode = $detail->expenseItem?->code ?? '';

            if (in_array($itemCode, RealizationJournalExportService::INVENTORY_ITEM_CODES, true) && empty($vehicleByDetailId[$detail->id])) {
                abort(response()->json([
                    'success' => false,
                    'error'   => ['code' => 'VEHICLE_REQUIRED', 'message' => "Kendaraan wajib dipilih untuk item {$itemCode}."],
                ], 422));
            }

            // BR-REAL-002: plafon kantong PDO-level (pribadi_vendor) tidak boleh terlampaui.
            // Kantong pribadi/vendor tidak punya saldo awal, jadi sisanya tetap
            // transfer − realisasi (lihat RealizationEntryService::openingBalanceForGroup()).
            $sisa = $this->realizationEntryService->remainingKantongForGroup($pdo, RealizationEntry::SETTLEMENT_PRIBADI_VENDOR);

            if ($delta > $sisa) {
                abort(response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'REALIZATION_EXCEEDS_KANTONG',
                        'message' => "Total realisasi kantong pribadi/vendor PDO {$pdo->pdo_number} akan melebihi saldo kantong. Sisa: Rp " . number_format($sisa, 0, ',', '.') . ".",
                    ],
                ], 422));
            }

            $proofNumber = $this->realizationEntryService->nextProofNumber($pdo, $itemCode);

            $entry = RealizationEntry::create([
                'pdo_detail_id'      => $detail->id,
                'vehicle_id'         => $vehicleByDetailId[$detail->id] ?? null,
                'recorded_by'        => $actor->id,
                'transaction_date'   => $this->resolveTransactionDate($pdo),
                'amount'             => $delta,
                'payment_method'     => RealizationEntry::PAYMENT_TRANSFER,
                'proof_number'       => $proofNumber,
                'funding_source'     => RealizationEntry::FUNDING_REKENING_UTAMA,
                'explanation'        => null,
                'settlement_group'   => RealizationEntry::SETTLEMENT_PRIBADI_VENDOR,
                'is_auto_generated'  => true,
            ]);

            AuditLog::record(
                actor: $actor,
                entityType: 'realization_entries',
                entityId: $entry->id,
                action: 'INSERT',
                oldValues: null,
                newValues: array_merge($entry->toArray(), ['_alasan' => 'Auto-realisasi saat transfer pribadi/vendor ditandai sudah ditransfer.']),
            );

            $created++;
        }

        return $created;
    }

    /**
     * True jika realisasi pribadi_vendor pada detail ini masih dalam batas transfer
     * yang is_transferred — dipanggil sebelum membatalkan tanda "sudah ditransfer".
     */
    public function assertUnmarkAllowed(PdoDetail $detail, int $transferredAfterUnmark): void
    {
        $realizedTotal = (int) RealizationEntry::where('pdo_detail_id', $detail->id)
            ->where('settlement_group', RealizationEntry::SETTLEMENT_PRIBADI_VENDOR)
            ->sum('amount');

        if ($realizedTotal > $transferredAfterUnmark) {
            $itemCode = $detail->expenseItem?->code ?? $detail->id;
            abort(response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'UNMARK_EXCEEDS_REALIZATION',
                    'message' => "Tidak bisa membatalkan tanda transfer untuk item {$itemCode}: realisasi (Rp " . number_format($realizedTotal, 0, ',', '.') . ") sudah melebihi sisa transfer.",
                ],
            ], 422));
        }
    }

    /**
     * Tanggal transaksi realisasi otomatis = hari ini, dijepit ke rentang periode PDO.
     * Mencegah realisasi PDO bulan lalu yang baru ditandai bulan ini jatuh di luar
     * periode — yang akan membuat Rekap dan Buku Kas Harian tidak sinkron.
     */
    private function resolveTransactionDate(PdoHeader $pdo): string
    {
        $today      = Carbon::today();
        $periodStart = Carbon::create($pdo->period_year, $pdo->period_month, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();

        if ($today->lt($periodStart)) {
            return $periodStart->toDateString();
        }
        if ($today->gt($periodEnd)) {
            return $periodEnd->toDateString();
        }

        return $today->toDateString();
    }
}
