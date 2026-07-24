<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CashBookDirectExport implements WithEvents, ShouldAutoSize
{
    private const FILL_HEADER = 'FFD9EAD3';
    private const FILL_SALDO  = 'FFB6D7A8';

    public function __construct(
        private array   $cashBook,
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

                $monthName = ['', 'Januari','Februari','Maret','April','Mei','Juni',
                              'Juli','Agustus','September','Oktober','November','Desember'][$this->month] ?? $this->month;

                foreach ([
                    ['Unit Kebun', $this->unit ? "{$this->unit->code} — {$this->unit->name}" : '—'],
                    ['Periode',    "{$monthName} {$this->year}"],
                ] as [$label, $value]) {
                    $sheet->setCellValue("A{$row}", $label);
                    $sheet->setCellValue("B{$row}", $value);
                    $sheet->mergeCells("B{$row}:E{$row}");
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                    $row++;
                }
                $row++;

                // Saldo awal
                $sheet->fromArray([['Saldo Awal', '', '', '', $this->cashBook['opening_balance']]], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:E{$row}", ['font' => ['bold' => true], 'fill' => self::FILL_SALDO, 'border' => Border::BORDER_THIN]);
                $this->applyNumberFormat($sheet, $row, ['E']);
                $row++;
                $row++;

                // Column headings
                $sheet->fromArray([['Tanggal', 'No. Ref', 'Uraian', 'Penerimaan', 'Pengeluaran', 'Saldo']], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:F{$row}", ['font' => ['bold' => true], 'fill' => self::FILL_HEADER, 'border' => Border::BORDER_THIN, 'align' => Alignment::HORIZONTAL_CENTER]);
                $row++;

                foreach ($this->cashBook['rows'] as $r) {
                    $sheet->fromArray([[
                        $r['date'],
                        $r['reference'] ?? '',
                        $r['description'],
                        $r['type'] === 'penerimaan'  ? $r['amount'] : null,
                        $r['type'] === 'pengeluaran' ? $r['amount'] : null,
                        $r['balance'],
                    ]], null, "A{$row}");
                    $this->applyStyle($sheet, "A{$row}:F{$row}", ['border' => Border::BORDER_THIN]);
                    $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F']);
                    $row++;
                }

                // Grand total row
                $sheet->fromArray([[
                    '', '', 'TOTAL',
                    $this->cashBook['total_penerimaan'],
                    $this->cashBook['total_pengeluaran'],
                    $this->cashBook['closing_balance'],
                ]], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:F{$row}", ['font' => ['bold' => true, 'size' => 11], 'fill' => self::FILL_SALDO, 'border' => Border::BORDER_MEDIUM]);
                $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F']);
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
        if (!empty($opts['font']['bold'])) $font['bold'] = true;
        if (!empty($opts['font']['size'])) $font['size'] = $opts['font']['size'];
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
