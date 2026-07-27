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

                // Pass 1: tulis semua baris dengan NILAI apa adanya (seperti sebelumnya),
                // sambil merekam baris kategori/subkategori/item supaya bisa ditimpa
                // dengan FORMULA pada pass 2 — perlu 2 pass karena baris kategori/
                // subkategori ditulis SEBELUM baris item-item di bawahnya, jadi rentang
                // barisnya baru diketahui setelah semua item selesai ditulis.
                $categoryRows    = []; // catIdx => row number
                $subcatRowsByCat = []; // catIdx => [subcat row numbers]
                $subcatItemRange = []; // subcat row number => [startItemRow, endItemRow]

                foreach ($this->recap['categories'] as $catIdx => $cat) {
                    $catRow = $row;
                    $categoryRows[$catIdx] = $catRow;
                    $subcatRowsByCat[$catIdx] = [];

                    // Category row (strictNullComparison=true: fromArray's default loose
                    // comparison treats 0 == null as equal and SKIPS writing zero values —
                    // must use strict comparison so legitimate 0 amounts are written)
                    $sheet->fromArray([[
                        $cat['no'],
                        $cat['category_code'],
                        strtoupper($cat['category_name']),
                        $cat['subtotal_amount'],
                        $cat['subtotal_transfer'],
                        $cat['subtotal_realization'],
                        $cat['subtotal_saldo'],
                    ]], null, "A{$row}", true);
                    $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true], 'fill' => self::FILL_CAT, 'border' => Border::BORDER_THIN]);
                    $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                    $row++;

                    foreach ($cat['subcategories'] as $sub) {
                        $subRow = $row;
                        $subcatRowsByCat[$catIdx][] = $subRow;

                        // Sub-category row
                        $sheet->fromArray([[
                            '',
                            $sub['subcategory_code'],
                            '  ' . $sub['subcategory_name'],
                            $sub['subtotal_amount'],
                            $sub['subtotal_transfer'],
                            $sub['subtotal_realization'],
                            $sub['subtotal_saldo'],
                        ]], null, "A{$row}", true);
                        $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true, 'italic' => true], 'fill' => self::FILL_SUB, 'border' => Border::BORDER_THIN]);
                        $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                        $row++;

                        $itemStartRow = $row;

                        foreach ($sub['items'] as $item) {
                            $sheet->fromArray([[
                                $item['no'],
                                $item['account_number'] ?? '',
                                '    ' . $item['item_name'],
                                $item['amount'],
                                $item['total_transfer'],
                                $item['total_realization'],
                                $item['saldo'],
                            ]], null, "A{$row}", true);
                            $this->applyStyle($sheet, "A{$row}:G{$row}", ['border' => Border::BORDER_THIN]);
                            $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);
                            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                            // Saldo per item = formula Transfer-Realisasi, KECUALI item
                            // potongan (is_deduction): saldonya sengaja tetap nilai statis
                            // 0 (bukan formula), karena potongan tidak pernah direalisasi
                            // secara terpisah — lihat RecapQueryService::buildHierarchy().
                            if (empty($item['is_deduction'])) {
                                $sheet->setCellValue("G{$row}", "=E{$row}-F{$row}");
                            }

                            $row++;
                        }

                        $itemEndRow = $row - 1;
                        if ($itemEndRow >= $itemStartRow) {
                            $subcatItemRange[$subRow] = [$itemStartRow, $itemEndRow];
                        }
                    }
                }

                $grandRow = $row;

                // Grand total row
                $sheet->fromArray([[
                    '', '', 'GRAND TOTAL',
                    $this->recap['grand_total_amount'],
                    $this->recap['grand_total_transfer'],
                    $this->recap['grand_total_realization'],
                    $this->recap['grand_total_saldo'],
                ]], null, "A{$row}", true);
                $this->applyStyle($sheet, "A{$row}:G{$row}", ['font' => ['bold' => true, 'size' => 11], 'fill' => self::FILL_GRAND, 'border' => Border::BORDER_MEDIUM]);
                $this->applyNumberFormat($sheet, $row, ['D', 'E', 'F', 'G']);

                // Pass 2: timpa Pengajuan/Total Transfer/Total Realisasi kategori &
                // subkategori dengan formula SUM, dan Saldo dengan formula
                // Transfer-Realisasi (bukan SUM kolom Saldo) — supaya subtotal tetap
                // konsisten dengan KPI walau ada baris item potongan yang Saldo-nya
                // sengaja ditampilkan 0 (lihat catatan di pass 1).
                foreach ($subcatItemRange as $subRow => [$startRow, $endRow]) {
                    foreach (['D', 'E', 'F'] as $col) {
                        $sheet->setCellValue("{$col}{$subRow}", "=SUM({$col}{$startRow}:{$col}{$endRow})");
                    }
                    $sheet->setCellValue("G{$subRow}", "=E{$subRow}-F{$subRow}");
                }

                foreach ($subcatRowsByCat as $catIdx => $subRows) {
                    $catRow = $categoryRows[$catIdx];
                    if (empty($subRows)) {
                        continue;
                    }
                    foreach (['D', 'E', 'F'] as $col) {
                        $refs = implode(',', array_map(fn ($r) => "{$col}{$r}", $subRows));
                        $sheet->setCellValue("{$col}{$catRow}", "=SUM({$refs})");
                    }
                    $sheet->setCellValue("G{$catRow}", "=E{$catRow}-F{$catRow}");
                }

                if (!empty($categoryRows)) {
                    foreach (['D', 'E', 'F'] as $col) {
                        $refs = implode(',', array_map(fn ($r) => "{$col}{$r}", $categoryRows));
                        $sheet->setCellValue("{$col}{$grandRow}", "=SUM({$refs})");
                    }
                    $sheet->setCellValue("G{$grandRow}", "=E{$grandRow}-F{$grandRow}");
                }
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
