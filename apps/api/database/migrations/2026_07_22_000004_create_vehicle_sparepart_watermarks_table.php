<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyimpan tanggal terakhir yang sudah diproses (watermark) untuk
     * pembebanan biaya sparepart per kendaraan per periode PDO (bulan/tahun),
     * agar export tahap 2 berikutnya dalam periode yang sama hanya
     * menghitung slice waktu baru (tidak menghitung ulang trip/pembelian
     * yang sudah pernah dibebankan).
     */
    public function up(): void
    {
        Schema::create('vehicle_sparepart_watermarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->date('watermark_date');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['vehicle_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_sparepart_watermarks');
    }
};
