<?php

namespace App\Services\PdoSupplementary;

use App\Models\AuditLog;
use App\Models\PdoDetail;
use App\Models\PdoSupplementaryApprovalLog;
use App\Models\PdoSupplementaryHeader;
use App\Models\Role;
use App\Models\User;
use App\Services\Notification\WhatsAppNotificationService;
use Illuminate\Support\Facades\DB;

class PdoSupplementaryApprovalService
{
    /**
     * Chain identik dengan PDO Bulanan — status final berbeda: final_merged (bukan final).
     */
    private const TRANSITION_MAP = [
        PdoSupplementaryHeader::STATUS_SUBMITTED          => [Role::ASISTEN_KEBUN,     PdoSupplementaryHeader::STATUS_REVIEWED_ASISTEN],
        PdoSupplementaryHeader::STATUS_REVIEWED_ASISTEN   => [Role::MANAJER_KEBUN,     PdoSupplementaryHeader::STATUS_IN_REVIEW_MANAGER],
        PdoSupplementaryHeader::STATUS_IN_REVIEW_MANAGER  => [Role::MANAJER_KEUANGAN,  PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR],
        PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR => [Role::DIREKTUR_KEUANGAN, PdoSupplementaryHeader::STATUS_FINAL_MERGED],
    ];

    public function __construct(
        private readonly WhatsAppNotificationService $wa = new WhatsAppNotificationService()
    ) {}

