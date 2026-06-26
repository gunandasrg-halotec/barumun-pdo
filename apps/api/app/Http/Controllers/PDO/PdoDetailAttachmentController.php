<?php

namespace App\Http\Controllers\PDO;

use App\Http\Controllers\Controller;
use App\Models\PdoDetail;
use App\Models\PdoDetailAttachment;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdoDetailAttachmentController extends Controller
{
    private const ALLOWED_MIMES = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_SIZE_MB = 10;

    public function index(PdoDetail $detail): JsonResponse
    {
        $attachments = $detail->attachments()->with('uploader:id,full_name')->latest()->get()
            ->map(fn ($a) => [
                'id'                => $a->id,
                'original_filename' => $a->original_filename,
                'mime_type'         => $a->mime_type,
                'file_size'         => $a->file_size,
                'uploaded_by'       => $a->uploader?->full_name,
                'created_at'        => $a->created_at,
                'download_url'      => route('pdo-detail-attachments.download', $a),
            ]);

        return response()->json(['success' => true, 'data' => $attachments]);
    }

    public function store(Request $request, PdoDetail $detail): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasAnyRole([Role::KERANI])) {
            return response()->json(['success' => false, 'message' => 'Hanya Kerani yang dapat mengunggah lampiran.'], 403);
        }

        $pdo = $detail->pdoHeader;
        if (! $pdo?->isDraft()) {
            return response()->json(['success' => false, 'message' => 'Lampiran hanya dapat diunggah saat PDO berstatus draft.'], 422);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . (self::MAX_SIZE_MB * 1024),
                'mimetypes:' . implode(',', self::ALLOWED_MIMES),
            ],
        ], [
            'file.mimetypes' => 'Format file tidak didukung. Gunakan Excel, Word, PDF, atau gambar (JPG/PNG).',
            'file.max'       => 'Ukuran file maksimal ' . self::MAX_SIZE_MB . ' MB.',
        ]);

        $file      = $request->file('file');
        $disk      = config('filesystems.default', 's3');
        $path      = 'pdo-attachments/' . $detail->pdo_header_id . '/' . $detail->id . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();

        Storage::disk($disk)->put($path, file_get_contents($file), 'private');

        $attachment = PdoDetailAttachment::create([
            'pdo_detail_id'     => $detail->id,
            'uploaded_by'       => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'disk_path'         => $path,
            'disk'              => $disk,
            'mime_type'         => $file->getMimeType(),
            'file_size'         => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'mime_type'         => $attachment->mime_type,
                'file_size'         => $attachment->file_size,
                'uploaded_by'       => $user->full_name,
                'created_at'        => $attachment->created_at,
                'download_url'      => route('pdo-detail-attachments.download', $attachment),
            ],
            'message' => 'Lampiran berhasil diunggah.',
        ], 201);
    }

    public function download(PdoDetailAttachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->download(
            $attachment->disk_path,
            $attachment->original_filename
        );
    }

    public function destroy(Request $request, PdoDetailAttachment $attachment): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasAnyRole([Role::KERANI])) {
            return response()->json(['success' => false, 'message' => 'Hanya Kerani yang dapat menghapus lampiran.'], 403);
        }

        $pdo = $attachment->pdoDetail->pdoHeader;
        if (! $pdo?->isDraft()) {
            return response()->json(['success' => false, 'message' => 'Lampiran hanya dapat dihapus saat PDO berstatus draft.'], 422);
        }

        Storage::disk($attachment->disk)->delete($attachment->disk_path);
        $attachment->delete();

        return response()->json(['success' => true, 'message' => 'Lampiran berhasil dihapus.']);
    }
}
