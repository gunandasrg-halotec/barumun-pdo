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
     * Approval chain (BRD BR-APPR-001, BR-APPR-002):
     *  draft             → submitted            KERANI submit
     *  submitted         → reviewed_asisten     ASISTEN_KEBUN approve
     *  reviewed_asisten  → in_review_manager    MANAJER_KEBUN atau MANAJER_KEUANGAN approve (paralel)
     *  in_review_manager → in_review_direktur   setelah KEDUA manajer approve
     *  in_review_direktur→ final                DIREKTUR_KEUANGAN approve → auto-generate transfer
     *
     * Tahap Asisten dan Direktur: sequential (satu approver).
     * Tahap Manajer: paralel — kedua manajer harus approve sebelum lanjut ke Direktur.
     */

    // Approver tunggal: status saat ini → [role_wajib, status_berikutnya]
    private const SINGLE_APPROVER_MAP = [
        PdoHeader::STATUS_SUBMITTED          => [Role::ASISTEN_KEBUN,    PdoHeader::STATUS_REVIEWED_ASISTEN],
        PdoHeader::STATUS_IN_REVIEW_DIREKTUR => [Role::DIREKTUR_KEUANGAN, PdoHeader::STATUS_FINAL],
    ];

    // Tahap paralel: status yang membutuhkan 2 manajer approve bersama
    private const PARALLEL_STATUSES = [
        PdoHeader::STATUS_REVIEWED_ASISTEN,
        PdoHeader::STATUS_IN_REVIEW_MANAGER,
    ];

    public function __construct(private readonly WhatsAppNotificationService $wa = new WhatsAppNotificationService()) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /** BR-APPROVAL-001: submit PDO (draft → submitted) */
    public function submit(PdoHeader $pdo, string $submissionDate, User $actor): PdoHeader
    {
        if (! $actor->hasRole(Role::KERANI)) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya KERANI yang bisa submit PDO.']], 403));
        }

        if (! $pdo->isDraft()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO harus berstatus draft untuk di-submit.']], 409));
        }

        // Minimal satu baris dengan amount > 0
        if ($pdo->details()->where('amount', '>', 0)->doesntExist()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'PDO_EMPTY', 'message' => 'PDO tidak bisa disubmit karena tidak ada item dengan jumlah > 0.']], 422));
        }

        // [D] Semua baris wajib memiliki deskripsi tidak kosong
        $missingDesc = $pdo->details()
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
            ->pluck('display_order');
        if ($missingDesc->isNotEmpty()) {
            abort(response()->json(['success' => false, 'error' => [
                'code'    => 'VALIDATION_ERROR',
                'message' => 'Semua baris wajib memiliki deskripsi. Baris tanpa deskripsi: ' . $missingDesc->implode(', ') . '.',
            ]], 422));
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

    /**
     * BR-APPR-001 / BR-APPR-002: approve sesuai role.
     * Tahap Asisten & Direktur: sequential.
     * Tahap Manajer: paralel — track per-role, lanjut ke Direktur setelah keduanya approve.
     * [B] lockForUpdate cegah race condition concurrent request.
     * [C] Self-approval check (BR-APPR-003).
     */
    public function approve(PdoHeader $pdo, ?string $reason, User $actor): PdoHeader
    {
        return DB::transaction(function () use ($pdo, $actor, $reason) {
            // [B] Lock row agar concurrent request tidak bisa approve bersamaan
            $pdo = PdoHeader::lockForUpdate()->findOrFail($pdo->id);

            // [C] BR-APPR-003: larangan self-approval
            if ($pdo->created_by === $actor->id) {
                abort(response()->json(['success' => false, 'error' => [
                    'code'    => 'SELF_APPROVAL_NOT_ALLOWED',
                    'message' => 'Anda tidak dapat menyetujui PDO yang Anda buat sendiri.',
                ]], 403));
            }

            // Tahap paralel (Manajer)
            if (in_array($pdo->status, self::PARALLEL_STATUSES)) {
                return $this->approveManagerParallel($pdo, $actor, $reason);
            }

            // Tahap sequential (Asisten, Direktur)
            return $this->approveSingleStage($pdo, $actor, $reason);
        });
    }

    /**
     * BR-APPR-004: reject di tahap manapun → kembali ke draft.
     * Tahap paralel: salah satu reject → hangus semua, reset ke draft.
     * [B] lockForUpdate juga diterapkan di reject.
     * [C] Self-approval check juga berlaku untuk reject.
     */
    public function reject(PdoHeader $pdo, string $reason, User $actor): PdoHeader
    {
        return DB::transaction(function () use ($pdo, $actor, $reason) {
            // [B] Lock row
            $pdo = PdoHeader::lockForUpdate()->findOrFail($pdo->id);

            // [C] Self-reject check — BRD tidak melarang ini secara eksplisit, tapi
            // self-approve dilarang (BR-APPR-003). Self-reject tetap diizinkan karena
            // reject adalah tindakan penolakan, bukan persetujuan.

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

            // Validasi: manajer hanya bisa reject di tahap manajer, asisten di tahap asisten, dst.
            $this->assertRejectAuthorized($pdo, $actor);

            $currentStatus = $pdo->status;

            // Reset ke draft + reset tracking paralel (BR-APPR-002: approval hangus jika reject)
            $pdo->update([
                'status'                   => PdoHeader::STATUS_DRAFT,
                'manager_kebun_approved'   => null,
                'manager_keuangan_approved'=> null,
            ]);

            $this->appendLog($pdo, $actor, $currentStatus, PdoApprovalLog::ACTION_REJECT, $reason);

            $fresh = $pdo->fresh()->load(['creator', 'plantationUnit']);
            match (true) {
                $actor->hasRole(Role::ASISTEN_KEBUN)                                      => $this->wa->notifyRejectedByAsisten($fresh, $reason),
                $actor->hasAnyRole([Role::MANAJER_KEBUN, Role::MANAJER_KEUANGAN])         => $this->wa->notifyRejectedByManager($fresh, $reason),
                $actor->hasRole(Role::DIREKTUR_KEUANGAN)                                  => $this->wa->notifyRejectedByDirektur($fresh, $reason),
                default                                                                    => null,
            };

            return $pdo->fresh();
        });
    }

    public function history(PdoHeader $pdo)
    {
        return $pdo->approvalLogs()->with('actor')->orderBy('sequence_number')->get();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Approval sequential (Asisten, Direktur): satu approver → satu transisi.
     */
    private function approveSingleStage(PdoHeader $pdo, User $actor, ?string $reason): PdoHeader
    {
        if (! isset(self::SINGLE_APPROVER_MAP[$pdo->status])) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'INVALID_STATUS', 'message' => 'PDO tidak bisa di-approve pada status ini.']], 409));
        }

        [$requiredRole, $nextStatus] = self::SINGLE_APPROVER_MAP[$pdo->status];

        if (! $actor->hasRole($requiredRole)) {
            abort(response()->json(['success' => false, 'error' => [
                'code'    => 'FORBIDDEN',
                'message' => "Approval tahap ini membutuhkan role {$requiredRole}.",
            ]], 403));
        }

        $stage = $pdo->status;
        $pdo->update(['status' => $nextStatus]);
        $this->appendLog($pdo, $actor, $stage, PdoApprovalLog::ACTION_APPROVE, $reason);

        $fresh = $pdo->fresh()->load(['creator', 'plantationUnit']);
        if ($nextStatus === PdoHeader::STATUS_FINAL) {
            $this->autoGenerateTransferEntries($pdo);
            $this->wa->notifyFinal($fresh);
        } elseif ($nextStatus === PdoHeader::STATUS_REVIEWED_ASISTEN) {
            $this->wa->notifyApprovedByAsisten($fresh);
        }

        return $pdo->fresh();
    }

    /**
     * BR-APPR-002: Approval paralel Manajer Kebun + Manajer Keuangan.
     *
     * Status in_review_manager dipakai sebagai "sedang dalam review paralel".
     * Saat pertama salah satu manajer approve:
     *   - status masuk in_review_manager (jika belum)
     *   - kolom manager_X_approved = true
     *   - log dicatat
     * Saat manajer kedua approve:
     *   - kolom manager_Y_approved = true
     *   - status naik ke in_review_direktur
     *   - kirim notifikasi ke Direktur
     */
    private function approveManagerParallel(PdoHeader $pdo, User $actor, ?string $reason): PdoHeader
    {
        $isManagerKebun    = $actor->hasRole(Role::MANAJER_KEBUN);
        $isManagerKeuangan = $actor->hasRole(Role::MANAJER_KEUANGAN);

        if (! $isManagerKebun && ! $isManagerKeuangan) {
            abort(response()->json(['success' => false, 'error' => [
                'code'    => 'FORBIDDEN',
                'message' => 'Approval tahap ini membutuhkan role Manajer Kebun atau Manajer Keuangan.',
            ]], 403));
        }

        // Cek apakah manajer ini sudah approve sebelumnya
        $alreadyApproved = $isManagerKebun
            ? $pdo->manager_kebun_approved === true
            : $pdo->manager_keuangan_approved === true;

        if ($alreadyApproved) {
            abort(response()->json(['success' => false, 'error' => [
                'code'    => 'ALREADY_APPROVED',
                'message' => 'Anda sudah memberikan persetujuan pada PDO ini.',
            ]], 409));
        }

        // Tandai approval manajer ini
        $field   = $isManagerKebun ? 'manager_kebun_approved' : 'manager_keuangan_approved';
        $updates = ['status' => PdoHeader::STATUS_IN_REVIEW_MANAGER, $field => true];

        $pdo->update($updates);
        $this->appendLog($pdo, $actor, PdoHeader::STATUS_IN_REVIEW_MANAGER, PdoApprovalLog::ACTION_APPROVE, $reason);

        // Refresh untuk baca nilai terkini setelah update
        $pdo = $pdo->fresh();

        // Cek apakah keduanya sudah approve
        if ($pdo->manager_kebun_approved === true && $pdo->manager_keuangan_approved === true) {
            $pdo->update(['status' => PdoHeader::STATUS_IN_REVIEW_DIREKTUR]);
            $this->wa->notifyApprovedByManager($pdo->fresh()->load(['creator', 'plantationUnit']));
        }

        return $pdo->fresh();
    }

    /**
     * Pastikan approver punya kewenangan reject di status PDO saat ini.
     * Mencegah Asisten reject PDO yang sedang di tahap Manajer, dsb.
     */
    private function assertRejectAuthorized(PdoHeader $pdo, User $actor): void
    {
        $authorized = match ($pdo->status) {
            PdoHeader::STATUS_SUBMITTED          => $actor->hasRole(Role::ASISTEN_KEBUN),
            PdoHeader::STATUS_REVIEWED_ASISTEN,
            PdoHeader::STATUS_IN_REVIEW_MANAGER  => $actor->hasAnyRole([Role::MANAJER_KEBUN, Role::MANAJER_KEUANGAN]),
            PdoHeader::STATUS_IN_REVIEW_DIREKTUR => $actor->hasRole(Role::DIREKTUR_KEUANGAN),
            default                              => false,
        };

        if (! $authorized) {
            abort(response()->json(['success' => false, 'error' => [
                'code'    => 'APPROVAL_SEQUENCE_VIOLATION',
                'message' => 'Anda tidak berwenang menolak PDO pada tahap ini.',
            ]], 403));
        }
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
                'recorded_by'      => null,
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
