<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PDO Tambahan opsi "Gunakan Kas Kebun": item tidak butuh dana transfer baru dari HO,
     * langsung final_merged tanpa approval berjenjang. funding_option pada pdo_details
     * (diisi hanya saat merge, dari header) dipakai RealizationEntryService untuk memaksa
     * funding_source = kas_kebun tanpa perlu join balik ke pdo_supplementary_headers.
     */
    public function up(): void
    {
        Schema::table('pdo_supplementary_headers', function (Blueprint $table) {
            $table->string('funding_option', 20)->default('ho_transfer')->after('status');
        });

        Schema::table('pdo_details', function (Blueprint $table) {
            $table->string('funding_option', 20)->nullable()->after('source_pdo_supplementary_id');
        });
    }

    public function down(): void
    {
        Schema::table('pdo_supplementary_headers', function (Blueprint $table) {
            $table->dropColumn('funding_option');
        });

        Schema::table('pdo_details', function (Blueprint $table) {
            $table->dropColumn('funding_option');
        });
    }
};
