<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Potongan (is_deduction) dicatat sebagai entri transfer negatif ke rek_kebun
     * (auto-generated) agar total transfer ter-net. Constraint lama (amount > 0)
     * memblokir ini, jadi dilonggarkan: transfer manual tetap harus positif,
     * hanya entri auto-generated yang boleh negatif. Nilai 0 tetap dilarang.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE transfer_entries DROP CONSTRAINT IF EXISTS chk_transfer_entries_amount');
        DB::statement('ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_amount CHECK (amount <> 0 AND (is_auto_generated OR amount > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfer_entries DROP CONSTRAINT IF EXISTS chk_transfer_entries_amount');
        // Catatan: rollback akan gagal bila ada entri negatif; hapus dulu entri auto-generated negatif bila perlu.
        DB::statement('ALTER TABLE transfer_entries ADD CONSTRAINT chk_transfer_entries_amount CHECK (amount > 0)');
    }
};
