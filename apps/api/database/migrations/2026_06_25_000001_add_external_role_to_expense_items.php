<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->string('external_role', 50)->nullable()->after('external_component_key');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->dropColumn('external_role');
        });
    }
};
