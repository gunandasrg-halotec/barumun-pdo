<?php

namespace App\Services\Realization;

use App\Models\AuditLog;
use App\Models\RealizationAttachment;
use App\Models\RealizationEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * Upload bukti transaksi ke S3 dan simpan metadata ke DB.
     * BR-ATTACH-001: maks 10MB, format jpg/jpeg/png/pdf.
     * Path S3: realization-attachments/{year}/{month}/{uuid}.{ext}
     */
    public function store(RealizationEntry $entry, UploadedFile $file, User $actor): RealizationAttachment
    {
        $pdo = $entry->pdoDetail->pdoHeader;

        // BR-AUTH-001: Verify entry belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($actor->plantation_unit_id && $pdo->plantation_unit_id !== $actor->plantation_unit_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Bukti hanya bisa ditambah untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Tidak bisa menambah bukti setelah PDO ditutup.'],
            ], 409));
        }

        $year  = now()->year;
        $month = str_pad(now()->month, 2, '0', STR_PAD_LEFT);
        $ext   = $file->getClientOriginalExtension();
        $path  = "realization-attachments/{$year}/{$month}/" . Str::uuid() . ".{$ext}";

        // Upload ke S3 (atau disk lokal saat dev sesuai FILESYSTEM_DISK di .env)
        Storage::disk(config('filesystems.default'))->put($path, $file->getContent(), 'private');

        $attachment = RealizationAttachment::create([
            'realization_entry_id' => $entry->id,
            'uploaded_by'          => $actor->id,
            'file_name'            => $file->getClientOriginalName(),
            'file_path'            => $path,
            'mime_type'            => $file->getMimeType(),
            'file_size_bytes'      => $file->getSize(),
        ]);

        AuditLog::record(
            actor: $actor,
            entityType: 'realization_attachments',
            entityId: $attachment->id,
            action: 'INSERT',
            oldValues: null,
            newValues: $attachment->toArray()
        );

        return $attachment;
    }

    /**
     * Hapus bukti transaksi — hapus file dari S3 dan hapus record DB.
     */
    public function destroy(RealizationAttachment $attachment, User $actor): void
    {
        $pdo = $attachment->realizationEntry->pdoDetail->pdoHeader;

        // BR-AUTH-001: Verify entry belongs to user's company and unit
        if ($pdo->company_id !== $actor->company_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'COMPANY_MISMATCH', 'message' => 'Anda tidak memiliki akses ke realisasi ini.'],
            ], 403));
        }
        if ($actor->plantation_unit_id && $pdo->plantation_unit_id !== $actor->plantation_unit_id) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'UNIT_MISMATCH', 'message' => 'Bukti hanya bisa dihapus untuk PDO unit Anda sendiri.'],
            ], 403));
        }

        if ($pdo->isClosed()) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'PDO_CLOSED', 'message' => 'Tidak bisa menghapus bukti setelah PDO ditutup.'],
            ], 409));
        }

        $old = $attachment->toArray();

        Storage::disk(config('filesystems.default'))->delete($attachment->file_path);
        $attachment->delete();

        AuditLog::record(
            actor: $actor,
            entityType: 'realization_attachments',
            entityId: $attachment->id,
            action: 'DELETE',
            oldValues: $old,
            newValues: null
        );
    }

    /**
     * Generate signed URL sementara untuk preview/download file dari S3.
     * URL berlaku 15 menit.
     */
    public function temporaryUrl(RealizationAttachment $attachment): string
    {
        return Storage::disk(config('filesystems.default'))
            ->temporaryUrl($attachment->file_path, now()->addMinutes(15));
    }
}
