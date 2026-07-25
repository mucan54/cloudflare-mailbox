<?php

namespace App\Models;

use App\Jobs\ProvisionMailClientDns;
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

    protected static function booted(): void
    {
        // Optionally auto-create the mail-client DNS records when a domain is
        // added (only when the native-mail feature is enabled).
        static::created(function (Domain $domain): void {
            if (config('cloudflare.mail_client.enabled') && config('cloudflare.mail_client.auto_dns')) {
                ProvisionMailClientDns::dispatch($domain->id);
            }
        });
    }

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
