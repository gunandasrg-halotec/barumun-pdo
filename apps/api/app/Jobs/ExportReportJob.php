<?php

namespace App\Jobs;

use App\Exports\MissingProofExport;
use App\Exports\OverBudgetExport;
use App\Exports\RealizationExport;
use App\Exports\RecapExport;
use App\Services\Reports\ReportQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private string $jobId,
        private string $reportType,
        private string $format,
        private array  $filters,
        private string $userId,
    ) {
        $this->onQueue('exports');
    }

    public function handle(ReportQueryService $service): void
    {
        $this->setStatus('processing');

        try {
            $path = match ($this->format) {
                'xlsx' => $this->exportExcel($service),
                'pdf'  => $this->exportPdf($service),
                default => throw new \InvalidArgumentException("Format tidak dikenal: {$this->format}"),
            };

            $url = Storage::disk('s3')->temporaryUrl($path, now()->addHours(2));

            $this->setStatus('done', ['url' => $url, 'path' => $path]);
        } catch (\Throwable $e) {
            Log::error('ExportReportJob failed', ['job_id' => $this->jobId, 'error' => $e->getMessage()]);
            $this->setStatus('failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function exportExcel(ReportQueryService $service): string
    {
        $export = match ($this->reportType) {
            'realization'   => new RealizationExport($this->filters, $service),
            'over_budget'   => new OverBudgetExport($this->filters, $service),
            'missing_proof' => new MissingProofExport($this->filters, $service),
            'recap'         => new RecapExport($this->filters, $service),
            default         => throw new \InvalidArgumentException("Tipe laporan tidak dikenal: {$this->reportType}"),
        };

        $filename = "reports/{$this->reportType}_{$this->jobId}.xlsx";
        Excel::store($export, $filename, 's3');

        return $filename;
    }

    private function exportPdf(ReportQueryService $service): string
    {
        $view = match ($this->reportType) {
            'realization'   => 'pdf.realization',
            'over_budget'   => 'pdf.over_budget',
            'missing_proof' => 'pdf.missing_proof',
            'recap'         => 'pdf.recap',
            default         => throw new \InvalidArgumentException("Tipe laporan tidak dikenal: {$this->reportType}"),
        };

        $data = match ($this->reportType) {
            'recap'         => ['recap' => $service->getRecapData($this->filters), 'filters' => $this->filters],
            'realization'   => ['rows' => $service->getRealizationData($this->filters), 'filters' => $this->filters],
            'over_budget'   => ['rows' => $service->getOverBudgetData($this->filters), 'filters' => $this->filters],
            'missing_proof' => ['rows' => $service->getMissingProofData($this->filters), 'filters' => $this->filters],
        };

        $pdf      = Pdf::loadView($view, $data)->setPaper('a4', 'landscape');
        $filename = "reports/{$this->reportType}_{$this->jobId}.pdf";

        Storage::disk('s3')->put($filename, $pdf->output());

        return $filename;
    }

    private function setStatus(string $status, array $extra = []): void
    {
        Cache::put("export_job:{$this->jobId}", array_merge([
            'status'      => $status,
            'report_type' => $this->reportType,
            'format'      => $this->format,
            'created_at'  => now()->toISOString(),
        ], $extra), now()->addHours(24));
    }

    public function failed(\Throwable $exception): void
    {
        $this->setStatus('failed', ['error' => $exception->getMessage()]);
    }
}
