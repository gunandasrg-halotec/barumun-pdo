<?php

namespace Tests\Feature\PDO;

use App\Exports\PdoExport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PdoExportTest extends TestCase
{
    public function test_pdo_export_writes_formulas_for_summary_rows_in_amount_column(): void
    {
        $export = new PdoExport([
            'pdo' => (object) [
                'pdo_number' => 'PDO-001',
                'plantationUnit' => (object) ['name' => 'Unit A'],
                'period_month' => 6,
                'period_year' => 2026,
                'status' => 'final',
                'notes' => 'Catatan',
            ],
            'categories' => [
                [
                    'category' => ['code' => 'CAT1', 'name' => 'Kategori 1'],
                    'subtotal_amount' => 450,
                    'subcategories' => [
                        [
                            'subcategory' => ['code' => 'SUB1', 'name' => 'Sub 1'],
                            'subtotal_amount' => 300,
                            'details' => [
                                $this->detail('ITEM-1', 'Item 1', 100),
                                $this->detail('ITEM-2', 'Item 2', 200),
                            ],
                        ],
                        [
                            'subcategory' => ['code' => 'SUB2', 'name' => 'Sub 2'],
                            'subtotal_amount' => 150,
                            'details' => [
                                $this->detail('ITEM-3', 'Item 3', 150),
                            ],
                        ],
                    ],
                ],
            ],
            'grand_total' => 450,
        ]);

        $binary = Excel::raw($export, ExcelFormat::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'pdo-export-').'.xlsx';
        file_put_contents($path, $binary);

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('=SUM(H10:H11)', $sheet->getCell('H12')->getValue());
        $this->assertSame('=SUM(H14:H14)', $sheet->getCell('H15')->getValue());
        $this->assertSame('=SUM(H10:H11,H14:H14)', $sheet->getCell('H16')->getValue());
        $this->assertSame('=SUM(H10:H11,H14:H14)', $sheet->getCell('H17')->getValue());
        $this->assertSame(300.0, $sheet->getCell('H12')->getCalculatedValue());
        $this->assertSame(150.0, $sheet->getCell('H15')->getCalculatedValue());
        $this->assertSame(450.0, $sheet->getCell('H16')->getCalculatedValue());
        $this->assertSame(450.0, $sheet->getCell('H17')->getCalculatedValue());

        @unlink($path);
    }

    private function detail(string $account, string $name, int $amount): object
    {
        return (object) [
            'account_number' => $account,
            'description' => $name,
            'quantity' => 1,
            'unit' => 'PCS',
            'rate' => $amount,
            'amount' => $amount,
            'total_transfer' => 0,
            'total_realization' => 0,
            'expenseItem' => (object) [
                'name' => $name,
                'default_account_number' => $account,
                'is_deduction' => false,
            ],
        ];
    }
}
