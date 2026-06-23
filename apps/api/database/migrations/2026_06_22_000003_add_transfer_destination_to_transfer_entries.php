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
                CREATE TYPE transfer_destination_enum AS ENUM ('rek_kebun', 'pribadi', 'vendor');
            EXCEPTION
                WHEN duplicate_object THEN null;
            END
            $$;
        SQL);
        DB::statement("ALTER TABLE transfer_entries ADD COLUMN transfer_destination transfer_destination_enum NOT NULL DEFAULT 'rek_kebun'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS transfer_destination');
        DB::statement('DROP TYPE IF EXISTS transfer_destination_enum');
    }
};
