<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('company_id')
                  ->nullable()
                  ->after('is_active')
                  ->constrained('companies')
                  ->restrictOnDelete();
        });

        // Backfill existing users with the first company (single-tenant default)
        DB::statement('
            UPDATE users SET company_id = (SELECT id FROM companies LIMIT 1)
            WHERE company_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
