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
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Only users flagged is_admin may reach the Filament admin panel. This is the
     * privilege boundary the advice-style `interpret` grant sits behind (can_interpret
     * is set from the panel's Users resource), so it must stay the tighter gate.
     * Bootstrap the first admin with `php artisan user:make-admin {email}`.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
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
            'two_factor_confirmed_at' => 'datetime',
            'can_interpret' => 'boolean',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
