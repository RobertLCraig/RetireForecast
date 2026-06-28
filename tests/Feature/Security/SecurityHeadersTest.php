<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The go-live security headers: a Content-Security-Policy plus a small set of
 * hardening headers on the public `web` surface (App\Http\Middleware\SecurityHeaders).
 *
 * The CSP value is asserted against the same config the middleware reads, so the test
 * tracks the one definition rather than a hand-copied duplicate that could drift.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_web_responses_carry_the_content_security_policy(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');
        $this->assertHeaderMissing($response, 'Content-Security-Policy-Report-Only');
    }

    public function test_csp_locks_down_the_structural_vectors(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        // The high-value directives that do not depend on how inline scripts are handled.
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_csp_allows_the_current_self_hosted_stack(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        // Self-hosted Vite bundle + Bunny fonts; Livewire/Alpine/ApexCharts need the
        // inline + eval relaxations until the nonce-based tightening lands.
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    public function test_the_header_matches_the_configured_policy_exactly(): void
    {
        $expected = collect(config('security.csp'))
            ->reject(fn ($sources) => $sources === null || $sources === '')
            ->map(fn ($sources, $directive) => trim($directive.' '.$sources))
            ->implode('; ');

        $this->get('/')->assertHeader('Content-Security-Policy', $expected);
    }

    public function test_static_hardening_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }

    public function test_the_auth_screens_are_covered_too(): void
    {
        // Fortify registers its routes in the `web` group, so the login screen gets
        // the same protection as the rest of the app.
        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_csp_can_be_sent_report_only_for_a_staged_rollout(): void
    {
        config(['security.csp_report_only' => true]);

        $response = $this->get('/');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertHeaderMissing($response, 'Content-Security-Policy');
    }

    public function test_the_headers_can_be_disabled(): void
    {
        config(['security.enabled' => false]);

        $response = $this->get('/');

        $this->assertHeaderMissing($response, 'Content-Security-Policy');
        $this->assertHeaderMissing($response, 'X-Content-Type-Options');
    }

    private function assertHeaderMissing(TestResponse $response, string $header): void
    {
        $this->assertFalse(
            $response->headers->has($header),
            "Expected the [{$header}] header to be absent, but it was present.",
        );
    }
}
