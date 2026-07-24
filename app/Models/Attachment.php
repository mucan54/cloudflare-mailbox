<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type', 'attachable_id', 'filename', 'mime_type',
        'size', 'storage_disk', 'storage_path', 'content_id', 'inline',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'inline' => 'boolean',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A temporary signed URL for private downloads, when the disk supports it.
     */
    public function temporaryUrl(int $minutes = 5): ?string
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return null;
        }

        $disk = Storage::disk($this->storage_disk);

        try {
            return $disk->temporaryUrl($this->storage_path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            // Local disk etc. — fall back to a route-based download.
            return null;
        }
    }
}
