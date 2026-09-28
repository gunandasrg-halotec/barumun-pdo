<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDO Tambahan "Gunakan Kas Kebun" sekarang butuh 1 approval Manajer Keuangan sebelum
 * di-merge (sebelumnya auto-merge tanpa approval sama sekali — lihat
 * PdoSupplementaryApprovalService::submitKasKebun()). Status baru
 * 'pending_keuangan_kas_kebun' eksklusif untuk jalur ini, ditambahkan ke constraint
 * status yang sudah ada (chk_pdot_status).
 */
return new class extends Migration
{
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
        $this->postgresOnlyStatement('ALTER TABLE pdo_supplementary_headers DROP CONSTRAINT IF EXISTS chk_pdot_status');
        $this->postgresOnlyStatement("ALTER TABLE pdo_supplementary_headers ADD CONSTRAINT chk_pdot_status CHECK (status IN ('draft','submitted','reviewed_asisten','in_review_manager','in_review_direktur','final_merged','rejected','pending_keuangan_kas_kebun'))");
    }

    public function down(): void
    {
        $this->postgresOnlyStatement('ALTER TABLE pdo_supplementary_headers DROP CONSTRAINT IF EXISTS chk_pdot_status');
        $this->postgresOnlyStatement("ALTER TABLE pdo_supplementary_headers ADD CONSTRAINT chk_pdot_status CHECK (status IN ('draft','submitted','reviewed_asisten','in_review_manager','in_review_direktur','final_merged','rejected'))");
    }
};
