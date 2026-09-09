<?php

namespace App\Console\Commands;

use App\Models\PdoHeader;
use App\Services\Transfer\TransferEntryService;
use Illuminate\Console\Command;

class SyncDeductionTransferFlagsCommand extends Command
{
    protected $signature   = 'transfer:sync-deduction-flags {--pdo= : Nomor PDO tertentu (mis. PDO-2026-08-BN-001); kosongkan untuk semua PDO}';
    protected $description = 'Selaraskan ulang flag is_transferred baris potongan panjar/koreksi manual dengan progres transfer PDO-nya. '
        . 'Jalankan setelah entri transfer committed dihapus manual dari database — satu-satunya cara entri committed '
        . 'terhapus saat ini, karena aplikasi hanya bisa menghapus draft (lihat TransferEntryService::deleteDraft()).';

    public function __construct(private readonly TransferEntryService $transferEntryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = PdoHeader::query();

        if ($number = $this->option('pdo')) {
            $query->where('pdo_number', $number);
        }

        $pdoIds = $query->pluck('id', 'pdo_number');

        if ($pdoIds->isEmpty()) {
            $this->error("PDO '{$this->option('pdo')}' tidak ditemukan.");
            return self::FAILURE;
        }

        $adjusted = $this->transferEntryService->syncDeductionTransferFlags($pdoIds->values(), null);

        if ($adjusted === 0) {
            $this->info("Sudah selaras. Tidak ada baris potongan yang perlu disesuaikan di {$pdoIds->count()} PDO.");
            return self::SUCCESS;
        }

        $this->info("Selesai. {$adjusted} baris potongan disesuaikan di {$pdoIds->count()} PDO.");
        return self::SUCCESS;
    }
}
