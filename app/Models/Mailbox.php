<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * A single mail address a person can log into (mailbox portal).
 * Globally unique by email; tenant-independent login. Authenticates the
 * headless mailbox API via Sanctum tokens (web SPA + mobile).
 */
class Mailbox extends Authenticatable implements AuthenticatableContract
{
    use HasApiTokens, HasPushSubscriptions, Notifiable;

    protected $fillable = [
        'cloudflare_account_id', 'domain_id', 'email', 'display_name',
        'password', 'login_enabled', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'login_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Resolve the owning domain from the email address when not set.
        static::saving(function (self $mailbox) {
            if (! $mailbox->domain_id && $mailbox->email && str_contains($mailbox->email, '@')) {
                $host = substr(strrchr($mailbox->email, '@'), 1);
                $mailbox->domain_id = Domain::query()
                    ->where('cloudflare_account_id', $mailbox->cloudflare_account_id)
                    ->where('name', $host)
                    ->value('id');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudflareAccount::class, 'cloudflare_account_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }
}
