<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Cloudflare accounts (tenants) this user belongs to.
     */
    public function cloudflareAccounts(): BelongsToMany
    {
        return $this->belongsToMany(CloudflareAccount::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    // --- Filament panel / tenancy contracts -------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return array<Model>|Collection
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->cloudflareAccounts;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->cloudflareAccounts()
            ->whereKey($tenant->getKey())
            ->exists();
    }
}
