<?php

namespace App\Services\PdoSupplementary;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoSupplementaryHeader;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\PDO\PdoService;
use Illuminate\Support\Facades\DB;

class PdoSupplementaryMergeService
{
    public function __construct(
        private readonly PdoService $pdoService = new PdoService()
    ) {}

    /**
     * Merge PDO Tambahan ke PDO Bulanan induk.
     *
     * BR-MERGE-001: Status PDO Tambahan harus final_merged (sudah disetujui Direktur).
     * BR-MERGE-002: Hanya MANAJER_KEUANGAN atau DIREKTUR_KEUANGAN yang bisa merge.
     * BR-MERGE-003: Setiap detail PDO Tambahan disalin ke pdo_details PDO Bulanan
     *              dengan source_pdo_supplementary_id diisi (traceability).
     * BR-MERGE-004: merged_at di-set saat merge berhasil.
     */
    public function merge(PdoSupplementaryHeader $supp, User $actor): PdoSupplementaryHeader
    {
        // BR-MERGE-002
        if (! $actor->hasAnyRole([Role::MANAJER_KEUANGAN, Role::DIREKTUR_KEUANGAN])) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Hanya Manajer/Direktur Keuangan yang bisa melakukan merge PDO Tambahan.'],
            ], 403));
        }

        // BR-MERGE-001: harus sudah final_merged (approved oleh Direktur)
        if ($supp->status !== PdoSupplementaryHeader::STATUS_FINAL_MERGED) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'SUPPLEMENTARY_NOT_APPROVED', 'message' => 'PDO Tambahan harus sudah disetujui Direktur (final_merged) sebelum dapat di-merge.'],
            ], 409));
        }

        // Cegah double-merge
        if ($supp->merged_at !== null) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'ALREADY_MERGED', 'message' => 'PDO Tambahan ini sudah pernah di-merge sebelumnya.'],
            ], 409));
        }

        return DB::transaction(function () use ($supp, $actor) {
            $parentPdo    = $supp->parentPdo;
            $nextOrder    = ($parentPdo->details()->max('display_order') ?? 0) + 1;
            $detailsAdded = 0;

            $now = now();

            // BR-MERGE-003: salin setiap detail ke pdo_details parent
            foreach ($supp->details()->orderBy('display_order')->get() as $suppDetail) {
                $detail = PdoDetail::create([
                    'pdo_header_id'              => $parentPdo->id,
                    'expense_item_id'            => $suppDetail->expense_item_id,
                    'source_pdo_supplementary_id'=> $supp->id, // traceability
                    'funding_option'             => $supp->funding_option, // kas_kebun → tidak boleh ditransfer lagi
                    'account_number'             => $suppDetail->account_number,
                    'description'                => $suppDetail->description,
                    'quantity'                   => $suppDetail->quantity,
                    'unit'                       => $suppDetail->unit,
                    'rate'                       => $suppDetail->rate,
                    'amount'                     => $suppDetail->amount,
                    'notes'                      => $suppDetail->notes,
                    'display_order'              => $nextOrder++,
                ]);
                $detailsAdded++;

                // Item kas_kebun: dana sudah "ada" di kas kebun (tidak lewat transfer
                // HO), jadi buat entri transfer committed otomatis ke rek_kebun supaya
                // muncul di Buku Kas Kebun dan KERANI bisa mencatat realisasinya.
                if ($supp->usesKasKebun()) {
                    $entry = TransferEntry::create([
                        'pdo_detail_id'        => $detail->id,
                        'recorded_by'          => $actor->id,
                        'entry_source'         => TransferEntry::SOURCE_SYSTEM,
                        'is_auto_generated'    => true,
                        'status'               => TransferEntry::STATUS_COMMITTED,
                        'committed_at'         => $now,
                        'committed_by'         => $actor->id,
                        'transfer_date'        => $now->toDateString(),
                        'amount'               => $detail->amount,
                        'reference_number'     => null,
                        'notes'                => "Dibuat otomatis — dana diambil dari Kas Kebun (PDOT {$supp->pdo_number})",
                        'transfer_destination' => TransferEntry::DEST_REK_KEBUN,
                    ]);

                    AuditLog::record(
                        actor: $actor,
                        entityType: 'transfer_entries',
                        entityId: $entry->id,
                        action: 'INSERT',
                        oldValues: null,
                        newValues: $entry->toArray()
                    );
                }
            }

            // BR-MERGE-004: tandai sudah merged
            $supp->update(['merged_at' => now()]);

            // Detail baru di-insert langsung (bypass PdoService), jadi grand_total_amount
            // yang tersimpan di parent harus di-resync manual.
            $this->pdoService->syncGrandTotal($parentPdo);

            AuditLog::record(
                actor: $actor,
                entityType: 'pdo_supplementary_headers',
                entityId: $supp->id,
                action: 'STATUS_CHANGE',
                oldValues: ['merged_at' => null],
                newValues: ['merged_at' => now()->toDateTimeString(), 'details_merged' => $detailsAdded]
            );

            return $supp->fresh()->load(['parentPdo', 'details.expenseItem']);
        });
    }
}
