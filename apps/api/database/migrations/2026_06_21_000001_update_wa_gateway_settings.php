<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ganti wa_gateway_api_key dengan wa_gateway_username dan wa_gateway_password
        DB::table('system_settings')
            ->where('key', 'wa_gateway_api_key')
            ->update([
                'key'         => 'wa_gateway_username',
                'value'       => '',
                'description' => 'Username untuk Basic Auth WhatsApp gateway.',
            ]);

        // Tambah wa_gateway_password jika belum ada
        $companyIds = DB::table('system_settings')->distinct()->pluck('company_id');
        foreach ($companyIds as $companyId) {
            $exists = DB::table('system_settings')
                ->where('company_id', $companyId)
                ->where('key', 'wa_gateway_password')
                ->exists();

            if (! $exists) {
                DB::table('system_settings')->insert([
                    'id'          => \Illuminate\Support\Str::uuid(),
                    'company_id'  => $companyId,
                    'key'         => 'wa_gateway_password',
                    'value'       => '',
                    'description' => 'Password untuk Basic Auth WhatsApp gateway. Disimpan terenkripsi.',
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'wa_gateway_username')
            ->update(['key' => 'wa_gateway_api_key', 'description' => 'API Key WhatsApp gateway.']);

        DB::table('system_settings')->where('key', 'wa_gateway_password')->delete();
    }
};
