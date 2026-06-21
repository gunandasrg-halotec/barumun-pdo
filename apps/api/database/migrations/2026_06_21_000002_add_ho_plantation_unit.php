<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $companyIds = DB::table('companies')->pluck('id');

        foreach ($companyIds as $companyId) {
            $exists = DB::table('plantation_units')
                ->where('company_id', $companyId)
                ->where('code', 'HO')
                ->exists();

            if (! $exists) {
                DB::table('plantation_units')->insert([
                    'id'         => Str::uuid(),
                    'company_id' => $companyId,
                    'code'       => 'HO',
                    'name'       => 'Head Office',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('plantation_units')->where('code', 'HO')->delete();
    }
};
