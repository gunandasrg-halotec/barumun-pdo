<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Ambil company_id pertama (PT Barumun Palma Nauli)
        $companyId = DB::table('companies')->value('id');

        if (! $companyId) {
            $this->command->warn('⚠️  Tidak ada company. Jalankan CompanySeeder terlebih dahulu.');
            return;
        }

        $settings = [
            // ── Threshold validasi realisasi (BRD v1.1 — global) ──────────
            [
                'key'         => 'threshold_proof_amount',
                'value'       => '1000000',
                'description' => 'Nominal minimum (Rupiah) yang mewajibkan upload bukti transaksi. Default: Rp 1.000.000.',
            ],
            [
                'key'         => 'threshold_explanation_amount',
                'value'       => '500000',
                'description' => 'Selisih |Transfer - Realisasi| per item (Rupiah) yang mewajibkan penjelasan. Default: Rp 500.000.',
            ],

            // ── WhatsApp Gateway ───────────────────────────────────────────
            [
                'key'         => 'wa_gateway_url',
                'value'       => '',
                'description' => 'Base URL WhatsApp gateway. Contoh: https://wa.gateway.barumunpalma.co.id — sistem akan menambahkan /send/message secara otomatis.',
            ],
            [
                'key'         => 'wa_gateway_username',
                'value'       => '',
                'description' => 'Username untuk Basic Auth WhatsApp gateway.',
            ],
            [
                'key'         => 'wa_gateway_password',
                'value'       => '',
                'description' => 'Password untuk Basic Auth WhatsApp gateway. Disimpan terenkripsi.',
            ],
            [
                'key'         => 'wa_gateway_device_id',
                'value'       => 'barumun',
                'description' => 'Device ID untuk header X-Device-Id saat mengirim pesan WhatsApp via gateway.',
            ],

            // ── Jadwal reminder bulanan (BRD BR-NOTIF-004) ────────────────
            [
                'key'         => 'reminder_day_of_month',
                'value'       => '1',
                'description' => 'Tanggal pengiriman reminder PDO bulanan (1–28). Default: tanggal 1.',
            ],
            [
                'key'         => 'reminder_hour',
                'value'       => '8',
                'description' => 'Jam pengiriman reminder bulanan (0–23, WIB). Default: 08:00.',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['company_id' => $companyId, 'key' => $setting['key']],
                [
                    'value'      => $setting['value'],
                    'description'=> $setting['description'],
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('✅ System settings seeded: ' . count($settings) . ' settings');
    }
}
