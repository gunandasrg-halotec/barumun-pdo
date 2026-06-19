<?php

namespace App\Exports;

use App\Services\Reports\ReportQueryService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Support\Collection;

class OverBudgetExport implements
    FromCollection,
    WithHeadings,
    WithTitle,
    WithStyles,
    ShouldAutoSize,
    WithColumnFormatting
{
    public function __construct(private array $filters, private ReportQueryService $service) {}

    public function collection(): Collection
    {
        return $this->service->getOverBudgetData($this->filters)->map(fn ($r) => [
            $r->pdo_number,
            $r->unit_name,
            $r->period_year . '-' . str_pad($r->period_month, 2, '0', STR_PAD_LEFT),
            $r->category_name,
            $r->item_name,
            (int) $r->amount,
            (int) $r->total_transfer,
            (int) $r->total_realization,
            (int) $r->total_realization - (int) $r->total_transfer,
        ]);
    }

    public function headings(): array
    {
        return [
            'No. PDO', 'Unit', 'Periode', 'Kategori', 'Item Biaya',
            'Anggaran', 'Transfer', 'Realisasi', 'Selisih Over',
        ];
    }

    public function title(): string
    {
        return 'Over Budget';
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['argb' => 'FFFCE8E6']],
            ],
        ];
    }
}
