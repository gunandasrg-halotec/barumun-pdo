<?php

namespace App\Console\Commands;

use App\Services\PDO\PdoCloseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoClosePdoCommand extends Command
{
    protected $signature   = 'pdo:auto-close';
    protected $description = 'Tutup otomatis semua PDO final yang periodenya berakhir hari ini (BR-CLOSE-001)';

    public function __construct(private readonly PdoCloseService $closeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('[PDO Auto-Close] Memulai proses penutupan otomatis...');

        try {
            $count = $this->closeService->closeAutomatic();

            if ($count === 0) {
                $this->line('[PDO Auto-Close] Tidak ada PDO yang perlu ditutup hari ini.');
            } else {
                $this->info("[PDO Auto-Close] Berhasil menutup {$count} PDO.");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("[PDO Auto-Close] Error: {$e->getMessage()}");
            Log::error('[AutoClose] Command gagal: ' . $e->getMessage(), ['exception' => $e]);
            return self::FAILURE;
        }
    }
}
