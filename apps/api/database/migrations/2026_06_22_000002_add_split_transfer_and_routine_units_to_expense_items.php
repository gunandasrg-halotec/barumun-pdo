<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->boolean('split_transfer')->default(false)->after('mode_input');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('expense_items', function (Blueprint $table) {
                $table->json('routine_plantation_unit_ids')->nullable();
            });

            return;
        }

        DB::statement('ALTER TABLE expense_items ADD COLUMN routine_plantation_unit_ids UUID[] DEFAULT NULL');
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn('split_transfer');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('expense_items', function (Blueprint $table) {
                $table->dropColumn('routine_plantation_unit_ids');
            });

            return;
        }

        DB::statement('ALTER TABLE expense_items DROP COLUMN IF EXISTS routine_plantation_unit_ids');
    }
};
