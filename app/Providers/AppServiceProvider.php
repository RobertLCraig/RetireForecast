<?php

namespace App\Providers;

use App\Forecast\ScenarioForecaster;
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
        // ONE forecaster per request, so everything a screen assembles shares what it derived
        // rather than decrypting, merging and projecting the same scenario over again. `scoped`
        // rather than `singleton` is the point: the queue worker drops scoped instances between
        // jobs, so a long-lived worker never carries one scenario's figures into the next job.
        // The memo lives on the instance ({@see ScenarioForecaster}); nothing is cached beyond
        // the request.
        $this->app->scoped(ScenarioForecaster::class);
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
        // and proxies to the app over loopback HTTP, preserving the original *.ts.net Host
        // header (verified 2026-07-16). Pin absolute URLs/redirects to the external https
        // origin ONLY for requests that actually arrive under that host, so a login does not
        // bounce to an unreachable host — while local dev at retireforecast.test keeps its
        // own URLs even when sharing is live. Spoof-safe without trusting X-Forwarded-Host:
        // a forged Host merely opts in to the legitimately configured origin; no
        // request-supplied value is ever used. Paired with the loopback trustProxies in
        // bootstrap/app.php (which supplies the HTTPS scheme from the proxy).
        // (In console — artisan, queue workers, tests — the bound request's host is
        // localhost, which never matches a *.ts.net name, so the pin stays off there.)
        $externalUrl = config('app.external_url');
        if ($externalUrl && $this->app['request']->getHost() === parse_url($externalUrl, PHP_URL_HOST)) {
            URL::forceRootUrl($externalUrl);
            URL::forceScheme('https');
        }
    }
}
