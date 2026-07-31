<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Tambah template WA reminder penutupan PDO (kerani/asisten + keuangan) untuk tiap company yang sudah ada. */
    public function up(): void
    {
        $templates = [
            [
                'event_type'    => 'closing_reminder_kerani',
                'template_body' => "*APLIKASI PDO - REMINDER PENUTUPAN PDO BULAN {{bulan_berjalan}}*\n\nHalo {{nama_user}},\n\nBerikut saldo Kas Kebun *{{unit_kebun}}* PDO *{{nomor_pdo}}* yang masih tersisa menjelang penutupan PDO bulan ini:\n\n{{daftar_item}}\n\n*Total Saldo: {{total_saldo}}*\n\nMohon segera dilakukan realisasi/pertanggungjawaban dana sebelum PDO ditutup.",
            ],
            [
                'event_type'    => 'closing_reminder_keuangan',
                'template_body' => "*APLIKASI PDO - REMINDER PENUTUPAN PDO BULAN {{bulan_berjalan}}*\n\nHalo {{nama_user}},\n\nBerikut rekap saldo Kas Kebun seluruh unit yang masih tersisa menjelang penutupan PDO bulan ini:\n\n{{daftar_kebun}}\n\n*TOTAL SALDO SEMUA KEBUN: {{total_saldo}}*\n\nMohon menjadi perhatian sebelum PDO bulan ini ditutup.",
            ],
        ];

        $companyIds = DB::table('companies')->pluck('id');

        foreach ($companyIds as $companyId) {
            foreach ($templates as $template) {
                $exists = DB::table('notification_templates')
                    ->where('company_id', $companyId)
                    ->where('event_type', $template['event_type'])
                    ->where('channel', 'whatsapp')
                    ->exists();

                if (! $exists) {
                    DB::table('notification_templates')->insert([
                        'id'            => Str::uuid(),
                        'company_id'    => $companyId,
                        'event_type'    => $template['event_type'],
                        'channel'       => 'whatsapp',
                        'template_body' => $template['template_body'],
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('event_type', ['closing_reminder_kerani', 'closing_reminder_keuangan'])->delete();
    }
};
