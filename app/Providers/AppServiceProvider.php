<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The advice-style interpretation capability: admin-granted per user, off by
        // default. The walled-off App\Compliance\Interpretation layer is reachable only
        // through this ability. See DECISIONS 2026-06-25.
        Gate::define('interpret', fn (User $user): bool => $user->can_interpret);
    }
}
