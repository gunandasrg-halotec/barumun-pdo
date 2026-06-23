<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdo_details', function (Blueprint $table) {
            $table->string('external_source_system', 50)->nullable()->after('amount');
            $table->string('external_component', 100)->nullable()->after('external_source_system');
            $table->string('external_component_key', 100)->nullable()->after('external_component');
            $table->timestampTz('external_amount_pulled_at')->nullable()->after('external_component_key');
            $table->json('external_payload')->nullable()->after('external_amount_pulled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_details', function (Blueprint $table) {
            $table->dropColumn([
                'external_source_system',
                'external_component',
                'external_component_key',
                'external_amount_pulled_at',
                'external_payload',
            ]);
        });
    }
};
