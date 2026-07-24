<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    protected $fillable = [
        'cloudflare_account_id', 'zone_id', 'name', 'status',
        'routing_enabled', 'sending_enabled', 'inbound_capture',
        'dns_records', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'routing_enabled' => 'boolean',
            'sending_enabled' => 'boolean',
            'dns_records' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudflareAccount::class, 'cloudflare_account_id');
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }
}
