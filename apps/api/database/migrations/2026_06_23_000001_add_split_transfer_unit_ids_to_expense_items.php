<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE expense_items ADD COLUMN split_transfer_plantation_unit_ids UUID[] DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE expense_items DROP COLUMN IF EXISTS split_transfer_plantation_unit_ids');
    }
};
