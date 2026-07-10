<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('transfer_entries', function (Blueprint $table) {
                $table->string('transfer_destination')->default('rek_kebun');
            });

            return;
        }

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
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('transfer_entries', function (Blueprint $table) {
                $table->dropColumn('transfer_destination');
            });

            return;
        }

        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS transfer_destination');
        DB::statement('DROP TYPE IF EXISTS transfer_destination_enum');
    }
};
