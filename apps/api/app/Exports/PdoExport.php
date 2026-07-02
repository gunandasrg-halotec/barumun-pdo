<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PdoExport implements WithEvents, ShouldAutoSize
{
    // Hex fills (ARGB)
    private const FILL_HEADER     = 'FFD9EAD3'; // column headings
    private const FILL_KATEGORI   = 'FFB6D7A8'; // kategori row
    private const FILL_SUB        = 'FFD9EAD3'; // sub-kategori row
    private const FILL_SUBTOT_SUB = 'FFEFF7ED'; // subtotal sub
    private const FILL_SUBTOT_CAT = 'FFD9EAD3'; // total kategori
    private const FILL_GRAND      = 'FF93C47D'; // grand total

    private const COLS = 11; // A..K
    private const LAST = 'K';

    public function __construct(private array $data) {}

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $pdo   = $this->data['pdo'];
                $cats  = $this->data['categories'];
                $grand = $this->data['grand_total'];

                $row = 1;

                // ── Meta block ───────────────────────────────────────────
                foreach ([
                    ['No. PDO',  $pdo->pdo_number ?? '—'],
                    ['Unit',     $pdo->plantationUnit?->name ?? '—'],
                    ['Periode',  $this->formatPeriod($pdo->period_month, $pdo->period_year)],
                    ['Status',   strtoupper($pdo->status ?? '—')],
                    ['Catatan',  $pdo->notes ?? '—'],
                ] as [$label, $value]) {
                    $sheet->setCellValue("A{$row}", $label);
                    $sheet->setCellValue("B{$row}", $value);
                    $sheet->mergeCells("B{$row}:" . self::LAST . "{$row}");
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                    $row++;
                }
                $row++;

                // ── Column headings ──────────────────────────────────────
                $sheet->fromArray([[
                    'No.', 'Kode Akun', 'Kategori / Item Biaya', 'Deskripsi',
                    'Vol', 'Satuan', 'Rate', 'Jumlah', 'Transfer', 'Realisasi', 'Saldo',
                ]], null, "A{$row}");
                $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                    'font'      => ['bold' => true],
                    'fill'      => self::FILL_HEADER,
                    'align'     => Alignment::HORIZONTAL_CENTER,
                    'border'    => Border::BORDER_THIN,
                ]);
                $row++;

                $no = 1;

                foreach ($cats as $catGroup) {
                    $cat      = $catGroup['category'];
                    $catLabel = trim(($cat['code'] ?? '') . ' — ' . ($cat['name'] ?? 'Tanpa Kategori'));

                    // ── Kategori row ────────────────────────────────────
                    $sheet->setCellValue("A{$row}", $catLabel);
                    $sheet->mergeCells("A{$row}:" . self::LAST . "{$row}");
                    $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                        'font'   => ['bold' => true],
                        'fill'   => self::FILL_KATEGORI,
                        'border' => Border::BORDER_THIN,
                    ]);
                    $row++;

                    foreach ($catGroup['subcategories'] as $subGroup) {
                        $sub      = $subGroup['subcategory'];
                        $subLabel = trim(($sub['code'] ?? '') . ' — ' . ($sub['name'] ?? 'Tanpa Sub-Kategori'));

                        // ── Sub-Kategori row ────────────────────────────
                        $sheet->setCellValue("A{$row}", '   ' . $subLabel);
                        $sheet->mergeCells("A{$row}:" . self::LAST . "{$row}");
                        $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                            'font'   => ['bold' => true, 'italic' => true],
                            'fill'   => self::FILL_SUB,
                            'border' => Border::BORDER_THIN,
                        ]);
                        $row++;

                        // ── Item rows ────────────────────────────────────
                        foreach ($subGroup['details'] as $detail) {
                            $item  = $detail->expenseItem;
                            // Item potongan (is_deduction) → Jumlah signed (minus), mengurangi total.
                            $signedAmount = ($item?->is_deduction ?? false)
                                ? -($detail->amount ?? 0)
                                : ($detail->amount ?? 0);
                            $saldo = $signedAmount
                                   - ($detail->total_transfer ?? 0)
                                   - ($detail->total_realization ?? 0);

                            $sheet->fromArray([[
                                $no++,
                                $detail->account_number ?? ($item?->default_account_number ?? '—'),
                                $item?->name ?? $detail->description ?? '—',
                                $detail->description ?? '—',
                                $detail->quantity,
                                $detail->unit ?? '—',
                                $detail->rate ?? 0,
                                $signedAmount,
                                $detail->total_transfer ?? 0,
                                $detail->total_realization ?? 0,
                                $saldo,
                            ]], null, "A{$row}");

                            $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                                'border' => Border::BORDER_THIN,
                            ]);
                            $this->applyNumberFormat($sheet, $row, ['G', 'H', 'I', 'J', 'K']);
                            $sheet->getStyle("A{$row}")->getAlignment()
                                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            $row++;
                        }

                        // ── Subtotal sub-kategori ────────────────────────
                        $sheet->setCellValue("A{$row}", '');
                        $sheet->setCellValue("C{$row}", '      Subtotal ' . $subLabel);
                        $sheet->mergeCells("A{$row}:G{$row}");
                        $sheet->setCellValue("H{$row}", $subGroup['subtotal_amount'] ?? 0);
                        $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                            'font'   => ['bold' => true, 'italic' => true],
                            'fill'   => self::FILL_SUBTOT_SUB,
                            'border' => Border::BORDER_THIN,
                        ]);
                        $sheet->getStyle("H{$row}")->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                        $row++;
                    }

                    // ── Total kategori ───────────────────────────────────
                    $sheet->mergeCells("A{$row}:G{$row}");
                    $sheet->setCellValue("A{$row}", 'Total ' . $catLabel);
                    $sheet->setCellValue("H{$row}", $catGroup['subtotal_amount'] ?? 0);
                    $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                        'font'   => ['bold' => true],
                        'fill'   => self::FILL_SUBTOT_CAT,
                        'border' => Border::BORDER_THIN,
                    ]);
                    $sheet->getStyle("H{$row}")->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    $row++;
                }

                // ── Grand total ──────────────────────────────────────────
                $sheet->mergeCells("A{$row}:G{$row}");
                $sheet->setCellValue("A{$row}", 'Total Pengajuan');
                $sheet->setCellValue("H{$row}", $grand);
                $this->applyStyle($sheet, "A{$row}:" . self::LAST . "{$row}", [
                    'font'   => ['bold' => true, 'size' => 12],
                    'fill'   => self::FILL_GRAND,
                    'border' => Border::BORDER_MEDIUM,
                ]);
                $sheet->getStyle("H{$row}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

                // ── Fixed column widths ──────────────────────────────────
                foreach (['A' => 6, 'B' => 16, 'C' => 32, 'D' => 36,
                          'E' => 7, 'F' => 10, 'G' => 16, 'H' => 18,
                          'I' => 16, 'J' => 16, 'K' => 16] as $col => $width) {
                    $sheet->getColumnDimension($col)->setWidth($width);
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

    private function formatPeriod(int $month, int $year): string
    {
        $names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return ($names[$month] ?? $month) . ' ' . $year;
    }
}
