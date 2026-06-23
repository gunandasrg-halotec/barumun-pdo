<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantation_units', function (Blueprint $table): void {
            $table->string('payroll_estate_external_id', 255)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('plantation_units', function (Blueprint $table): void {
            $table->dropColumn('payroll_estate_external_id');
        });
    }
};
