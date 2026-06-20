<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah grand_total_amount ke pdo_headers
        //    Kolom ini menyimpan jumlah cached SUM(pdo_details.amount) agar
        //    query list PDO tidak perlu JOIN ke details setiap saat.
        Schema::table('pdo_headers', function (Blueprint $table) {
            $table->bigInteger('grand_total_amount')->default(0)->after('status');
        });

        // 2. Rename reference_number → proof_number di realization_entries
        //    Nama ERD v1.2 adalah proof_number; implementasi awal memakai
        //    reference_number. transfer_entries TIDAK diubah (nama sudah sesuai ERD).
        Schema::table('realization_entries', function (Blueprint $table) {
            $table->renameColumn('reference_number', 'proof_number');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_headers', function (Blueprint $table) {
            $table->dropColumn('grand_total_amount');
        });

        Schema::table('realization_entries', function (Blueprint $table) {
            $table->renameColumn('proof_number', 'reference_number');
        });
    }
};
