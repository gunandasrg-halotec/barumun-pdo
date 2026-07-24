<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_trip_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdo_header_id')->constrained('pdo_headers')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->date('trip_date');
            $table->string('driver_name', 150);
            $table->unsignedInteger('trip_count');
            $table->string('trip_type', 20); // 'angkut_tbs' | 'perawatan'
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['vehicle_id', 'pdo_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_trip_logs');
    }
};
