<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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

        // Remote family access over a private Tailscale tailnet: Tailscale terminates TLS
        // and proxies to the app over loopback HTTP under the *.ts.net hostname. Pin every
        // absolute URL/redirect to that external https origin so a login does not bounce to
        // an unreachable host. Inert until APP_EXTERNAL_URL is set — local dev at
        // retireforecast.test is untouched. Paired with the loopback trustProxies in
        // bootstrap/app.php (which supplies the HTTPS scheme from the proxy).
        if ($externalUrl = config('app.external_url')) {
            URL::forceRootUrl($externalUrl);
            URL::forceScheme('https');
        }
    }
}
