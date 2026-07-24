<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Email extends Model
{
    protected $fillable = [
        'cloudflare_account_id', 'domain_id', 'mailbox_id', 'ingest_key',
        'message_id', 'in_reply_to', 'references', 'from_name', 'from_email',
        'to_email', 'cc', 'subject', 'text_body', 'html_body', 'headers',
        'raw_size', 'read_at', 'starred', 'folder', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'cc' => 'array',
            'headers' => 'array',
            'read_at' => 'datetime',
            'starred' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudflareAccount::class, 'cloudflare_account_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
