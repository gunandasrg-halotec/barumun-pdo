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

                // ── Meta header ──────────────────────────────────────────
                $meta = [
                    ['No. PDO',  $pdo->pdo_number ?? '—'],
                    ['Unit',     $pdo->plantationUnit?->name ?? '—'],
                    ['Periode',  $this->formatPeriod($pdo->period_month, $pdo->period_year)],
                    ['Status',   strtoupper($pdo->status ?? '—')],
                    ['Catatan',  $pdo->notes ?? '—'],
                ];
                foreach ($meta as [$label, $value]) {
                    $sheet->setCellValue("A{$row}", $label);
                    $sheet->setCellValue("B{$row}", $value);
                    $sheet->mergeCells("B{$row}:I{$row}");
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                    $row++;
                }
                $row++;

                // ── Column headings ──────────────────────────────────────
                $headings = ['No.', 'Kode Akun', 'Kategori / Item Biaya', 'Deskripsi', 'Vol', 'Satuan', 'Rate', 'Jumlah', 'Transfer', 'Realisasi', 'Saldo'];
                $lastCol  = 'K';
                $sheet->fromArray([$headings], null, "A{$row}");
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['argb' => 'FF000000']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9EAD3']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                $row++;

                $no = 1;

                foreach ($cats as $catGroup) {
                    $cat        = $catGroup['category'];
                    $catLabel   = ($cat['code'] ?? '') . ' - ' . ($cat['name'] ?? 'Tanpa Kategori');
                    $catStartRow = $row;

                    foreach ($catGroup['subcategories'] as $subGroup) {
                        $sub      = $subGroup['subcategory'];
                        $subLabel = ($sub['code'] ?? '') . ' - ' . ($sub['name'] ?? 'Tanpa Sub-Kategori');
                        $subStartRow = $row;

                        foreach ($subGroup['details'] as $detail) {
                            $item    = $detail->expenseItem;
                            $saldo   = ($detail->amount ?? 0) - ($detail->total_transfer ?? 0) - ($detail->total_realization ?? 0);

                            $sheet->fromArray([[
                                $no++,
                                $detail->account_number ?? ($item?->default_account_number ?? '—'),
                                $item?->name ?? $detail->description ?? '—',
                                $detail->description ?? '—',
                                $detail->quantity,
                                $detail->unit ?? '—',
                                $detail->rate ?? 0,
                                $detail->amount ?? 0,
                                $detail->total_transfer ?? 0,
                                $detail->total_realization ?? 0,
                                $saldo,
                            ]], null, "A{$row}");

                            // Number formats
                            foreach (['G', 'H', 'I', 'J', 'K'] as $col) {
                                $sheet->getStyle("{$col}{$row}")->getNumberFormat()
                                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                            }
                            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()
                                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                            $row++;
                        }

                        // Subtotal sub-kategori
                        $sheet->fromArray([[
                            '', '', "Subtotal {$subLabel}", '', '', '', '',
                            $subGroup['subtotal_amount'] ?? 0,
                            '', '', '',
                        ]], null, "A{$row}");
                        $sheet->mergeCells("C{$row}:G{$row}");
                        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                            'font' => ['bold' => true, 'italic' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF7ED']],
                            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        ]);
                        $sheet->getStyle("H{$row}")->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                        $row++;
                    }

                    // Subtotal kategori
                    $sheet->fromArray([[
                        '', '', "Total {$catLabel}", '', '', '', '',
                        $catGroup['subtotal_amount'] ?? 0,
                        '', '', '',
                    ]], null, "A{$row}");
                    $sheet->mergeCells("C{$row}:G{$row}");
                    $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9EAD3']],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    $sheet->getStyle("H{$row}")->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    $row++;
                }

                // Grand total
                $sheet->fromArray([[
                    '', '', 'TOTAL PENGAJUAN', '', '', '', '',
                    $grand, '', '', '',
                ]], null, "A{$row}");
                $sheet->mergeCells("C{$row}:G{$row}");
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF93C47D']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                ]);
                $sheet->getStyle("H{$row}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

                // Column widths
                $sheet->getColumnDimension('A')->setWidth(6);
                $sheet->getColumnDimension('B')->setWidth(16);
                $sheet->getColumnDimension('C')->setWidth(30);
                $sheet->getColumnDimension('D')->setWidth(34);
                $sheet->getColumnDimension('E')->setWidth(8);
                $sheet->getColumnDimension('F')->setWidth(10);
            },
        ];
    }

    private function formatPeriod(int $month, int $year): string
    {
        $names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return ($names[$month] ?? $month) . ' ' . $year;
    }
}
