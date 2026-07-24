<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah destination (wajib) dan weight (bobot jarak, 1 = 0-5km, kelipatan
     * 5km per unit, dipakai sbg pengganti trip_count polos saat menghitung
     * split biaya BBM/sparepart — trip yg lebih jauh membebankan lebih besar).
     */
    public function up(): void
    {
        Schema::table('vehicle_trip_logs', function (Blueprint $table) {
            $table->string('destination', 150)->after('trip_type');
            $table->unsignedTinyInteger('weight')->default(1)->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_trip_logs', function (Blueprint $table) {
            $table->dropColumn(['destination', 'weight']);
        });
    }
};
