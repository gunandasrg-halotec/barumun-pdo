<?php

namespace App\Http\Controllers\Realization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Realization\StoreAttachmentRequest;
use App\Models\RealizationAttachment;
use App\Models\RealizationEntry;
use App\Services\Realization\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealizationAttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $service) {}

    /** POST /realization-entries/{entry}/attachments */
    public function store(StoreAttachmentRequest $request, RealizationEntry $entry): JsonResponse
    {
        $attachment = $this->service->store($entry, $request->file('file'), $request->user());

        // Tambahkan signed URL sementara ke response agar frontend bisa langsung preview
        $data             = $attachment->toArray();
        $data['temp_url'] = $this->service->temporaryUrl($attachment);

        return response()->json(['success' => true, 'data' => $data, 'message' => 'Bukti transaksi berhasil diunggah.'], 201);
    }

    /** DELETE /realization-entries/{entry}/attachments/{attachment} */
    public function destroy(Request $request, RealizationEntry $entry, RealizationAttachment $attachment): JsonResponse
    {
        // Pastikan attachment memang milik entry ini
        if ($attachment->realization_entry_id !== $entry->id) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'NOT_FOUND', 'message' => 'Bukti tidak ditemukan dalam entri ini.'],
            ], 404);
        }

        $this->service->destroy($attachment, $request->user());

        return response()->json(['success' => true, 'message' => 'Bukti transaksi berhasil dihapus.']);
    }
}
