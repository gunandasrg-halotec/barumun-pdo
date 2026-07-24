<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nomor_polisi', 20);
            $table->string('nama', 100);
            $table->foreignUuid('expense_item_id')->nullable()->constrained('expense_items')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz('deleted_at');

            $table->index('nomor_polisi');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
