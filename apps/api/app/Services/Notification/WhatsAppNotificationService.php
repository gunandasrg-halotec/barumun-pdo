<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;
use App\Models\PdoHeader;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    /**
     * Kirim notifikasi saat PDO disubmit → ke ASISTEN_KEBUN unit tersebut.
     * BR-NOTIF-001
     */
    public function notifySubmitted(PdoHeader $pdo): void
    {
        $recipients = User::with('role')
            ->where('company_id', $pdo->company_id)
            ->where('plantation_unit_id', $pdo->plantation_unit_id)
            ->whereHas('role', fn ($q) => $q->where('code', 'ASISTEN_KEBUN'))
            ->where('is_active', true)
            ->get();

        $this->send($pdo->company_id, NotificationTemplate::EVENT_PDO_SUBMITTED, $recipients, [
            'nomor_pdo'   => $pdo->pdo_number,
            'periode'     => $this->formatPeriod($pdo),
            'unit_kebun'  => $pdo->plantationUnit?->name ?? '',
        ]);
    }

    /**
     * Kirim notifikasi setelah Asisten approve → ke MANAJER_KEBUN.
     * BR-NOTIF-002
     */
    public function notifyApprovedByAsisten(PdoHeader $pdo): void
    {
        $recipients = User::with('role')
            ->where('company_id', $pdo->company_id)
            ->whereHas('role', fn ($q) => $q->where('code', 'MANAJER_KEBUN'))
            ->where('is_active', true)
            ->get();

        $this->send($pdo->company_id, NotificationTemplate::EVENT_PDO_APPROVED_ASISTEN, $recipients, [
            'nomor_pdo' => $pdo->pdo_number,
            'periode'   => $this->formatPeriod($pdo),
        ]);
    }

    /**
     * Kirim notifikasi setelah Manajer Kebun approve → ke MANAJER_KEUANGAN.
     * BR-NOTIF-002
     */
    public function notifyApprovedByManager(PdoHeader $pdo): void
    {
        $recipients = User::with('role')
            ->where('company_id', $pdo->company_id)
            ->whereHas('role', fn ($q) => $q->where('code', 'MANAJER_KEUANGAN'))
            ->where('is_active', true)
            ->get();

        $this->send($pdo->company_id, NotificationTemplate::EVENT_PDO_APPROVED_MANAGER, $recipients, [
            'nomor_pdo' => $pdo->pdo_number,
            'periode'   => $this->formatPeriod($pdo),
        ]);
    }

    /**
     * Kirim notifikasi ke KERANI saat PDO ditolak.
     * BR-NOTIF-003
     */
    public function notifyRejected(PdoHeader $pdo, string $reason): void
    {
        $creator = $pdo->creator;
        if (! $creator) {
            return;
        }

        $this->send($pdo->company_id, NotificationTemplate::EVENT_PDO_REJECTED, collect([$creator]), [
            'nomor_pdo'    => $pdo->pdo_number,
            'periode'      => $this->formatPeriod($pdo),
            'alasan_reject'=> $reason,
        ]);
    }

    /**
     * Kirim notifikasi ke KERANI saat PDO Final.
     */
    public function notifyFinal(PdoHeader $pdo): void
    {
        $creator = $pdo->creator;
        if (! $creator) {
            return;
        }

        $this->send($pdo->company_id, NotificationTemplate::EVENT_PDO_FINAL, collect([$creator]), [
            'nomor_pdo' => $pdo->pdo_number,
            'periode'   => $this->formatPeriod($pdo),
        ]);
    }

    /**
     * Reminder bulanan: KERANI yang belum buat PDO bulan ini.
     * BR-NOTIF-004 — dipanggil dari scheduled job.
     */
    public function sendMonthlyReminders(string $companyId, int $month, int $year): void
    {
        // Temukan unit yang belum punya PDO bulan ini
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
    // PRIVATE
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

        $endpoint = rtrim($baseUrl, '/') . '/send/message';

        foreach ($recipients as $user) {
            if (! $user->whatsapp_number) {
                continue;
            }

            $message = $template->render(array_merge($variables, ['nama_user' => $user->full_name]));

            try {
                Http::withBasicAuth($username, $password)
                    ->timeout(5)
                    ->post($endpoint, ['phone' => $user->whatsapp_number, 'message' => $message]);
            } catch (\Exception $e) {
                Log::error("WhatsApp send failed for {$user->whatsapp_number}: " . $e->getMessage());
            }
        }
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
