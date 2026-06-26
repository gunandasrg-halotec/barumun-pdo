<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PdoDetailAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'pdo_detail_id',
        'uploaded_by',
        'original_filename',
        'disk_path',
        'disk',
        'mime_type',
        'file_size',
    ];

    public function pdoDetail(): BelongsTo
    {
        return $this->belongsTo(PdoDetail::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function temporaryUrl(int $minutes = 15): string
    {
        if ($this->disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->disk_path, now()->addMinutes($minutes));
        }
        return Storage::disk($this->disk)->url($this->disk_path);
    }
}
