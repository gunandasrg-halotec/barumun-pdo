<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Models\PettyCashVoucher;
use App\Services\PettyCash\PettyCashVoucherService;
use App\Support\Terbilang;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PettyCashVoucherPrintController extends Controller
{
    public function __construct(
        private readonly PettyCashVoucherService $service,
    ) {}

    /** GET /petty-cash-vouchers/{voucher}/print */
    public function print(Request $request, PettyCashVoucher $voucher): Response
    {
        $this->service->authorizeAccess($voucher, $request->user());

        $voucher->load([
            'lines.pdoDetail.expenseItem',
            'pdoHeader.plantationUnit.company',
        ]);

        $pdo     = $voucher->pdoHeader;
        $unit    = $pdo->plantationUnit;
        $company = $unit->company;

        $pdf = Pdf::loadView('pdf.petty_cash_voucher', [
            'voucher'   => $voucher,
            'company'   => $company,
            'unit'      => $unit,
            'pdoNumber' => $pdo->pdo_number,
            'total'     => $voucher->total_amount,
            'terbilang' => Terbilang::rupiah($voucher->total_amount),
            'rows'      => $voucher->lines,
        ])->setPaper('a4', 'portrait');

        $filename = 'PCV-' . str_replace('/', '-', $voucher->voucher_number) . '.pdf';

        return $pdf->download($filename);
    }
}
