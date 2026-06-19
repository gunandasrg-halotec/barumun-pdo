<?php

namespace App\Exports;

use App\Services\Reports\ReportQueryService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class RecapExport implements FromArray, WithTitle, WithStyles, ShouldAutoSize, WithColumnFormatting
{
    private array $recap;

    public function __construct(private array $filters, private ReportQueryService $service)
    {
        $this->recap = $this->service->getRecapData($filters);
    }

    public function array(): array
    {
        $rows = [];

        // Header row
        $rows[] = ['No', 'Kode Akun', 'Uraian', 'Anggaran', 'Transfer', 'Realisasi', 'Saldo'];

        foreach ($this->recap['categories'] as $cat) {
            // Category header
            $rows[] = [
                $cat['no'],
                $cat['category_code'],
                strtoupper($cat['category_name']),
                $cat['subtotal_amount'],
                $cat['subtotal_transfer'],
                $cat['subtotal_realization'],
                $cat['subtotal_saldo'],
            ];

            foreach ($cat['subcategories'] as $sub) {
                $rows[] = [
                    '',
                    $sub['subcategory_code'],
                    '  ' . $sub['subcategory_name'],
                    $sub['subtotal_amount'],
                    $sub['subtotal_transfer'],
                    $sub['subtotal_realization'],
                    $sub['subtotal_saldo'],
                ];

                foreach ($sub['items'] as $item) {
                    $rows[] = [
                        '',
                        $item['account_number'],
                        '    ' . $item['item_name'],
                        $item['amount'],
                        $item['total_transfer'],
                        $item['total_realization'],
                        $item['saldo'],
                    ];
                }
            }
        }

        // Grand total
        $rows[] = [
            '',
            '',
            'JUMLAH TOTAL',
            $this->recap['grand_total_amount'],
            $this->recap['grand_total_transfer'],
            $this->recap['grand_total_realization'],
            $this->recap['grand_total_saldo'],
        ];

        return $rows;
    }

    public function title(): string
    {
        return 'Rekapitulasi';
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        return [
            1        => [
                'font'      => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['argb' => 'FFD9EAD3']],
            ],
            $lastRow => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FFD9EAD3']],
            ],
        ];
    }
}
