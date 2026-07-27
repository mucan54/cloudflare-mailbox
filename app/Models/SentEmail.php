<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SentEmail extends Model
{
    protected $fillable = [
        'cloudflare_account_id', 'domain_id', 'mailbox_id', 'driver',
        'from_email', 'to', 'cc', 'bcc', 'reply_to', 'subject',
        'message_id', 'in_reply_to', 'references',
        'html_body', 'text_body', 'status', 'cf_response', 'error',
        'in_reply_to_email_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'references' => 'array',
            'cf_response' => 'array',
            'sent_at' => 'datetime',
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
}
