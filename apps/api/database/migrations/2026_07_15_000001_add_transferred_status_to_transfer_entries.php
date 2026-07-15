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
                $table->boolean('is_transferred')->default(false);
                $table->timestamp('transferred_at')->nullable();
                $table->foreignUuid('transferred_by')->nullable()->constrained('users');
            });

            // Backfill: semua entry committed yang sudah ada sebelum fitur ini dianggap sudah ditransfer.
            DB::statement("UPDATE transfer_entries SET is_transferred = 1, transferred_at = datetime('now') WHERE status = 'committed'");

            return;
        }

        DB::statement('ALTER TABLE transfer_entries ADD COLUMN is_transferred BOOLEAN NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE transfer_entries ADD COLUMN transferred_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE transfer_entries ADD COLUMN transferred_by UUID NULL REFERENCES users(id)');

        // Index agar filter is_transferred cepat pada halaman Daftar Perintah Transfer.
        DB::statement('CREATE INDEX IF NOT EXISTS transfer_entries_is_transferred_idx ON transfer_entries (is_transferred)');

        // Backfill: semua entry committed yang sudah ada sebelum fitur ini dianggap sudah ditransfer.
        DB::statement("UPDATE transfer_entries SET is_transferred = true, transferred_at = NOW() WHERE status = 'committed'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('transfer_entries', function (Blueprint $table) {
                $table->dropForeign(['transferred_by']);
                $table->dropColumn(['is_transferred', 'transferred_at', 'transferred_by']);
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS transfer_entries_is_transferred_idx');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS transferred_by');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS transferred_at');
        DB::statement('ALTER TABLE transfer_entries DROP COLUMN IF EXISTS is_transferred');
    }
};
