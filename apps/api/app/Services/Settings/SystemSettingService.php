<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

class SystemSettingService
{
    public function listSettings(string $companyId): Collection
    {
        return SystemSetting::where('company_id', $companyId)
            ->orderBy('key')
            ->get()
            ->map(function ($setting) {
                if ($setting->key === SystemSetting::KEY_WA_GATEWAY_PASSWORD && $setting->value) {
                    $setting->value = '••••••••';
                }
                return $setting;
            });
    }

    /**
     * Update satu atau lebih setting sekaligus.
     * Input: ['key' => 'value', ...]
     * ADMIN only.
     */
    public function updateSettings(string $companyId, array $data, User $actor): void
    {
        foreach ($data as $key => $value) {
            $setting = SystemSetting::where('company_id', $companyId)
                ->where('key', $key)
                ->firstOrFail();

            $old = $setting->toArray();

            // Enkripsi password sebelum disimpan; abaikan jika masih masked
            if ($key === SystemSetting::KEY_WA_GATEWAY_PASSWORD && $value !== '••••••••') {
                $value = encrypt($value);
            }

            $setting->update(['value' => $value, 'updated_by' => $actor->id]);

            AuditLog::record(
                actor: $actor,
                entityType: 'system_settings',
                entityId: $setting->id,
                action: 'UPDATE',
                oldValues: $old,
                newValues: $setting->fresh()->toArray()
            );
        }
    }

    /**
     * Test konektivitas WhatsApp gateway.
     * Kirim pesan ping ke nomor admin.
     */
    public function testWhatsApp(string $companyId, User $actor): array
    {
        $baseUrl  = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_URL);
        $username = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_USERNAME);
        $password = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_PASSWORD);

        if (! $baseUrl) {
            return ['success' => false, 'message' => 'URL WhatsApp gateway belum dikonfigurasi.'];
        }

        try {
            $password = decrypt($password);
        } catch (\Exception) {
            // Jika tidak terenkripsi, pakai langsung
        }

        $normalizedUrl = rtrim($baseUrl, '/');
        $endpoint = str_ends_with($normalizedUrl, '/send/message')
            ? $normalizedUrl
            : $normalizedUrl . '/send/message';

        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout(10)
                ->post($endpoint, [
                    'phone'   => $actor->whatsapp_number ?? '628100000000',
                    'message' => 'Test koneksi WhatsApp Gateway PDO System — ' . now()->toDateTimeString(),
                ]);

            return [
                'success' => $response->successful(),
                'status'  => $response->status(),
                'message' => $response->successful()
                    ? 'Koneksi berhasil. Pesan test terkirim ke ' . ($actor->whatsapp_number ?? '628100000000')
                    : 'Gateway merespons dengan error: ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Gagal terhubung ke gateway: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────
    // NOTIFICATION TEMPLATES
    // ─────────────────────────────────────────────────────

    public function listTemplates(string $companyId): Collection
    {
        return NotificationTemplate::where('company_id', $companyId)
            ->orderBy('event_type')
            ->get();
    }

    public function updateTemplate(NotificationTemplate $template, array $data, User $actor): NotificationTemplate
    {
        $old = $template->toArray();
        $template->update($data);

        AuditLog::record(
            actor: $actor,
            entityType: 'notification_templates',
            entityId: $template->id,
            action: 'UPDATE',
            oldValues: $old,
            newValues: $template->fresh()->toArray()
        );

        return $template->fresh();
    }
}
