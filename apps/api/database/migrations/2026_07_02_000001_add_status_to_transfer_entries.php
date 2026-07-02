<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                CREATE TYPE transfer_status_enum AS ENUM ('draft', 'committed');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END
            $$;
        SQL);

        // Default 'committed' agar semua data lama tetap terhitung permanen.
        DB::statement("ALTER TABLE transfer_entries ADD COLUMN status transfer_status_enum NOT NULL DEFAULT 'committed'");
        DB::statement('ALTER TABLE transfer_entries ADD COLUMN committed_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE transfer_entries ADD COLUMN committed_by UUID NULL REFERENCES users(id)');

        // Index agar filter status='committed' cepat pada agregasi.
        DB::statement('CREATE INDEX IF NOT EXISTS transfer_entries_status_idx ON transfer_entries (status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transfer_entries_status_idx');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS committed_by');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS committed_at');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS status');
        DB::statement('DROP TYPE IF EXISTS transfer_status_enum');
    }
};
