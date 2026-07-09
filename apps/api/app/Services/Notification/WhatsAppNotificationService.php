<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    // ─────────────────────────────────────────────────────
    // PUBLIC NOTIFICATION METHODS
    // ─────────────────────────────────────────────────────

    /** Kerani submit → Asisten Kebun (unit kebun sama) */
    public function notifySubmitted(PdoHeader $pdo): void
    {
        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_SUBMITTED,
            $this->asistenByUnit($pdo),
            $this->baseVars($pdo)
        );
    }

    /** Asisten reject → Kerani (creator) */
    public function notifyRejectedByAsisten(PdoHeader $pdo, string $reason): void
    {
        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_ASISTEN,
            $this->creator($pdo),
            array_merge($this->baseVars($pdo), ['alasan_reject' => $reason, 'penolak' => 'Asisten Kebun'])
        );
    }

    /** Asisten approve → Kerani (creator) + Manajer Kebun + Manajer Keuangan */
    public function notifyApprovedByAsisten(PdoHeader $pdo): void
    {
        $recipients = $this->creator($pdo)
            ->merge($this->byRole($pdo, Role::MANAJER_KEBUN))
            ->merge($this->byRole($pdo, Role::MANAJER_KEUANGAN));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_ASISTEN,
            $recipients,
            $this->baseVars($pdo)
        );
    }

    /** Manajer reject → Asisten Kebun (unit kebun sama) + Kerani (creator) */
    public function notifyRejectedByManager(PdoHeader $pdo, string $reason): void
    {
        $recipients = $this->asistenByUnit($pdo)->merge($this->creator($pdo));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_MANAGER,
            $recipients,
            array_merge($this->baseVars($pdo), ['alasan_reject' => $reason, 'penolak' => 'Manajer'])
        );
    }

    /**
     * Kedua Manajer approve (status → in_review_direktur)
     * → Kerani (creator) + Asisten Kebun (unit kebun sama) + Direktur Keuangan
     */
    public function notifyApprovedByManager(PdoHeader $pdo): void
    {
        $recipients = $this->creator($pdo)
            ->merge($this->asistenByUnit($pdo))
            ->merge($this->byRole($pdo, Role::DIREKTUR_KEUANGAN));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_MANAGER,
            $recipients,
            $this->baseVars($pdo)
        );
    }

    /** Direktur reject → Manajer Keuangan + Manajer Kebun + Asisten Kebun (unit sama) + Kerani (creator) */
    public function notifyRejectedByDirektur(PdoHeader $pdo, string $reason): void
    {
        $recipients = $this->byRole($pdo, Role::MANAJER_KEUANGAN)
            ->merge($this->byRole($pdo, Role::MANAJER_KEBUN))
            ->merge($this->asistenByUnit($pdo))
            ->merge($this->creator($pdo));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_DIREKTUR,
            $recipients,
            array_merge($this->baseVars($pdo), ['alasan_reject' => $reason, 'penolak' => 'Direktur Keuangan'])
        );
    }

    /** Direktur approve (→ Final) → Manajer Keuangan + Manajer Kebun + Asisten Kebun (unit sama) + Kerani (creator) */
    public function notifyFinal(PdoHeader $pdo): void
    {
        $recipients = $this->byRole($pdo, Role::MANAJER_KEUANGAN)
            ->merge($this->byRole($pdo, Role::MANAJER_KEBUN))
            ->merge($this->asistenByUnit($pdo))
            ->merge($this->creator($pdo));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_FINAL,
            $recipients,
            $this->baseVars($pdo)
        );
    }

    // ─────────────────────────────────────────────────────
    // PDO TAMBAHAN NOTIFICATIONS
    // ─────────────────────────────────────────────────────

    /** Kerani submit PDO Tambahan → Asisten Kebun (unit sama) */
    public function notifySupplementarySubmitted(PdoSupplementaryHeader $supp): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_SUBMITTED,
            $this->suppAsistenByUnit($supp),
            $this->suppBaseVars($supp)
        );
    }

    /** Asisten approve PDO Tambahan → Kerani + Manajer Kebun + Manajer Keuangan */
    public function notifySupplementaryApprovedByAsisten(PdoSupplementaryHeader $supp): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppCreator($supp)
            ->merge($this->suppByRole($supp, Role::MANAJER_KEBUN))
            ->merge($this->suppByRole($supp, Role::MANAJER_KEUANGAN));

        $this->send($supp->company_id, NotificationTemplate::EVENT_PDO_APPROVED_ASISTEN, $recipients, $this->suppBaseVars($supp));
    }

    /** Manajer Kebun approve PDO Tambahan → Manajer Keuangan + Asisten + Kerani */
    public function notifySupplementaryApprovedByManagerKebun(PdoSupplementaryHeader $supp): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::MANAJER_KEUANGAN)
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send($supp->company_id, NotificationTemplate::EVENT_PDO_APPROVED_MANAGER, $recipients, $this->suppBaseVars($supp));
    }

    /** Manajer Keuangan approve PDO Tambahan → Direktur + Asisten + Kerani */
    public function notifySupplementaryApprovedByManagerKeuangan(PdoSupplementaryHeader $supp): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::DIREKTUR_KEUANGAN)
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send($supp->company_id, NotificationTemplate::EVENT_PDO_APPROVED_MANAGER, $recipients, $this->suppBaseVars($supp));
    }

    /** Direktur approve PDO Tambahan (final_merged) → Manajer Kebun + Manajer Keuangan + Asisten + Kerani */
    public function notifySupplementaryFinal(PdoSupplementaryHeader $supp): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::MANAJER_KEBUN)
            ->merge($this->suppByRole($supp, Role::MANAJER_KEUANGAN))
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send($supp->company_id, NotificationTemplate::EVENT_PDO_FINAL, $recipients, $this->suppBaseVars($supp));
    }

    /** Asisten reject PDO Tambahan → Kerani (creator) */
    public function notifySupplementaryRejectedByAsisten(PdoSupplementaryHeader $supp, string $reason): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_ASISTEN,
            $this->suppCreator($supp),
            array_merge($this->suppBaseVars($supp), ['alasan_reject' => $reason, 'penolak' => 'Asisten Kebun'])
        );
    }

    /** Manajer (Kebun/Keuangan) reject PDO Tambahan → Asisten + Kerani */
    public function notifySupplementaryRejectedByManager(PdoSupplementaryHeader $supp, string $reason): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppAsistenByUnit($supp)->merge($this->suppCreator($supp));

        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_MANAGER,
            $recipients,
            array_merge($this->suppBaseVars($supp), ['alasan_reject' => $reason, 'penolak' => 'Manajer'])
        );
    }

    /** Direktur reject PDO Tambahan → Manajer Kebun + Manajer Keuangan + Asisten + Kerani */
    public function notifySupplementaryRejectedByDirektur(PdoSupplementaryHeader $supp, string $reason): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::MANAJER_KEUANGAN)
            ->merge($this->suppByRole($supp, Role::MANAJER_KEBUN))
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_REJECTED_DIREKTUR,
            $recipients,
            array_merge($this->suppBaseVars($supp), ['alasan_reject' => $reason, 'penolak' => 'Direktur Keuangan'])
        );
    }

    // ─────────────────────────────────────────────────────
    // PDO TAMBAHAN RECIPIENT HELPERS
    // ─────────────────────────────────────────────────────

    private function suppCreator(PdoSupplementaryHeader $supp): Collection
    {
        $user = $supp->creator;
        return $user ? collect([$user]) : collect();
    }

    private function suppAsistenByUnit(PdoSupplementaryHeader $supp): Collection
    {
        return User::with('role')
            ->where('company_id', $supp->company_id)
            ->where('plantation_unit_id', $supp->plantation_unit_id)
            ->whereHas('role', fn ($q) => $q->where('code', Role::ASISTEN_KEBUN))
            ->where('is_active', true)
            ->get();
    }

    private function suppByRole(PdoSupplementaryHeader $supp, string $roleCode): Collection
    {
        return User::with('role')
            ->where('company_id', $supp->company_id)
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();
    }

    private function suppBaseVars(PdoSupplementaryHeader $supp): array
    {
        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                   'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return [
            'nomor_pdo'  => $supp->pdo_number,
            'periode'    => $months[$supp->period_month] . ' ' . $supp->period_year,
            'unit_kebun' => $supp->plantationUnit?->name ?? '',
        ];
    }

    /** Reminder bulanan: KERANI yang belum buat PDO bulan ini. */
    public function sendMonthlyReminders(string $companyId, int $month, int $year): void
    {
        $unitsWithPdo = \App\Models\PdoHeader::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->pluck('plantation_unit_id');

        $keraniWithoutPdo = User::with(['role', 'plantationUnit'])
            ->where('company_id', $companyId)
            ->whereHas('role', fn ($q) => $q->where('code', 'KERANI'))
            ->whereNotNull('plantation_unit_id')
            ->whereNotIn('plantation_unit_id', $unitsWithPdo)
            ->where('is_active', true)
            ->get();

        foreach ($keraniWithoutPdo as $kerani) {
            $this->send($companyId, NotificationTemplate::EVENT_MONTHLY_REMINDER, collect([$kerani]), [
                'unit_kebun' => $kerani->plantationUnit?->name ?? '',
                'periode'    => $this->formatPeriodRaw($month, $year),
                'nama_user'  => $kerani->full_name,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────
    // RECIPIENT HELPERS
    // ─────────────────────────────────────────────────────

    private function creator(PdoHeader $pdo): Collection
    {
        $user = $pdo->creator;
        return $user ? collect([$user]) : collect();
    }

    private function asistenByUnit(PdoHeader $pdo): Collection
    {
        return User::with('role')
            ->where('company_id', $pdo->company_id)
            ->where('plantation_unit_id', $pdo->plantation_unit_id)
            ->whereHas('role', fn ($q) => $q->where('code', Role::ASISTEN_KEBUN))
            ->where('is_active', true)
            ->get();
    }

    private function byRole(PdoHeader $pdo, string $roleCode): Collection
    {
        return User::with('role')
            ->where('company_id', $pdo->company_id)
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();
    }

    private function baseVars(PdoHeader $pdo): array
    {
        return [
            'nomor_pdo'  => $pdo->pdo_number,
            'periode'    => $this->formatPeriod($pdo),
            'unit_kebun' => $pdo->plantationUnit?->name ?? '',
        ];
    }

    // ─────────────────────────────────────────────────────
    // SEND ENGINE
    // ─────────────────────────────────────────────────────

    private function send(string $companyId, string $eventType, iterable $recipients, array $variables): void
    {
        $template = NotificationTemplate::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->where('channel', NotificationTemplate::CHANNEL_WHATSAPP)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            Log::warning("WhatsApp template tidak ditemukan: {$eventType}");
            return;
        }

        $baseUrl  = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_URL);
        $username = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_USERNAME);
        $password = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_PASSWORD);

        if (! $baseUrl) {
            Log::warning('WhatsApp gateway URL belum dikonfigurasi.');
            return;
        }

        try {
            $password = decrypt($password);
        } catch (\Exception) {
            // Nilai mungkin belum terenkripsi (dev environment)
        }

        $normalizedUrl = rtrim($baseUrl, '/');
        $endpoint = str_ends_with($normalizedUrl, '/send/message')
            ? $normalizedUrl
            : $normalizedUrl . '/send/message';

        $sentUserIds = [];
        foreach ($recipients as $user) {
            if (! $user->whatsapp_number) continue;
            // Deduplicate by user ID (bukan nomor) — satu user tidak perlu terima dua kali
            if (in_array($user->id, $sentUserIds)) continue;
            $sentUserIds[] = $user->id;

            $message = $template->render(array_merge($variables, ['nama_user' => $user->full_name]));
            $phone   = $this->toInternationalFormat($user->whatsapp_number);

            try {
                Http::withBasicAuth($username, $password)
                    ->timeout(5)
                    ->post($endpoint, ['phone' => $phone, 'message' => $message]);
            } catch (\Exception $e) {
                Log::error("WhatsApp send failed for {$user->whatsapp_number}: " . $e->getMessage());
            }
        }
    }

    private function toInternationalFormat(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        }
        return $number;
    }

    private function formatPeriod(PdoHeader $pdo): string
    {
        return $this->formatPeriodRaw($pdo->period_month, $pdo->period_year);
    }

    private function formatPeriodRaw(int $month, int $year): string
    {
        $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                   'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return "{$months[$month]} {$year}";
    }
}
