<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BR-REAL-005: Realisasi dibatasi per "kantong" tujuan transfer —
     * KERANI hanya boleh realisasi dari kantong rek_kebun; STAFF_PURCHASING
     * dan MANAJER_KEUANGAN dari kantong pribadi+vendor. settlement_group
     * merekam kantong mana yang dipakai SAAT realisasi dicatat (Opsi A),
     * agar atribusi historis tidak ikut bergeser bila role user berubah.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('realization_entries', function (Blueprint $table) {
                $table->string('settlement_group')->nullable();
            });

            return;
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                CREATE TYPE realization_settlement_group_enum AS ENUM ('kebun', 'pribadi_vendor');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END
            $$;
        SQL);

        DB::statement('ALTER TABLE realization_entries ADD COLUMN settlement_group realization_settlement_group_enum NULL');

        // Backfill data lama berdasarkan role user yang mencatat (recorded_by).
        DB::statement(<<<'SQL'
            UPDATE realization_entries re SET settlement_group = 'kebun'
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE re.recorded_by = u.id AND r.code = 'KERANI'
        SQL);

        DB::statement(<<<'SQL'
            UPDATE realization_entries re SET settlement_group = 'pribadi_vendor'
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE re.recorded_by = u.id AND r.code IN ('STAFF_PURCHASING', 'MANAJER_KEUANGAN')
        SQL);

        // Fallback untuk baris yang recorder-nya tidak match role di atas (mis. dihapus/role lain).
        DB::statement("UPDATE realization_entries SET settlement_group = 'kebun' WHERE settlement_group IS NULL");

        DB::statement('CREATE INDEX IF NOT EXISTS realization_entries_settlement_group_idx ON realization_entries (settlement_group)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('realization_entries', function (Blueprint $table) {
                $table->dropColumn('settlement_group');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS realization_entries_settlement_group_idx');
        DB::statement('ALTER TABLE realization_entries DROP COLUMN IF EXISTS settlement_group');
        DB::statement('DROP TYPE IF EXISTS realization_settlement_group_enum');
    }
};
