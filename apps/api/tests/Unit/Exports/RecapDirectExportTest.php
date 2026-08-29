<?php

namespace Tests\Unit\Exports;

use App\Exports\RecapDirectExport;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Regresi: baris Grand Total di export Excel dulu ditimpa rumus "=E−F" (transfer
 * − realisasi), sehingga Saldo-nya tidak pernah sama dengan KPI "Saldo" Kas Kebun
 * di layar walaupun RecapQueryService sudah mengirim grand_total_saldo yang benar.
 */
class RecapDirectExportTest extends TestCase
{
    /** @return array<string, mixed> */
    private function recap(int $grandTotalSaldo): array
    {
        return [
            'grand_total_amount'      => 1_000_000,
            'grand_total_transfer'    => 900_000,
            'grand_total_realization' => 750_000,
            'grand_total_saldo'       => $grandTotalSaldo,
            'categories'              => [[
                'no'                   => 1,
                'category_code'        => 'BUL',
                'category_name'        => 'Biaya Umum',
                'subtotal_amount'      => 1_000_000,
                'subtotal_transfer'    => 900_000,
                'subtotal_realization' => 750_000,
                'subtotal_saldo'       => 150_000,
                'subcategories'        => [[
                    'subcategory_code'     => 'BUL-OPR',
                    'subcategory_name'     => 'Operasional',
                    'subtotal_amount'      => 1_000_000,
                    'subtotal_transfer'    => 900_000,
                    'subtotal_realization' => 750_000,
                    'subtotal_saldo'       => 150_000,
                    'items'                => [[
                        'no'                => 1,
                        'account_number'    => '1-10001',
                        'item_name'         => 'BIAYA PERJALANAN DINAS',
                        'amount'            => 1_000_000,
                        'total_transfer'    => 900_000,
                        'total_realization' => 750_000,
                        'saldo'             => 150_000,
                    ]],
                ]],
            ]],
        ];
    }

    /** @return array{0: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, 1: string} sheet + path */
    private function render(array $recap): array
    {
        $unit = (object) ['code' => 'JM', 'name' => 'Janji Matogu'];
        $path = tempnam(sys_get_temp_dir(), 'recap') . '.xlsx';

        file_put_contents($path, Excel::raw(new RecapDirectExport($recap, $unit, 7, 2026), \Maatwebsite\Excel\Excel::XLSX));

        return [IOFactory::load($path)->getActiveSheet(), $path];
    }

    private function findRow($sheet, string $needle): ?int
    {
        foreach ($sheet->getRowIterator() as $r) {
            foreach (['A', 'C'] as $col) {
                if ((string) $sheet->getCell($col . $r->getRowIndex())->getValue() === $needle) {
                    return $r->getRowIndex();
                }
            }
        }

        return null;
    }

    public function test_grand_total_saldo_formula_includes_opening_balance_cell(): void
    {
        // grand_total_saldo 2.150.000 = saldo awal 2.000.000 + (900.000 − 750.000)
        [$sheet, $path] = $this->render($this->recap(2_150_000));

        $saldoAwalRow = $this->findRow($sheet, 'Saldo Awal Kas Kebun');
        $grandRow     = $this->findRow($sheet, 'GRAND TOTAL');

        $this->assertNotNull($saldoAwalRow, 'Baris "Saldo Awal Kas Kebun" harus ada di blok meta.');
        $this->assertNotNull($grandRow);

        $this->assertEquals(2_000_000, $sheet->getCell("B{$saldoAwalRow}")->getValue());
        $this->assertSame(
            "=B{$saldoAwalRow}+E{$grandRow}-F{$grandRow}",
            $sheet->getCell("G{$grandRow}")->getValue(),
            'Saldo Grand Total wajib merujuk sel saldo awal, bukan sekadar E−F.'
        );

        @unlink($path);
    }

    /**
     * Saat filter kantong = Pribadi/Vendor, RecapQueryService tidak menyertakan saldo
     * awal di grand_total_saldo — export harus ikut nol, bukan mengambil angka lain.
     */
    public function test_opening_balance_cell_is_zero_when_service_excludes_it(): void
    {
        // grand_total_saldo = 900.000 − 750.000, tanpa tambahan saldo awal.
        [$sheet, $path] = $this->render($this->recap(150_000));

        $saldoAwalRow = $this->findRow($sheet, 'Saldo Awal Kas Kebun');

        $this->assertNotNull($saldoAwalRow);
        $this->assertEquals(0, $sheet->getCell("B{$saldoAwalRow}")->getValue());

        @unlink($path);
    }
}
