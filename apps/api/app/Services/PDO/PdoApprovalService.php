<?php

namespace App\Services\PDO;

use App\Models\PdoApprovalLog;
use App\Models\PdoHeader;
use App\Models\Role;
use App\Models\TransferEntry;
use App\Models\User;
use App\Services\Notification\WhatsAppNotificationService;
use Illuminate\Support\Facades\DB;

class PdoApprovalService
{
    /**
     * Approval chain:
     *  draft → submitted           (KERANI submit)
     *  submitted → reviewed_asisten (ASISTEN_KEBUN approve)
     *  reviewed_asisten → in_review_manager (MANAJER_KEBUN approve)
     *  in_review_manager → in_review_direktur (MANAJER_KEUANGAN approve)
     *  in_review_direktur → final  (DIREKTUR_KEUANGAN approve → auto-generate transfer)
     */
    private const TRANSITION_MAP = [
        PdoHeader::STATUS_SUBMITTED          => [Role::ASISTEN_KEBUN,       PdoHeader::STATUS_REVIEWED_ASISTEN],
        PdoHeader::STATUS_REVIEWED_ASISTEN   => [Role::MANAJER_KEBUN,       PdoHeader::STATUS_IN_REVIEW_MANAGER],
        PdoHeader::STATUS_IN_REVIEW_MANAGER  => [Role::MANAJER_KEUANGAN,    PdoHeader::STATUS_IN_REVIEW_DIREKTUR],
        PdoHeader::STATUS_IN_REVIEW_DIREKTUR => [Role::DIREKTUR_KEUANGAN,   PdoHeader::STATUS_FINAL],
    ];

    public function __construct(private readonly WhatsAppNotificationService $wa = new WhatsAppNotificationService()) {}

    /** BR-APPROVAL-001: submit PDO (draft → submitted) */
    public function submit(PdoHeader $pdo, string $submissionDate, User $actor): PdoHeader
    {
        if (! $actor->hasRole(Role::KERANI)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya KERANI yang bisa submit PDO.']], 403));
        }

        if (! $pdo->isDraft()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO harus berstatus draft untuk di-submit.']], 409));
        }

        if ($pdo->details()->where('amount', '>', 0)->doesntExist()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'PDO_EMPTY', 'message' => 'PDO tidak bisa disubmit karena tidak ada item dengan jumlah > 0.']], 422));
        }

        return DB::transaction(function () use ($pdo, $submissionDate, $actor) {
            $pdo->update([
                'status'          => PdoHeader::STATUS_SUBMITTED,
                'submission_date' => $submissionDate,
            ]);

            $this->appendLog($pdo, $actor, 'kerani_submit', PdoApprovalLog::ACTION_SUBMIT);

            // BR-NOTIF-001: notifikasi WhatsApp ke Asisten Kebun
            $this->wa->notifySubmitted($pdo->fresh()->load(['creator', 'plantationUnit']));

            return $pdo->fresh();
        });
    }

    /** BR-APPROVAL-002: approve berdasarkan role approver */
    public function approve(PdoHeader $pdo, ?string $reason, User $actor): PdoHeader
    {
        [$requiredRole, $nextStatus] = $this->resolveTransition($pdo, $actor);

        return DB::transaction(function () use ($pdo, $actor, $reason, $nextStatus) {
            $stage = $pdo->status;
            $pdo->update(['status' => $nextStatus]);

            $this->appendLog($pdo, $actor, $stage, PdoApprovalLog::ACTION_APPROVE, $reason);

            // BR-APPROVAL-003: PDO Final → auto-generate transfer untuk semua detail
            if ($nextStatus === PdoHeader::STATUS_FINAL) {
                $this->autoGenerateTransferEntries($pdo);
                $this->wa->notifyFinal($pdo->fresh()->load('creator'));
            } elseif ($nextStatus === PdoHeader::STATUS_REVIEWED_ASISTEN) {
                $this->wa->notifyApprovedByAsisten($pdo->fresh());
            } elseif ($nextStatus === PdoHeader::STATUS_IN_REVIEW_DIREKTUR) {
                // BR-NOTIF-002: notifikasi setelah Manajer Kebun approve
                $this->wa->notifyApprovedByManager($pdo->fresh());
            }

            return $pdo->fresh();
        });
    }

    /** BR-APPROVAL-004: reject di tahap manapun → kembali ke draft */
    public function reject(PdoHeader $pdo, string $reason, User $actor): PdoHeader
    {
        $currentStatus = $pdo->status;

        // Cek role: harus merupakan salah satu approver yang relevan
        if (! $actor->canApprove()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak memiliki akses untuk menolak PDO ini.']], 403));
        }

        $inReviewStatuses = [
            PdoHeader::STATUS_SUBMITTED,
            PdoHeader::STATUS_REVIEWED_ASISTEN,
            PdoHeader::STATUS_IN_REVIEW_MANAGER,
            PdoHeader::STATUS_IN_REVIEW_DIREKTUR,
        ];

        if (! in_array($pdo->status, $inReviewStatuses)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO tidak bisa ditolak pada status ini.']], 409));
        }

        return DB::transaction(function () use ($pdo, $actor, $reason, $currentStatus) {
            $pdo->update(['status' => PdoHeader::STATUS_DRAFT]);

            $this->appendLog($pdo, $actor, $currentStatus, PdoApprovalLog::ACTION_REJECT, $reason);

            // BR-NOTIF-003: notifikasi penolakan ke KERANI
            $this->wa->notifyRejected($pdo->fresh()->load('creator'), $reason);

            return $pdo->fresh();
        });
    }

    public function history(PdoHeader $pdo)
    {
        return $pdo->approvalLogs()->with('actor')->orderBy('sequence_number')->get();
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────

    private function resolveTransition(PdoHeader $pdo, User $actor): array
    {
        if (! isset(self::TRANSITION_MAP[$pdo->status])) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO tidak bisa di-approve pada status ini.']], 409));
        }

        [$requiredRole, $nextStatus] = self::TRANSITION_MAP[$pdo->status];

        if (! $actor->hasRole($requiredRole)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => "Approval tahap ini membutuhkan role {$requiredRole}."]], 403));
        }

        return [$requiredRole, $nextStatus];
    }

    private function appendLog(PdoHeader $pdo, User $actor, string $stage, string $action, ?string $reason = null): void
    {
        PdoApprovalLog::create([
            'pdo_header_id'  => $pdo->id,
            'actor_user_id'  => $actor->id,
            'approval_stage' => $stage,
            'action'         => $action,
            'reason'         => $reason,
            'sequence_number'=> $pdo->nextApprovalSequence(),
        ]);
    }

    /**
     * BR-APPROVAL-003: Saat PDO status menjadi FINAL, generate transfer_entries
     * otomatis (entry_source='system') untuk setiap detail dengan amount > 0.
     */
    private function autoGenerateTransferEntries(PdoHeader $pdo): void
    {
        $pdo->details()->where('amount', '>', 0)->each(function ($detail) use ($pdo) {
            TransferEntry::create([
                'pdo_detail_id'    => $detail->id,
                'recorded_by'      => null, // NULL = entri sistem
                'entry_source'     => TransferEntry::SOURCE_SYSTEM,
                'is_auto_generated'=> true,
                'transfer_date'    => now()->toDateString(),
                'amount'           => $detail->amount,
                'reference_number' => "AUTO-{$pdo->pdo_number}",
                'notes'            => 'Transfer otomatis saat PDO Final.',
            ]);
        });
    }
}
