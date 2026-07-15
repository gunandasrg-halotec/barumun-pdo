<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = DB::table('companies')->value('id');

        if (! $companyId) {
            $this->command->warn('⚠️  Tidak ada company. Jalankan CompanySeeder terlebih dahulu.');
            return;
        }

        $templates = [
            // ── PDO Lifecycle ──────────────────────────────────────────────
            [
                'event_type'    => 'pdo_submitted',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nPDO *{{nomor_pdo}}* untuk periode *{{periode}}* dari unit *{{unit_kebun}}* telah disubmit dan menunggu persetujuan Anda.\n\nSilakan buka sistem PDO untuk melakukan review.",
            ],
            [
                'event_type'    => 'pdo_approved_asisten',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nPDO *{{nomor_pdo}}* telah disetujui Asisten Kebun dan kini menunggu persetujuan Anda (Manajer).\n\nSilakan buka sistem PDO untuk melakukan review.",
            ],
            [
                'event_type'    => 'pdo_approved_manager',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nPDO *{{nomor_pdo}}* periode *{{periode}}* telah disetujui kedua Manajer dan menunggu persetujuan akhir Anda.\n\nSilakan buka sistem PDO untuk approval final.",
            ],
            [
                'event_type'    => 'pdo_rejected',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nPDO *{{nomor_pdo}}* Anda untuk periode *{{periode}}* telah *ditolak*.\n\n*Alasan penolakan:*\n{{alasan_reject}}\n\nSilakan lakukan revisi dan submit kembali melalui sistem PDO.",
            ],
            [
                'event_type'    => 'pdo_final',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nPDO *{{nomor_pdo}}* periode *{{periode}}* telah *disetujui* oleh Direktur Keuangan dan kini berstatus *Final*.\n\nAnda sudah dapat mulai mencatat realisasi dana operasional.",
            ],

            // ── SLA Reminder ───────────────────────────────────────────────
            [
                'event_type'    => 'sla_reminder',
                'channel'       => 'whatsapp',
                'template_body' => "Pengingat SLA: PDO *{{nomor_pdo}}* sedang menunggu persetujuan Anda sejak kemarin.\n\nBatas waktu review adalah 1 hari kerja. Mohon segera berikan keputusan melalui sistem PDO.",
            ],

            // ── Reminder Bulanan (BRD BR-NOTIF-004) ───────────────────────
            [
                'event_type'    => 'monthly_reminder',
                'channel'       => 'whatsapp',
                'template_body' => "Pengingat: PDO Bulanan untuk unit *{{unit_kebun}}* periode *{{periode}}* belum disubmit.\n\nMohon segera buat dan ajukan PDO melalui sistem untuk memastikan operasional kebun berjalan lancar.",
            ],

            // ── PDO Closed ─────────────────────────────────────────────────
            [
                'event_type'    => 'pdo_closed',
                'channel'       => 'whatsapp',
                'template_body' => "Informasi: PDO *{{nomor_pdo}}* periode *{{periode}}* telah *ditutup*.\n\nInput realisasi dan transfer baru tidak dapat dilakukan lagi untuk PDO ini.",
            ],

            // ── Transfer Dana (Rencana Transfer) ────────────────────────────
            [
                'event_type'    => 'transfer_draft_saved',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nDraft *Rencana Transfer Dana* untuk PDO *{{nomor_pdo}}* periode *{{periode}}* telah disimpan oleh {{dicatat_oleh}} dan menunggu persetujuan Anda:\n\n{{daftar_item}}\n\nSilakan lakukan *Simpan Permanen* melalui sistem untuk menyetujui rencana transfer ini.",
            ],
            [
                'event_type'    => 'transfer_plan_approved',
                'channel'       => 'whatsapp',
                'template_body' => "Halo {{nama_user}},\n\nRencana Transfer Dana untuk PDO *{{nomor_pdo}}* periode *{{periode}}* telah *disetujui* oleh Direktur Keuangan ({{disetujui_oleh}}) untuk item berikut:\n\n{{daftar_item}}\n\nProses transfer dana untuk item-item di atas sudah dapat dilakukan.",
            ],
        ];

        foreach ($templates as $template) {
            DB::table('notification_templates')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'event_type' => $template['event_type'],
                    'channel'    => $template['channel'],
                ],
                [
                    'template_body' => $template['template_body'],
                    'is_active'     => true,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }

        $this->command->info('✅ Notification templates seeded: ' . count($templates) . ' templates');
    }
}
