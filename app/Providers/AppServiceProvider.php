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
        // The advice-style interpretation capability — the reach into the walled-off
        // App\Compliance\Interpretation layer. In PERSONAL-USE mode (a private, local-first
        // tool, not a public release) it is on for everyone; otherwise it stays the
        // admin-granted, off-by-default per-user `can_interpret` grant (the public
        // guidance-only posture). config/compliance.php is the single home of that regulatory
        // line — flip `personal_use` to false before any public release. See DECISIONS 2026-06-25/30.
        Gate::define('interpret', fn (User $user): bool => (bool) config('compliance.personal_use') || $user->can_interpret);
    }
}
