<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class RecapDirectExport implements WithEvents, ShouldAutoSize
{
    private const FILL_HEADER  = 'FFD9EAD3';
    private const FILL_CAT     = 'FFB6D7A8';
    private const FILL_SUB     = 'FFD9EAD3';
    private const FILL_GRAND   = 'FF93C47D';

    public function __construct(
        private array   $recap,
        private ?object $unit,
        private int     $month,
        private int     $year,
    ) {}

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $row   = 1;

                // ── Meta block ───────────────────────────────────────────
                $monthName = ['', 'Januari','Februari','Maret','April','Mei','Juni',
                              'Juli','Agustus','September','Oktober','November','Desember'][$this->month] ?? $this->month;

                foreach ([
                    ['Unit Kebun', $this->unit ? "{$this->unit->code} — {$this->unit->name}" : '—'],
                    ['Periode',    "{$monthName} {$this->year}"],
                ] as [$label, $value]) {
                    $sheet->setCellValue("A{$row}", $label);
                    $sheet->setCellValue("B{$row}", $value);
                    $sheet->mergeCells("B{$row}:G{$row}");
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                    $row++;
                }
                $row++;

                // ── Column headings ──────────────────────────────────────
                $sheet->fromArray([['No', 'Kode', 'Uraian', 'Pengajuan', 'Total Transfer', 'Total Realisasi', 'Saldo']], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true], 'fill' => self::FILL_HEADER, 'border' => Border::BORDER_THIN, 'align' => Alignment::HORIZONTAL_CENTER]);
                $row++;

                foreach ($this->recap['categories'] as $cat) {
                    // Category row
                    $sheet->fromArray([[
                        $cat['no'],
                        $cat['category_code'],
                        strtoupper($cat['category_name']),
                        $cat['subtotal_amount'],
                        $cat['subtotal_transfer'],
                        $cat['subtotal_realization'],
                        $cat['subtotal_saldo'],
                    ]], null, "A{$row}");
                    $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true], 'fill' => self::FILL_CAT, 'border' => Border::BORDER_THIN]);
                    $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                    $row++;

                    foreach ($cat['subcategories'] as $sub) {
                        // Sub-category row
                        $sheet->fromArray([[
                            '',
                            $sub['subcategory_code'],
                            '  ' . $sub['subcategory_name'],
                            $sub['subtotal_amount'],
                            $sub['subtotal_transfer'],
                            $sub['subtotal_realization'],
                            $sub['subtotal_saldo'],
                        ]], null, "A{$row}");
                        $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true, 'italic' => true], 'fill' => self::FILL_SUB, 'border' => Border::BORDER_THIN]);
                        $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                        $row++;

                        foreach ($sub['items'] as $item) {
                            $sheet->fromArray([[
                                $item['no'],
                                $item['account_number'] ?? '',
                                '    ' . $item['item_name'],
                                $item['amount'],
                                $item['total_transfer'],
                                $item['total_realization'],
                                $item['saldo'],
                            ]], null, "A{$row}");
                            $this->applyStyle($sheet, "A{$row}:G{$row}", ['border' => Border::BORDER_THIN]);
                            $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            $row++;
                        }
                    }
                }

                // Grand total row
                $sheet->fromArray([[
                    '', '', 'GRAND TOTAL',
                    $this->recap['grand_total_amount'],
                    $this->recap['grand_total_transfer'],
                    $this->recap['grand_total_realization'],
                    $this->recap['grand_total_saldo'],
                ]], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true, 'size' => 11], 'fill' => self::FILL_GRAND, 'border' => Border::BORDER_MEDIUM]);
                $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
            },
        ];
    }

    private function applyStyle($sheet, string $range, array $opts): void
    {
        $style = ['borders' => ['allBorders' => ['borderStyle' => $opts['border'] ?? Border::BORDER_THIN]]];

        if (isset($opts['fill'])) {
            $style['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $opts['fill']]];
        }

        $font = [];
        if (!empty($opts['font']['bold']))   $font['bold']   = true;
        if (!empty($opts['font']['italic'])) $font['italic'] = true;
        if (!empty($opts['font']['size']))   $font['size']   = $opts['font']['size'];
        if ($font) $style['font'] = $font;

        if (isset($opts['align'])) {
            $style['alignment'] = ['horizontal' => $opts['align']];
        }

        $sheet->getStyle($range)->applyFromArray($style);
    }

    private function applyNumberFormat($sheet, int $row, array $cols): void
    {
        foreach ($cols as $col) {
            $sheet->getStyle("{$col}{$row}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        }
    }
}
