<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
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
    use RefreshDatabase;

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

        // Self-hosted Vite bundle + Bunny fonts. Inline scripts are admitted by nonce only;
        // 'unsafe-eval' is the documented residual (Livewire 4 bundles Alpine, which
        // evaluates expressions through the Function constructor).
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+' 'unsafe-eval'/", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    public function test_script_src_no_longer_carries_a_blanket_inline_allowance(): void
    {
        // The public-release bar (board card 0012): inline scripts run because they carry
        // this request's nonce, not because anything inline is allowed. Browsers ignore
        // 'unsafe-inline' once a nonce is present, so its absence must be asserted, not assumed.
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $scriptSrc = $this->directive($csp, 'script-src');

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString('*', $scriptSrc);
    }

    public function test_each_request_gets_its_own_nonce(): void
    {
        $first = $this->nonceFrom($this->get('/'));
        $second = $this->nonceFrom($this->get('/'));

        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second, 'A reused nonce is no better than no nonce at all.');
    }

    public function test_every_script_tag_rendered_carries_the_requests_nonce(): void
    {
        // The failure mode a nonce policy invites is a page that no longer runs: one script
        // tag emitted without the nonce is silently blocked in a real browser. Assert over the
        // actual HTML of a Livewire page, which is where the runtime + inline init script are
        // injected, so a vendor helper that stops stamping the nonce goes red here.
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $response->assertOk();

        $nonce = $this->nonceFrom($response);
        $this->assertNotSame('', $nonce);

        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $matches);

        $this->assertNotEmpty(
            $matches[0],
            'No script tags rendered, so this check proves nothing; it must run on a page that has some.',
        );

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString(
                'nonce="'.$nonce.'"',
                $tag,
                "A script tag has no nonce and would be blocked by the CSP: {$tag}",
            );
        }
    }

    public function test_the_vite_helper_is_handed_the_same_nonce_as_the_header(): void
    {
        // The bundle tags are stamped by Laravel's Vite helper (and Livewire reads the nonce
        // back off it). Tests run with Vite neutralised, so assert the handover itself rather
        // than the tags it would have produced from a gitignored build.
        $seen = null;
        Route::middleware('web')->get('/__csp-nonce-probe', function () use (&$seen) {
            $seen = Vite::cspNonce();

            return 'ok';
        });

        $response = $this->get('/__csp-nonce-probe');

        $this->assertSame($this->nonceFrom($response), $seen);
    }

    public function test_the_header_matches_the_configured_policy_exactly(): void
    {
        $response = $this->get('/');
        $nonce = $this->nonceFrom($response);

        $expected = collect(config('security.csp'))
            ->reject(fn ($sources) => $sources === null || $sources === '')
            ->map(fn ($sources, $directive) => trim($directive.' '.str_replace("'nonce'", "'nonce-{$nonce}'", (string) $sources)))
            ->implode('; ');

        $response->assertHeader('Content-Security-Policy', $expected);
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

    /** The nonce the CSP header actually issued for this response ('' if there is none). */
    private function nonceFrom(TestResponse $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');

        return preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m) === 1 ? $m[1] : '';
    }

    /** The source list of one directive, so an assertion cannot be satisfied by a neighbour. */
    private function directive(string $csp, string $directive): string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if (str_starts_with($part, $directive.' ')) {
                return $part;
            }
        }

        return '';
    }

    private function assertHeaderMissing(TestResponse $response, string $header): void
    {
        $this->assertFalse(
            $response->headers->has($header),
            "Expected the [{$header}] header to be absent, but it was present.",
        );
    }
}
