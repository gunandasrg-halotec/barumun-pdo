<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\StorePettyCashVoucherRequest;
use App\Http\Requests\PettyCash\UpdatePettyCashVoucherRequest;
use App\Models\PdoHeader;
use App\Models\PettyCashVoucher;
use App\Services\PettyCash\PettyCashVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PettyCashVoucherController extends Controller
{
    public function __construct(
        private readonly PettyCashVoucherService $service,
    ) {}

    /** GET /pdo/{pdo}/petty-cash-vouchers */
    public function index(Request $request, PdoHeader $pdo): JsonResponse
    {
        $data = $this->service->listForPdo($pdo, $request->user());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** POST /pdo/{pdo}/petty-cash-vouchers */
    public function store(StorePettyCashVoucherRequest $request, PdoHeader $pdo): JsonResponse
    {
        $data = $request->validated();
        $data['pdo_header_id'] = $pdo->id;

        $voucher = $this->service->create($data, $request->user());

        return response()->json(['success' => true, 'data' => $voucher, 'message' => 'Voucher berhasil dibuat.'], 201);
    }

    /** GET /petty-cash-vouchers/{voucher} */
    public function show(Request $request, PettyCashVoucher $voucher): JsonResponse
    {
        $data = $this->service->find($voucher, $request->user());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** PUT /petty-cash-vouchers/{voucher} */
    public function update(UpdatePettyCashVoucherRequest $request, PettyCashVoucher $voucher): JsonResponse
    {
        $updated = $this->service->update($voucher, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Voucher berhasil diperbarui.']);
    }

    /** DELETE /petty-cash-vouchers/{voucher} */
    public function destroy(Request $request, PettyCashVoucher $voucher): JsonResponse
    {
        $this->service->destroy($voucher, $request->user());

        return response()->json(['success' => true, 'message' => 'Voucher berhasil dihapus.']);
    }
}
