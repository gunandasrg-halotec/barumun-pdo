<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->string('external_source_system', 50)->nullable()->after('mode_input');
            $table->string('external_component', 80)->nullable()->after('external_source_system');
            $table->string('external_component_key', 100)->nullable()->after('external_component');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->dropColumn('external_source_system');
            $table->dropColumn('external_component');
            $table->dropColumn('external_component_key');
        });
    }
};
