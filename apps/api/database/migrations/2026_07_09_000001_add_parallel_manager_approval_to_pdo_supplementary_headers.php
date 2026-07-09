<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BR-APPR-002 (PDO Tambahan): Manajer Kebun & Manajer Keuangan approve paralel,
     * sama seperti pdo_headers. NULL = belum ada keputusan, TRUE = approve.
     * Reset ke NULL setiap kali PDO Tambahan kembali ke draft (reject).
     */
    public function up(): void
    {
        Schema::table('pdo_supplementary_headers', function (Blueprint $table) {
            $table->boolean('manager_kebun_approved')->nullable()->default(null)->after('status');
            $table->boolean('manager_keuangan_approved')->nullable()->default(null)->after('manager_kebun_approved');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_supplementary_headers', function (Blueprint $table) {
            $table->dropColumn(['manager_kebun_approved', 'manager_keuangan_approved']);
        });
    }
};