    /** Submit PDO Tambahan: draft/rejected → submitted */
    public function submit(PdoSupplementaryHeader $supp, string $submissionDate, User $actor): PdoSupplementaryHeader
    {
        if (! $actor->hasRole(Role::KERANI)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya KERANI yang bisa submit PDO Tambahan.']], 403));
        }

        $allowedStatuses = [PdoSupplementaryHeader::STATUS_DRAFT, PdoSupplementaryHeader::STATUS_REJECTED];
        if (! in_array($supp->status, $allowedStatuses)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO Tambahan harus berstatus draft atau rejected untuk di-submit.']], 409));
        }

        if ($supp->details()->where('amount', '>', 0)->doesntExist()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'SUPPLEMENTARY_EMPTY', 'message' => 'PDO Tambahan harus memiliki minimal satu item dengan jumlah > 0.']], 422));
        }

        return DB::transaction(function () use ($supp, $submissionDate, $actor) {
            $action = $supp->isRejected()
                ? PdoSupplementaryApprovalLog::ACTION_RESUBMIT
                : PdoSupplementaryApprovalLog::ACTION_SUBMIT;

            $supp->update([
                'status'          => PdoSupplementaryHeader::STATUS_SUBMITTED,
                'submission_date' => $submissionDate,
            ]);

            $this->appendLog($supp, $actor, 'kerani_submit', $action);

            $fresh = $supp->fresh()->load(['creator', 'plantationUnit']);
            $this->wa->notifySupplementarySubmitted($fresh);

            return $fresh;
        });
    }

    /** Approve berdasarkan role approver — chain sama seperti PDO Bulanan */
    public function approve(PdoSupplementaryHeader $supp, ?string $reason, User $actor): PdoSupplementaryHeader
    {
        [$requiredRole, $nextStatus] = $this->resolveTransition($supp, $actor);

        return DB::transaction(function () use ($supp, $actor, $reason, $nextStatus) {
            $stage = $supp->status;
            $supp->update(['status' => $nextStatus]);

            $this->appendLog($supp, $actor, $stage, PdoSupplementaryApprovalLog::ACTION_APPROVE, $reason);

            $fresh = $supp->fresh()->load(['creator', 'plantationUnit']);

            if ($nextStatus === PdoSupplementaryHeader::STATUS_FINAL_MERGED) {
                $this->mergeIntoParent($supp, $actor);
                $fresh = $supp->fresh()->load(['creator', 'plantationUnit']);
            }

            match ($nextStatus) {
                PdoSupplementaryHeader::STATUS_REVIEWED_ASISTEN   => $this->wa->notifySupplementaryApprovedByAsisten($fresh),
                PdoSupplementaryHeader::STATUS_IN_REVIEW_MANAGER  => $this->wa->notifySupplementaryApprovedByManagerKebun($fresh),
                PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR => $this->wa->notifySupplementaryApprovedByManagerKeuangan($fresh),
                PdoSupplementaryHeader::STATUS_FINAL_MERGED       => $this->wa->notifySupplementaryFinal($fresh),
                default                                            => null,
            };

            return $fresh;
        });
    }

    /** Reject di tahap manapun → kembali ke status draft (sama seperti PDO Bulanan), agar KERANI bisa edit dan resubmit */
    public function reject(PdoSupplementaryHeader $supp, string $reason, User $actor): PdoSupplementaryHeader
    {
        if (! $actor->canApprove()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menolak PDO Tambahan ini.']], 403));
        }

        $inReview = [
            PdoSupplementaryHeader::STATUS_SUBMITTED,
            PdoSupplementaryHeader::STATUS_REVIEWED_ASISTEN,
            PdoSupplementaryHeader::STATUS_IN_REVIEW_MANAGER,
            PdoSupplementaryHeader::STATUS_IN_REVIEW_DIREKTUR,
        ];

        if (! in_array($supp->status, $inReview)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO Tambahan tidak bisa ditolak pada status ini.']], 409));
        }

        return DB::transaction(function () use ($supp, $actor, $reason) {
            $stage = $supp->status;
            $supp->update(['status' => PdoSupplementaryHeader::STATUS_DRAFT, 'submission_date' => null]);

            $this->appendLog($supp, $actor, $stage, PdoSupplementaryApprovalLog::ACTION_REJECT, $reason);

            $fresh = $supp->fresh()->load(['creator', 'plantationUnit']);

            match (true) {
                $actor->hasRole(Role::ASISTEN_KEBUN)                                    => $this->wa->notifySupplementaryRejectedByAsisten($fresh, $reason),
                $actor->hasAnyRole([Role::MANAJER_KEBUN, Role::MANAJER_KEUANGAN])       => $this->wa->notifySupplementaryRejectedByManager($fresh, $reason),
                $actor->hasRole(Role::DIREKTUR_KEUANGAN)                                => $this->wa->notifySupplementaryRejectedByDirektur($fresh, $reason),
                default                                                                  => null,
            };

            return $fresh;
        });
    }

    public function history(PdoSupplementaryHeader $supp)
    {
        return $supp->approvalLogs()->with('actor')->orderBy('sequence_number')->get();
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────

    private function resolveTransition(PdoSupplementaryHeader $supp, User $actor): array
    {
        if (! isset(self::TRANSITION_MAP[$supp->status])) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO Tambahan tidak bisa di-approve pada status ini.']], 409));
        }

        [$requiredRole, $nextStatus] = self::TRANSITION_MAP[$supp->status];

        if (! $actor->hasRole($requiredRole)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => "Approval tahap ini membutuhkan role {$requiredRole}."]], 403));
        }

        return [$requiredRole, $nextStatus];
    }

    private function appendLog(PdoSupplementaryHeader $supp, User $actor, string $stage, string $action, ?string $reason = null): void
    {
        PdoSupplementaryApprovalLog::create([
            'pdo_supplementary_header_id' => $supp->id,
            'actor_user_id'               => $actor->id,
            'approval_stage'              => $stage,
            'action'                      => $action,
            'reason'                      => $reason,
            'sequence_number'             => $supp->nextApprovalSequence(),
        ]);
    }

    /**
     * Auto-merge PDO Tambahan items into the parent PDO Bulanan.
     * Called within the same DB transaction as Direktur's approval.
     */
    private function mergeIntoParent(PdoSupplementaryHeader $supp, User $actor): void
    {
        $parentPdo = $supp->parentPdo;
        $nextOrder = ($parentPdo->details()->max('display_order') ?? 0) + 1;
        $detailsAdded = 0;

        foreach ($supp->details()->orderBy('display_order')->get() as $suppDetail) {
            PdoDetail::create([
                'pdo_header_id'               => $parentPdo->id,
                'expense_item_id'             => $suppDetail->expense_item_id,
                'source_pdo_supplementary_id' => $supp->id,
                'account_number'              => $suppDetail->account_number,
                'description'                 => $suppDetail->description,
                'quantity'                    => $suppDetail->quantity,
                'unit'                        => $suppDetail->unit,
                'rate'                        => $suppDetail->rate,
                'amount'                      => $suppDetail->amount,
                'notes'                       => $suppDetail->notes,
                'display_order'               => $nextOrder++,
            ]);
            $detailsAdded++;
        }

        $supp->update(['merged_at' => now()]);

        AuditLog::record(
            actor: $actor,
            entityType: 'pdo_supplementary_headers',
            entityId: $supp->id,
            action: 'STATUS_CHANGE',
            oldValues: ['merged_at' => null],
            newValues: ['merged_at' => now()->toDateTimeString(), 'details_merged' => $detailsAdded]
        );
    }
}
