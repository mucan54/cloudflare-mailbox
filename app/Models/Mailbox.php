<?php

namespace App\Models;

use App\Support\AvatarImage;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'cloudflare_account_id', 'domain_id', 'email', 'display_name', 'signature',
        'password', 'login_enabled', 'last_login_at', 'calendar_token',
        'avatar_path', 'avatar_in_signature',
    ];

    protected $hidden = ['password', 'remember_token', 'calendar_token', 'avatar_token'];

    /** The disk profile photos are stored on. */
    public static function avatarDisk(): string
    {
        return config('cloudflare.avatars_disk') ?: config('cloudflare.attachments_disk', 'local');
    }

    /**
     * Public URL of the profile photo, or null when none is set. The token part
     * is stable for the mailbox (links in already-sent mail keep working); the
     * ?v= part changes with every new photo so browsers fetch the new one.
     *
     * Built from APP_URL, not the current request's host: the same URL goes
     * into outgoing signatures, where it must be the public address even if
     * the user happens to browse the portal via an internal host or IP.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path || ! $this->avatar_token) {
            return null;
        }

        $base = rtrim((string) config('app.url'), '/') ?: url('/');

        return $base.'/avatars/'.$this->avatar_token.'?v='.substr(md5($this->avatar_path), 0, 10);
    }

    /**
     * Store a new profile photo (normalized to a small square JPEG), replacing
     * the current one.
     *
     * @throws \InvalidArgumentException when the bytes aren't a usable image
     */
    public function setAvatar(string $binary): void
    {
        [$bytes, $ext] = AvatarImage::normalize($binary);

        $path = 'avatars/'.$this->id.'/'.Str::random(20).'.'.$ext;
        if (! Storage::disk(self::avatarDisk())->put($path, $bytes)) {
            throw new \RuntimeException('Could not store the profile photo.');
        }

        $this->update(['avatar_path' => $path]); // the updated hook removes the old file
    }

    public function removeAvatar(): void
    {
        $this->update(['avatar_path' => null, 'avatar_in_signature' => false]);
    }

    /** Get (creating if needed) the secret token for this mailbox's .ics feed. */
    public function calendarToken(): string
    {
        if (! $this->calendar_token) {
            $this->forceFill(['calendar_token' => Str::random(48)])->save();
        }

        return $this->calendar_token;
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'login_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'avatar_in_signature' => 'boolean',
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

            // Every photo gets a public token the first time one is set.
            if ($mailbox->avatar_path && ! $mailbox->avatar_token) {
                $mailbox->avatar_token = Str::random(40);
            }
        });

        // Replacing or removing the photo (portal API or admin panel) deletes
        // the old file; deleting the mailbox deletes its photo.
        static::updated(function (self $mailbox) {
            $old = $mailbox->getOriginal('avatar_path');
            if ($mailbox->wasChanged('avatar_path') && $old) {
                Storage::disk(self::avatarDisk())->delete($old);
            }
        });
        static::deleted(function (self $mailbox) {
            if ($mailbox->avatar_path) {
                Storage::disk(self::avatarDisk())->delete($mailbox->avatar_path);
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

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
