<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BR-APPR-002: Tracking approval paralel Manajer Kebun & Manajer Keuangan.
     * NULL = belum ada keputusan, TRUE = approve, FALSE = pernah reject (sudah reset ke draft).
     * Reset ke NULL setiap kali PDO kembali ke reviewed_asisten.
     */
    public function up(): void
    {
        Schema::table('pdo_headers', function (Blueprint $table) {
            $table->boolean('manager_kebun_approved')->nullable()->default(null)->after('grand_total_amount');
            $table->boolean('manager_keuangan_approved')->nullable()->default(null)->after('manager_kebun_approved');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_headers', function (Blueprint $table) {
            $table->dropColumn(['manager_kebun_approved', 'manager_keuangan_approved']);
        });
    }
};
