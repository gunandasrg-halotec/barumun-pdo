<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('expense_items', function (Blueprint $table) {
                $table->json('split_transfer_plantation_unit_ids')->nullable();
            });

            return;
        }

        DB::statement('ALTER TABLE expense_items ADD COLUMN split_transfer_plantation_unit_ids UUID[] DEFAULT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('expense_items', function (Blueprint $table) {
                $table->dropColumn('split_transfer_plantation_unit_ids');
            });

            return;
        }

        DB::statement('ALTER TABLE expense_items DROP COLUMN IF EXISTS split_transfer_plantation_unit_ids');
    }
};
