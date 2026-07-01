<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->boolean('is_deduction')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn('is_deduction');
        });
    }
};
