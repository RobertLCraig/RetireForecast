<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The Tailscale-Serve external-origin pin (AppServiceProvider::boot).
 *
 * When APP_EXTERNAL_URL is set, absolute URLs are pinned to that https origin ONLY
 * for requests that actually arrive under its host (Tailscale Serve preserves the
 * original *.ts.net Host header when proxying to loopback). Local requests at
 * retireforecast.test must keep their own URLs even while family sharing is live —
 * a global pin broke local asset loading (found 2026-07-16).
 *
 * The provider boots during test setUp under a console request (host localhost),
 * so each case rebinds the incoming request and re-runs boot() — the same order
 * as the real HTTP lifecycle, where the request is captured before providers boot.
 */
class ExternalUrlPinTest extends TestCase
{
    private const EXTERNAL_URL = 'https://example-machine.tail1234.ts.net';

    private function bootForRequest(string $incomingUrl): void
    {
        $this->app->instance('request', Request::create($incomingUrl));
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_requests_under_the_external_host_get_pinned_urls(): void
    {
        config(['app.external_url' => self::EXTERNAL_URL]);
        $this->bootForRequest(self::EXTERNAL_URL.'/login');

        $this->assertSame(self::EXTERNAL_URL.'/dashboard', url('/dashboard'));
    }

    public function test_local_requests_keep_local_urls_while_sharing_is_live(): void
    {
        config(['app.external_url' => self::EXTERNAL_URL]);
        $this->bootForRequest('http://retireforecast.test/');

        $this->assertSame('http://retireforecast.test/dashboard', url('/dashboard'));
    }

    public function test_blank_external_url_pins_nothing(): void
    {
        config(['app.external_url' => null]);
        $this->bootForRequest('http://retireforecast.test/');

        $this->assertSame('http://retireforecast.test/dashboard', url('/dashboard'));
    }
}
