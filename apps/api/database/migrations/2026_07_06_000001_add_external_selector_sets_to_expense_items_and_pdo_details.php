<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->json('external_component_keys')->nullable()->after('external_component_key');
            $table->json('external_block_keys')->nullable()->after('external_component_keys');
        });

        Schema::table('pdo_details', function (Blueprint $table): void {
            $table->json('external_component_keys')->nullable()->after('external_component_key');
            $table->json('external_block_keys')->nullable()->after('external_component_keys');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->dropColumn(['external_component_keys', 'external_block_keys']);
        });

        Schema::table('pdo_details', function (Blueprint $table): void {
            $table->dropColumn(['external_component_keys', 'external_block_keys']);
        });
    }
};
