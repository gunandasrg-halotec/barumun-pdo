<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->boolean('split_transfer')->default(false)->after('mode_input');
        });

        // PostgreSQL array type tidak didukung Blueprint, pakai raw SQL
        DB::statement('ALTER TABLE expense_items ADD COLUMN routine_plantation_unit_ids UUID[] DEFAULT NULL');
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn('split_transfer');
        });
        DB::statement('ALTER TABLE expense_items DROP COLUMN IF EXISTS routine_plantation_unit_ids');
    }
};
