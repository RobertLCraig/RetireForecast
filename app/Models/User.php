<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Local-first single-user app: any authenticated user may reach the admin
     * panel. Tighten this (e.g. an is_admin flag) before any public release.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** True once the user has accepted the first-run guidance-only disclaimer. */
    public function hasAcknowledgedDisclaimer(): bool
    {
        return $this->disclaimer_acknowledged_at !== null;
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class);
    }

    public function simulationRuns(): HasMany
    {
        return $this->hasMany(SimulationRun::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disclaimer_acknowledged_at' => 'datetime',
            'can_interpret' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
