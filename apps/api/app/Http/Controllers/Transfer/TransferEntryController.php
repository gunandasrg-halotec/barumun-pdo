<?php

namespace App\Http\Controllers\Transfer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\StoreTransferEntryRequest;
use App\Http\Requests\Transfer\UpdateTransferEntryRequest;
use App\Models\PdoDetail;
use App\Models\PdoHeader;
use App\Models\TransferEntry;
use App\Services\Transfer\TransferEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferEntryController extends Controller
{
    public function __construct(private readonly TransferEntryService $service) {}

    /** GET /transfer-entries?unit_ids[]= — semua transfer dalam perusahaan */
    public function all(Request $request): JsonResponse
    {
        $user    = $request->user();
        $filters = [];

        // unit_ids filter only applies to HO users (those without a fixed plantation unit)
        if (!$user->plantation_unit_id && $request->has('unit_ids')) {
            $filters['unit_ids'] = array_filter((array) $request->input('unit_ids'));
        }

        $entries = $this->service->listAll($user, $filters);

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /** GET /pdo-details/{detail}/transfers */
    public function index(PdoDetail $detail): JsonResponse
    {
        $entries = $this->service->listByDetail($detail);

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /** POST /pdo-details/{detail}/transfers */
    public function store(StoreTransferEntryRequest $request, PdoDetail $detail): JsonResponse
    {
        $entry = $this->service->store($detail, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $entry, 'message' => 'Transfer berhasil dicatat.'], 201);
    }

    /** PUT /transfer-entries/{entry} */
    public function update(UpdateTransferEntryRequest $request, TransferEntry $entry): JsonResponse
    {
        $updated = $this->service->update($entry, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Transfer berhasil diperbarui.']);
    }

    /** GET /pdo/{pdo}/transfers — summary semua detail dalam satu PDO */
    public function summaryByPdo(PdoHeader $pdo): JsonResponse
    {
        $details = $this->service->summaryByPdo($pdo);

        return response()->json([
            'success' => true,
            'data'    => [
                'pdo_number'       => $pdo->pdo_number,
                'period_month'     => $pdo->period_month,
                'period_year'      => $pdo->period_year,
                'plantation_unit'  => $pdo->plantationUnit?->only(['id', 'code', 'name']),
                'details'          => $details,
            ],
        ]);
    }

    /**
     * POST /pdo/{pdo}/transfers/bulk — catat transfer untuk banyak item sekaligus
     * Body: { entries: [{ pdo_detail_id, amount, transfer_date, reference_number?, notes? }] }
     */
    public function storeBulk(Request $request, PdoHeader $pdo): JsonResponse
    {
        $request->validate([
            // Boleh kosong: sinkronisasi form → draft (entri kosong = hapus semua draft).
            'entries'                            => ['present', 'array'],
            'entries.*.pdo_detail_id'            => ['required', 'uuid'],
            'entries.*.amount'                   => ['required', 'integer', 'min:1'],
            'entries.*.transfer_date'            => ['required', 'date'],
            'entries.*.reference_number'         => ['nullable', 'string', 'max:100'],
            'entries.*.notes'                    => ['nullable', 'string'],
            'entries.*.transfer_destination'     => ['nullable', 'string', 'in:rek_kebun,pribadi,vendor'],
        ]);

        if (! $request->user()?->canRecordTransfer()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berhak mencatat transfer dana.']], 403));
        }

        $results = $this->service->storeBulk($pdo, $request->input('entries'), $request->user());

        return response()->json(['success' => true, 'data' => $results, 'message' => 'Draft transfer berhasil disimpan.'], 201);
    }

    /**
     * PUT /pdo/{pdo}/transfers/drafts/{entry} — ubah entri draft.
     */
    public function updateDraft(Request $request, PdoHeader $pdo, string $entry): JsonResponse
    {
        $validated = $request->validate([
            'amount'               => ['sometimes', 'integer', 'min:1'],
            'transfer_date'        => ['sometimes', 'date'],
            'reference_number'     => ['nullable', 'string', 'max:100'],
            'notes'                => ['nullable', 'string'],
            'transfer_destination' => ['sometimes', 'string', 'in:rek_kebun,pribadi,vendor'],
        ]);

        if (! $request->user()?->canRecordTransfer()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berhak mengubah transfer dana.']], 403));
        }

        $updated = $this->service->updateDraft($pdo, $entry, $validated, $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Draft transfer berhasil diperbarui.']);
    }

    /**
     * DELETE /pdo/{pdo}/transfers/drafts/{entry} — hapus entri draft.
     */
    public function deleteDraft(Request $request, PdoHeader $pdo, string $entry): JsonResponse
    {
        if (! $request->user()?->canRecordTransfer()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Anda tidak berhak menghapus transfer dana.']], 403));
        }

        $this->service->deleteDraft($pdo, $entry, $request->user());

        return response()->json(['success' => true, 'message' => 'Draft transfer berhasil dihapus.']);
    }

    /**
     * POST /pdo/{pdo}/transfers/commit — simpan permanen semua draft PDO.
     */
    public function commitDrafts(Request $request, PdoHeader $pdo): JsonResponse
    {
        $request->validate([
            // Map pdo_detail_id (item potongan) → tujuan transfer pilihan user.
            'deduction_destinations'   => ['sometimes', 'array'],
            'deduction_destinations.*' => ['string', 'in:rek_kebun,pribadi,vendor'],
        ]);

        if (! $request->user()?->canCommitTransfer()) {
            abort(response()->json(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Hanya Direktur Keuangan yang dapat menyimpan permanen rencana transfer dana.']], 403));
        }

        $count = $this->service->commitDrafts($pdo, $request->user(), $request->input('deduction_destinations', []));

        return response()->json(['success' => true, 'data' => ['committed' => $count], 'message' => "{$count} transfer berhasil disimpan permanen."]);
    }

    /** GET /transfer-entries/pdo-summary — list PDO final dengan ringkasan transfer */
    public function pdoSummaryList(Request $request): JsonResponse
    {
        $data = $this->service->pdoSummaryList($request->user());

        return response()->json(['success' => true, 'data' => $data]);
    }
}
