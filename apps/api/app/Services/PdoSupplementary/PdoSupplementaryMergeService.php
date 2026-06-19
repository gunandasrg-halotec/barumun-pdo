<?php

namespace App\Services\PdoSupplementary;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoSupplementaryHeader;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PdoSupplementaryMergeService
{
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

            // BR-MERGE-003: salin setiap detail ke pdo_details parent
            foreach ($supp->details()->orderBy('display_order')->get() as $suppDetail) {
                PdoDetail::create([
                    'pdo_header_id'              => $parentPdo->id,
                    'expense_item_id'            => $suppDetail->expense_item_id,
                    'source_pdo_supplementary_id'=> $supp->id, // traceability
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
            }

            // BR-MERGE-004: tandai sudah merged
            $supp->update(['merged_at' => now()]);

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
