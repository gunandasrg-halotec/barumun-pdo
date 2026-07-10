<?php

namespace App\Console\Commands;

use App\Models\PdoHeader;
use App\Services\PDO\PdoService;
use Illuminate\Console\Command;

class ResyncPdoGrandTotalsCommand extends Command
{
    protected $signature   = 'pdo:resync-grand-totals';
    protected $description = 'Sinkronkan ulang grand_total_amount tersimpan dengan SUM aktual pdo_details untuk semua PDO Bulanan (perbaikan data untuk PDO yang totalnya belum ter-update setelah merge PDO Tambahan)';

    public function __construct(private readonly PdoService $pdoService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pdos = PdoHeader::all();
        $changed = 0;

        $rows = [];

        foreach ($pdos as $pdo) {
            $before = $pdo->grand_total_amount;

            $this->pdoService->syncGrandTotal($pdo);

            $after = $pdo->fresh()->grand_total_amount;

            if ($before !== $after) {
                $changed++;
                $rows[] = [$pdo->pdo_number, $before, $after];
            }
        }

        if ($changed === 0) {
            $this->info('Semua grand_total_amount sudah sesuai. Tidak ada yang diubah.');
            return self::SUCCESS;
        }

        $this->table(['PDO', 'Sebelum', 'Sesudah'], $rows);
        $this->info("Selesai. {$changed} dari {$pdos->count()} PDO diperbarui.");

        return self::SUCCESS;
    }
}
