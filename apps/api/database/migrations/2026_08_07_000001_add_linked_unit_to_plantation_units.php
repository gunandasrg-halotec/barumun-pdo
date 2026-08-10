<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * linked_unit_id: unit kedua yang otomatis accessible untuk KERANI/ASISTEN_KEBUN
     * yang terikat ke unit ini (lihat EnsureUnitAccess). One-directional — cukup
     * di-set di unit "rumah" (Sosa), menunjuk ke unit project terpisah (Sosa
     * Replanting), supaya kerani/asisten tidak perlu akun kedua.
     */
    public function up(): void
    {
        Schema::table('plantation_units', function (Blueprint $table) {
            $table->uuid('linked_unit_id')->nullable()->after('id');
            $table->foreign('linked_unit_id')->references('id')->on('plantation_units')->nullOnDelete();
        });

        $sosa = DB::table('plantation_units')->where('code', 'SS')->first();

        if ($sosa) {
            $replantingId = (string) Str::orderedUuid();

            DB::table('plantation_units')->insert([
                'id'                         => $replantingId,
                'company_id'                 => $sosa->company_id,
                'code'                       => 'SS-RPL',
                'name'                       => 'Sosa - Replanting',
                'payroll_estate_external_id' => $sosa->payroll_estate_external_id,
                'account_code_kas_kebun'     => $sosa->account_code_kas_kebun,
                'is_active'                  => true,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);

            DB::table('plantation_units')->where('id', $sosa->id)->update(['linked_unit_id' => $replantingId]);
        }
    }

    public function down(): void
    {
        $sosa = DB::table('plantation_units')->where('code', 'SS')->first();
        if ($sosa) {
            DB::table('plantation_units')->where('id', $sosa->id)->update(['linked_unit_id' => null]);
        }
        DB::table('plantation_units')->where('code', 'SS-RPL')->delete();

        Schema::table('plantation_units', function (Blueprint $table) {
            $table->dropForeign(['linked_unit_id']);
            $table->dropColumn('linked_unit_id');
        });
    }
};
