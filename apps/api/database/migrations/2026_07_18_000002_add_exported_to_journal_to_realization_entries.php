<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realization_entries', function (Blueprint $table) {
            $table->timestamp('exported_to_journal_at')->nullable();
            $table->uuid('exported_to_journal_by')->nullable();

            $table->foreign('exported_to_journal_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('realization_entries', function (Blueprint $table) {
            $table->dropForeign(['exported_to_journal_by']);
            $table->dropColumn(['exported_to_journal_at', 'exported_to_journal_by']);
        });
    }
};
