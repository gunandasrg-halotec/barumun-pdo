<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ExportReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $jobId,
        private readonly string $reportType,
        private readonly array  $filters,
        private readonly User   $user,
    ) {}

    public function handle(ReportService $service): void
    {
        Cache::put("export:{$this->jobId}", ['status' => 'processing'], now()->addHour());

        try {
            $data = match ($this->reportType) {
                'realization'  => $service->realization($this->user, $this->filters),
                'over_budget'  => $service->overBudget($this->user, $this->filters),
                'missing_proof'=> $service->missingProof($this->user, $this->filters),
                'recap'        => $service->recap($this->user, $this->filters),
                default        => throw new \InvalidArgumentException("Tipe laporan tidak dikenal: {$this->reportType}"),
            };

            // Simpan sebagai CSV sementara
            $csv  = $this->toCsv($data);
            $path = "exports/{$this->jobId}.csv";
            Storage::disk('local')->put($path, $csv);

            Cache::put("export:{$this->jobId}", [
                'status'     => 'done',
                'path'       => $path,
                'filename'   => "laporan-{$this->reportType}-{$this->jobId}.csv",
                'expires_at' => now()->addHour()->toDateTimeString(),
            ], now()->addHour());
        } catch (\Throwable $e) {
            Cache::put("export:{$this->jobId}", [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ], now()->addHour());
        }
    }

    private function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        // Header
        fputcsv($handle, array_keys((array) $rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, (array) $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
