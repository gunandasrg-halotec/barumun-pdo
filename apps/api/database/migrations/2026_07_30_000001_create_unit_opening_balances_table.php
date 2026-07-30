<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo kas kebun akhir bulan sebelum sistem PDO mulai dipakai (30 Juni 2026),
     * jadi titik awal (seed) perhitungan saldo kumulatif kas kebun per unit.
     * Hanya berlaku untuk kantong Kas Kebun (rek_kebun) — bukan Pribadi/Vendor.
     */
    public function up(): void
    {
        Schema::create('unit_opening_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plantation_unit_id')->unique();
            $table->foreign('plantation_unit_id')->references('id')->on('plantation_units')->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('as_of_date');
            $table->text('notes')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_opening_balances');
    }
};
