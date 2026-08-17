<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function uuidPrimary(Blueprint $table): void
    {
        $column = $table->uuid('id')->primary();

        if (! $this->isSqlite()) {
            $column->default(DB::raw('gen_random_uuid()'));
        }
    }

    private function postgresOnlyStatement(string $sql): void
    {
        if ($this->isSqlite()) {
            return;
        }

        DB::statement($sql);
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    public function up(): void
    {
        // ─────────────────────────────────────────
        // PETTY_CASH_VOUCHERS
        // ─────────────────────────────────────────
        Schema::create('petty_cash_vouchers', function (Blueprint $table) {
            $this->uuidPrimary($table);
            $table->foreignUuid('pdo_header_id')->constrained('pdo_headers')->restrictOnDelete();
            $table->string('voucher_number', 100)->unique();
            $table->string('paid_to', 255);
            $table->date('voucher_date');
            $table->string('status', 20)->default('draft');
            $table->bigInteger('total_amount')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('scan_file_name', 255)->nullable();
            $table->text('scan_file_path')->nullable();
            $table->string('scan_disk', 20)->nullable();
            $table->string('scan_mime_type', 100)->nullable();
            $table->bigInteger('scan_file_size_bytes')->nullable();
            $table->foreignUuid('scan_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('scan_uploaded_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->comment('Petty Cash Voucher — bukti otorisasi fisik untuk realisasi tunai kas kebun.');
        });

        $this->postgresOnlyStatement("ALTER TABLE petty_cash_vouchers ADD CONSTRAINT chk_pcv_status CHECK (status IN ('draft','posted'))");
        $this->postgresOnlyStatement("ALTER TABLE petty_cash_vouchers ADD CONSTRAINT chk_pcv_total_amount CHECK (total_amount >= 0)");
        $this->postgresOnlyStatement("ALTER TABLE petty_cash_vouchers ADD CONSTRAINT chk_pcv_scan_file_size CHECK (scan_file_size_bytes IS NULL OR scan_file_size_bytes > 0)");
        $this->postgresOnlyStatement("ALTER TABLE petty_cash_vouchers ADD CONSTRAINT chk_pcv_posted_needs_scan CHECK (status = 'draft' OR (scan_file_path IS NOT NULL AND posted_at IS NOT NULL))");
        $this->postgresOnlyStatement("CREATE INDEX idx_pcv_pdo_status ON petty_cash_vouchers(pdo_header_id, status)");
        $this->postgresOnlyStatement("CREATE INDEX idx_pcv_created_by ON petty_cash_vouchers(created_by)");

        // ─────────────────────────────────────────
        // PETTY_CASH_VOUCHER_LINES
        // ─────────────────────────────────────────
        Schema::create('petty_cash_voucher_lines', function (Blueprint $table) {
            $this->uuidPrimary($table);
            $table->foreignUuid('petty_cash_voucher_id')->constrained('petty_cash_vouchers')->cascadeOnDelete();
            $table->foreignUuid('pdo_detail_id')->constrained('pdo_details')->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->smallInteger('line_no');
            $table->string('description', 255);
            $table->bigInteger('amount');
            $table->foreignUuid('realization_entry_id')->nullable()->unique()->constrained('realization_entries')->nullOnDelete();
            $table->timestampsTz();

            $table->comment('Baris item Petty Cash Voucher. 1 baris = 1 realization_entry setelah voucher diposting.');
        });

        $this->postgresOnlyStatement("ALTER TABLE petty_cash_voucher_lines ADD CONSTRAINT chk_pcvl_amount CHECK (amount > 0)");
        $this->postgresOnlyStatement("ALTER TABLE petty_cash_voucher_lines ADD CONSTRAINT chk_pcvl_line_no CHECK (line_no >= 1)");
        $this->postgresOnlyStatement("CREATE UNIQUE INDEX uq_pcvl_voucher_line ON petty_cash_voucher_lines(petty_cash_voucher_id, line_no)");
        $this->postgresOnlyStatement("CREATE INDEX idx_pcvl_pdo_detail ON petty_cash_voucher_lines(pdo_detail_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_voucher_lines');
        Schema::dropIfExists('petty_cash_vouchers');
    }
};
