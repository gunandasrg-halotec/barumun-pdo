<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\StoreVoucherScanRequest;
use App\Models\PettyCashVoucher;
use App\Services\PettyCash\PettyCashVoucherPostingService;
use App\Services\PettyCash\PettyCashVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PettyCashVoucherScanController extends Controller
{
    public function __construct(
        private readonly PettyCashVoucherPostingService $postingService,
        private readonly PettyCashVoucherService $voucherService,
    ) {}

    /** POST /petty-cash-vouchers/{voucher}/scan */
    public function store(StoreVoucherScanRequest $request, PettyCashVoucher $voucher): JsonResponse
    {
        $voucher = $this->postingService->postWithScan($voucher, $request->file('file'), $request->user());

        $proofNumbers = $voucher->lines->pluck('realizationEntry.proof_number')->filter()->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'voucher'             => $voucher,
                'realization_entries' => $voucher->lines->pluck('realizationEntry')->filter()->values(),
            ],
            'message' => "Scan berhasil diunggah. {$proofNumbers->count()} realisasi dibuat.",
        ], 201);
    }

    /**
     * GET /petty-cash-vouchers/{voucher}/scan — tampil INLINE di tab baru (bukan
     * dipaksa unduh), lihat §3g pada plan. Endpoint sama dipakai baik dari halaman
     * daftar voucher maupun chip keterlacakan di Rekap/Buku Kas Harian.
     */
    public function download(Request $request, PettyCashVoucher $voucher): StreamedResponse
    {
        $this->voucherService->authorizeAccess($voucher, $request->user());

        if (! $voucher->hasScan()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'VOUCHER_SCAN_NOT_FOUND', 'message' => 'Voucher ini belum punya scan.'],
            ], 404));
        }

        return Storage::disk($voucher->scan_disk)->response($voucher->scan_file_path, $voucher->scan_file_name);
    }
}
