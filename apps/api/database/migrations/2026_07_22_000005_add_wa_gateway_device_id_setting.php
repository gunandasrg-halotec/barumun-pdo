<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tambah wa_gateway_device_id (header X-Device-Id wajib dikirim gateway
     * WhatsApp) untuk tiap company yang sudah ada, default 'barumun'.
     */
    public function up(): void
    {
        $companyIds = DB::table('system_settings')->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $exists = DB::table('system_settings')
                ->where('company_id', $companyId)
                ->where('key', 'wa_gateway_device_id')
                ->exists();

            if (! $exists) {
                DB::table('system_settings')->insert([
                    'id'          => Str::uuid(),
                    'company_id'  => $companyId,
                    'key'         => 'wa_gateway_device_id',
                    'value'       => 'barumun',
                    'description' => 'Device ID untuk header X-Device-Id saat mengirim pesan WhatsApp via gateway.',
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'wa_gateway_device_id')->delete();
    }
};
