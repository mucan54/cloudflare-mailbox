<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = ['mailbox_id', 'source_uid', 'title', 'location', 'starts_at', 'ends_at', 'all_day', 'notes', 'color', 'reminded_at', 'dav_uri'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean', 'reminded_at' => 'datetime'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
