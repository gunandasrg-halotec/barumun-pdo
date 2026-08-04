<?php

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        Schema::table('expense_items', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->boolean('is_fund_return')->default(false)->after('is_deduction');
        });

        // Seed baris sistem PENGEMBALIAN SISA DANA BULAN LALU untuk setiap company.
        foreach (Company::all() as $company) {
            $category = ExpenseCategory::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'PSD'],
                [
                    'name'             => 'PENGEMBALIAN SISA DANA',
                    'display_order'    => 99,
                    'include_in_recap' => true,
                    'is_active'        => true,
                    'is_system'        => true,
                ]
            );

            $subcategory = ExpenseSubcategory::updateOrCreate(
                ['category_id' => $category->id, 'code' => 'PSD-KAS'],
                [
                    'name'          => 'KAS KEBUN',
                    'display_order' => 0,
                    'is_active'     => true,
                    'is_system'     => true,
                ]
            );

            ExpenseItem::updateOrCreate(
                ['subcategory_id' => $subcategory->id, 'code' => 'PSD-KAS-001'],
                [
                    'name'                    => 'PENGEMBALIAN SISA DANA BULAN LALU',
                    'default_account_number'  => '1-10019',
                    'display_order'           => 0,
                    'is_active'               => true,
                    'is_system'               => true,
                    'is_fund_return'          => true,
                    'mode_input'              => ExpenseItem::MODE_MANUAL,
                ]
            );
        }
    }

    public function down(): void
    {
        ExpenseItem::where('code', 'PSD-KAS-001')->delete();
        ExpenseSubcategory::where('code', 'PSD-KAS')->delete();
        ExpenseCategory::where('code', 'PSD')->delete();

        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'is_fund_return']);
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
