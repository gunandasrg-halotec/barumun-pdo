<?php

use App\Models\ExpenseItem;
use App\Models\PlantationUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->json('external_block_scopes')->nullable()->after('external_block_keys');
        });

        ExpenseItem::query()
            ->whereNotNull('external_block_keys')
            ->whereNull('external_block_scopes')
            ->orderBy('id')
            ->each(function (ExpenseItem $item): void {
                $blockKeys = is_array($item->external_block_keys) ? array_values(array_unique(array_filter($item->external_block_keys, 'is_string'))) : [];
                $routineUnitIds = is_array($item->routine_plantation_unit_ids) ? $item->routine_plantation_unit_ids : [];

                if ($blockKeys === [] || count($routineUnitIds) !== 1) {
                    return;
                }

                $unit = PlantationUnit::find($routineUnitIds[0]);
                if (! $unit instanceof PlantationUnit || ! filled($unit->payroll_estate_external_id)) {
                    return;
                }

                $item->forceFill([
                    'external_block_scopes' => [[
                        'plantation_unit_id' => $unit->id,
                        'block_keys' => $blockKeys,
                    ]],
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table): void {
            $table->dropColumn('external_block_scopes');
        });
    }
};
