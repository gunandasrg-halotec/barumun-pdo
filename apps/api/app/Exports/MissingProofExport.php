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

class MissingProofExport implements
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
        return $this->service->getMissingProofData($this->filters)->map(fn ($r) => [
            $r->pdo_number,
            $r->unit_name,
            $r->item_name,
            $r->keterangan,
            $r->transaction_date,
            (int) $r->amount,
            $r->recorded_by,
        ]);
    }

    public function headings(): array
    {
        return [
            'No. PDO', 'Unit', 'Item Biaya', 'Keterangan',
            'Tgl Transaksi', 'Nominal', 'Dicatat Oleh',
        ];
    }

    public function title(): string
    {
        return 'Bukti Belum Lengkap';
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['argb' => 'FFFFF3CD']],
            ],
        ];
    }
}
