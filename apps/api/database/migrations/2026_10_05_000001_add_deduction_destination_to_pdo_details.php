<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * deduction_destination: kantong pilihan kasir untuk item Potongan Panjar SEBELUM
     * Simpan Permanen. Entri transfer potongan baru lahir saat commit (oleh Direktur
     * Keuangan, di sesi berbeda dari pembuat draft), jadi pilihan itu harus tersimpan di
     * server saat Simpan Draft — kalau hanya hidup di state React, Direktur selalu
     * melihat default rek_kebun dan semua potongan pribadi tercatat ke kebun.
     */
    public function up(): void
    {
        Schema::table('pdo_details', function (Blueprint $table) {
            $table->string('deduction_destination', 20)->nullable()->after('funding_option');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_details', function (Blueprint $table) {
            $table->dropColumn('deduction_destination');
        });
    }
};
