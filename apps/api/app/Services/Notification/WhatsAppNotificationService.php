<?php

namespace App\Services\Notification;

use App\Models\NotificationTemplate;
use App\Models\PdoHeader;
use App\Models\PdoSupplementaryHeader;
use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Report\RecapQueryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    /**
     * Dependency di-resolve LAZY (bukan constructor wajib) karena service ini
     * dipakai sebagai default parameter `= new WhatsAppNotificationService()` di
     * beberapa service/controller lain — konstruktor berargumen wajib akan
     * memicu ArgumentCountError di semua pemanggil tersebut.
     */
    private ?RecapQueryService $recapQueryService = null;

    private function recap(): RecapQueryService
    {
        return $this->recapQueryService ??= app(RecapQueryService::class);
    }

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
    public function notifyApprovedByAsisten(PdoHeader $pdo, ?string $comment = null): void
    {
        $recipients = $this->creator($pdo)
            ->merge($this->byRole($pdo, Role::MANAJER_KEBUN))
            ->merge($this->byRole($pdo, Role::MANAJER_KEUANGAN));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_ASISTEN,
            $recipients,
            array_merge($this->baseVars($pdo), ['catatan_approval' => $this->formatApprovalComment($comment)])
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
    public function notifyApprovedByManager(PdoHeader $pdo, ?string $comment = null): void
    {
        $recipients = $this->creator($pdo)
            ->merge($this->asistenByUnit($pdo))
            ->merge($this->byRole($pdo, Role::DIREKTUR_KEUANGAN));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_MANAGER,
            $recipients,
            array_merge($this->baseVars($pdo), ['catatan_approval' => $this->formatApprovalComment($comment)])
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
    public function notifyFinal(PdoHeader $pdo, ?string $comment = null): void
    {
        $recipients = $this->byRole($pdo, Role::MANAJER_KEUANGAN)
            ->merge($this->byRole($pdo, Role::MANAJER_KEBUN))
            ->merge($this->asistenByUnit($pdo))
            ->merge($this->creator($pdo));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_PDO_FINAL,
            $recipients,
            array_merge($this->baseVars($pdo), ['catatan_approval' => $this->formatApprovalComment($comment)])
        );
    }

    // ─────────────────────────────────────────────────────
    // TRANSFER DANA NOTIFICATIONS
    // ─────────────────────────────────────────────────────

    /** Draft rencana transfer disimpan → Direktur Keuangan (perlu simpan permanen) */
    public function notifyTransferDraftSaved(PdoHeader $pdo, User $actor, Collection $entries): void
    {
        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_TRANSFER_DRAFT_SAVED,
            $this->byRole($pdo, Role::DIREKTUR_KEUANGAN),
            array_merge($this->baseVars($pdo), [
                'dicatat_oleh' => $actor->full_name,
                'daftar_item'  => $this->formatTransferItemList($entries, $pdo),
            ])
        );
    }

    /** Direktur simpan permanen → Manajer Keuangan + Staff Purchasing (boleh mulai transfer) */
    public function notifyTransferPlanApproved(PdoHeader $pdo, User $actor, Collection $entries): void
    {
        $recipients = $this->byRole($pdo, Role::MANAJER_KEUANGAN)
            ->merge($this->byRole($pdo, Role::STAFF_PURCHASING));

        $this->send(
            $pdo->company_id,
            NotificationTemplate::EVENT_TRANSFER_PLAN_APPROVED,
            $recipients,
            array_merge($this->baseVars($pdo), [
                'disetujui_oleh' => $actor->full_name,
                'daftar_item'    => $this->formatTransferItemList($entries, $pdo),
            ])
        );
    }

    /** Render daftar item transfer, dikelompokkan per PDO sumber (Bulanan / masing-masing Tambahan). */
    private function formatTransferItemList(Collection $entries, PdoHeader $pdo): string
    {
        $truncated = $entries->count() > 30;
        $subset    = $truncated ? $entries->take(30) : $entries;

        $groups = $subset->groupBy(fn ($e) => $e->pdoDetail?->source_pdo_supplementary_id ?? 'bulanan');

        $sections = $groups->map(function ($group, $key) use ($pdo) {
            $header = $key === 'bulanan'
                ? "*PDO {$pdo->pdo_number}*"
                : "*PDO {$group->first()->pdoDetail?->sourceSupplementary?->pdo_number}* (Tambahan)";

            $lines = $group->map(fn ($e) => $this->formatTransferLine($e));

            return $header . "\n" . $lines->implode("\n");
        });

        $result = $sections->implode("\n\n");

        // Pesan WA jadi tidak praktis di atas ~30 item — potong dan arahkan ke sistem.
        if ($truncated) {
            $result .= "\n\n… dan " . ($entries->count() - 30) . " item lainnya (lihat sistem untuk daftar lengkap).";
        }

        return $result;
    }

    private function formatTransferLine(\App\Models\TransferEntry $e): string
    {
        $item  = $e->pdoDetail?->expenseItem;
        $label = $item ? "[{$item->code}] {$item->name}" : ($e->pdoDetail?->description ?? '-');
        return "- {$label}: Rp " . number_format($e->amount, 0, ',', '.');
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
    public function notifySupplementaryApprovedByAsisten(PdoSupplementaryHeader $supp, ?string $comment = null): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppCreator($supp)
            ->merge($this->suppByRole($supp, Role::MANAJER_KEBUN))
            ->merge($this->suppByRole($supp, Role::MANAJER_KEUANGAN));

        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_ASISTEN,
            $recipients,
            array_merge($this->suppBaseVars($supp), ['catatan_approval' => $this->formatApprovalComment($comment)])
        );
    }

    /** Kedua Manajer approve PDO Tambahan (paralel, status → in_review_direktur) → Direktur + Asisten + Kerani */
    public function notifySupplementaryApprovedByManager(PdoSupplementaryHeader $supp, ?string $comment = null): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::DIREKTUR_KEUANGAN)
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_APPROVED_MANAGER,
            $recipients,
            array_merge($this->suppBaseVars($supp), ['catatan_approval' => $this->formatApprovalComment($comment)])
        );
    }

    /** Direktur approve PDO Tambahan (final_merged) → Manajer Kebun + Manajer Keuangan + Asisten + Kerani */
    public function notifySupplementaryFinal(PdoSupplementaryHeader $supp, ?string $comment = null): void
    {
        $supp->loadMissing(['creator', 'plantationUnit']);
        $recipients = $this->suppByRole($supp, Role::MANAJER_KEBUN)
            ->merge($this->suppByRole($supp, Role::MANAJER_KEUANGAN))
            ->merge($this->suppAsistenByUnit($supp))
            ->merge($this->suppCreator($supp));

        $this->send(
            $supp->company_id,
            NotificationTemplate::EVENT_PDO_FINAL,
            $recipients,
            array_merge($this->suppBaseVars($supp), ['catatan_approval' => $this->formatApprovalComment($comment)])
        );
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
    // REMINDER PENUTUPAN PDO (manual, dari halaman Pengaturan)
    // ─────────────────────────────────────────────────────

    /**
     * Kirim reminder saldo Kas Kebun ke Kerani + Asisten Kebun tiap unit yang
     * masih punya item saldo tersisa (transfer > realisasi) pada PDO Bulanan
     * berstatus final periode berjalan. Unit tanpa saldo tersisa dilewati.
     *
     * @return array<int, array{unit: string, recipients: int, total_saldo: int}>
     */
    public function sendClosingReminderKerani(string $companyId, int $year, int $month): array
    {
        $summary = [];

        $units = PlantationUnit::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        foreach ($units as $unit) {
            $pdo = $this->finalPdoForUnit($companyId, $unit->id, $year, $month);
            if (! $pdo) {
                continue;
            }

            [$items, $total] = $this->collectKebunSaldoItems($unit->id, $year, $month);
            if (empty($items)) {
                continue;
            }

            $recipients = User::with('role')
                ->where('company_id', $companyId)
                ->where('plantation_unit_id', $unit->id)
                ->whereHas('role', fn ($q) => $q->whereIn('code', [Role::KERANI, Role::ASISTEN_KEBUN]))
                ->where('is_active', true)
                ->get();

            $this->send($companyId, NotificationTemplate::EVENT_CLOSING_REMINDER_KERANI, $recipients, [
                'bulan_berjalan' => $this->formatPeriodRaw($month, $year),
                'unit_kebun'     => $unit->name,
                'nomor_pdo'      => $pdo->pdo_number,
                'daftar_item'    => $this->formatItemList($items),
                'total_saldo'    => $this->formatRupiah($total),
            ]);

            $summary[] = ['unit' => $unit->code, 'recipients' => $recipients->count(), 'total_saldo' => $total];
        }

        return $summary;
    }

    /**
     * Kirim reminder rekap saldo Kas Kebun seluruh unit (dikelompokkan per
     * kebun) ke Manajer Keuangan, Direktur Keuangan, dan Staff Keuangan.
     * 1 pesan gabungan untuk semua penerima level keuangan ini.
     *
     * @return array{recipients: int, total_saldo: int}|array{}
     */
    public function sendClosingReminderKeuangan(string $companyId, int $year, int $month): array
    {
        $units = PlantationUnit::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $blocks     = [];
        $grandTotal = 0;
        $hasAnyPdo  = false;

        foreach ($units as $unit) {
            $pdo = $this->finalPdoForUnit($companyId, $unit->id, $year, $month);
            if (! $pdo) {
                continue;
            }
            $hasAnyPdo = true;

            [$items, $total] = $this->collectKebunSaldoItems($unit->id, $year, $month);
            $grandTotal += $total;

            $blocks[] = "*{$unit->name} ({$unit->code})*\n"
                . (empty($items) ? 'Tidak ada saldo tersisa' : $this->formatItemList($items) . "\nSubtotal: " . $this->formatRupiah($total));
        }

        if (! $hasAnyPdo) {
            return [];
        }

        $recipients = User::with('role')
            ->where('company_id', $companyId)
            ->whereHas('role', fn ($q) => $q->whereIn('code', [Role::MANAJER_KEUANGAN, Role::DIREKTUR_KEUANGAN, Role::STAFF_KEUANGAN]))
            ->where('is_active', true)
            ->get();

        $this->send($companyId, NotificationTemplate::EVENT_CLOSING_REMINDER_KEUANGAN, $recipients, [
            'bulan_berjalan' => $this->formatPeriodRaw($month, $year),
            'daftar_kebun'   => implode("\n\n", $blocks),
            'total_saldo'    => $this->formatRupiah($grandTotal),
        ]);

        return ['recipients' => $recipients->count(), 'total_saldo' => $grandTotal];
    }

    private function finalPdoForUnit(string $companyId, string $unitId, int $year, int $month): ?PdoHeader
    {
        return PdoHeader::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('plantation_unit_id', $unitId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', PdoHeader::STATUS_FINAL)
            ->first();
    }

    /**
     * Item Kas Kebun (kantong=kebun) dengan saldo tersisa (transfer > realisasi,
     * saldo > 0) untuk unit+periode tertentu, via RecapQueryService supaya
     * konsisten dengan angka yang tampil di halaman Rekap Buku Kas.
     *
     * @return array{0: array<int, array{name: string, saldo: int}>, 1: int}
     */
    private function collectKebunSaldoItems(string $unitId, int $year, int $month): array
    {
        $recap = $this->recap()->getRecapData([
            'period_year'  => $year,
            'period_month' => $month,
            'unit_id'      => $unitId,
            'category_id'  => null,
            'start_date'   => null,
            'end_date'     => null,
            'kantong'      => 'kebun',
        ]);

        $items = [];
        foreach ($recap['categories'] as $category) {
            foreach ($category['subcategories'] as $subcategory) {
                foreach ($subcategory['items'] as $item) {
                    if ($item['saldo'] <= 0) {
                        continue;
                    }
                    // Nama lengkap (Kategori — Sub-kategori — Item) karena banyak item
                    // dengan nama sama di sub-kategori berbeda (mis. "GAJI").
                    $fullName = implode(' — ', [$category['category_name'], $subcategory['subcategory_name'], $item['item_name']]);
                    $items[]  = ['name' => $fullName, 'saldo' => $item['saldo']];
                }
            }
        }

        // Total HARUS = saldo_kebun dari RecapQueryService (net SEMUA item, termasuk
        // yang saldo-nya negatif/overbudget) supaya konsisten dengan KPI "Saldo Kas
        // Kebun" di halaman Rekap. Menjumlahkan hanya item yang ditampilkan (saldo>0)
        // akan overstate total karena mengabaikan item overbudget yang mengurangi saldo.
        $total = (int) $recap['saldo_kebun'];

        return [$items, $total];
    }

    private function formatItemList(array $items): string
    {
        $lines = [];
        foreach ($items as $i => $item) {
            $lines[] = ($i + 1) . '. ' . $item['name'] . ' — ' . $this->formatRupiah($item['saldo']);
        }
        return implode("\n", $lines);
    }

    private function formatRupiah(int $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
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

    /** Bangun blok catatan approval — string kosong jika tidak ada komentar, agar tidak menyisakan baris kosong di pesan. */
    private function formatApprovalComment(?string $comment): string
    {
        $trimmed = trim((string) $comment);
        return $trimmed === '' ? '' : "\n\n*Catatan:*\n{$trimmed}";
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
        $deviceId = SystemSetting::getValue($companyId, SystemSetting::KEY_WA_GATEWAY_DEVICE_ID);

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
                    ->withHeaders(['X-Device-Id' => $deviceId])
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
