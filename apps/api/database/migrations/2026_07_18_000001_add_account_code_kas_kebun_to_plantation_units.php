<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantation_units', function (Blueprint $table) {
            $table->string('account_code_kas_kebun', 50)->nullable()->after('payroll_estate_external_id');
        });

        $seed = [
            'KP' => '1-10002',
            'SS' => '1-10003',
            'BN' => '1-10004',
            'JM' => '1-10026',
        ];

        foreach ($seed as $code => $accountCode) {
            DB::table('plantation_units')
                ->where('code', $code)
                ->update(['account_code_kas_kebun' => $accountCode]);
        }
    }

    public function down(): void
    {
        Schema::table('plantation_units', function (Blueprint $table) {
            $table->dropColumn('account_code_kas_kebun');
        });
    }
};
