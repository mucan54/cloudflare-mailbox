<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A Cloudflare account = a tenant. All mail data is scoped to it.
 */
class CloudflareAccount extends Model
{
    protected $fillable = [
        'name', 'slug', 'account_id', 'api_token', 'webhook_secret',
        'sending_driver', 'worker_deployed_at', 'worker_config_hash', 'last_synced_at',
    ];

    protected $hidden = ['api_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'worker_deployed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            if (empty($account->slug)) {
                $account->slug = static::uniqueSlug($account->name);
            }
            if (empty($account->webhook_secret)) {
                $account->webhook_secret = Str::random(48);
            }
        });
    }

    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function destinationAddresses(): HasMany
    {
        return $this->hasMany(DestinationAddress::class);
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    /**
     * Onboarding state machine (see docs/ARCHITECTURE.md §8.2.2).
     */
    public function isConnected(): bool
    {
        return filled($this->api_token) && filled($this->account_id);
    }

    public function isSynced(): bool
    {
        return $this->domains()->exists();
    }

    public function isWorkerDeployed(): bool
    {
        return $this->worker_deployed_at !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
