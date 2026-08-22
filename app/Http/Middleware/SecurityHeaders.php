<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets a Content-Security-Policy plus a small set of hardening headers on every
 * response in the `web` group: the public landing, the Fortify auth screens and the
 * Livewire forecast UI.
 *
 * The policy and toggles live in config/security.php (one home for the directives, so
 * the test asserts against the same definition the middleware reads). The CSP is
 * compatible-by-construction with the current self-hosted stack (Vite bundle, Bunny
 * self-hosted fonts, Livewire/Alpine, ApexCharts), while still locking down the
 * structural vectors (default-src, object-src, base-uri, form-action, frame-ancestors)
 * and admitting inline scripts only by this request's nonce.
 *
 * The Filament `/admin` panel is out of scope by design: it runs its own middleware
 * stack (not the `web` group), manages its own asset loading, and is admin-only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.enabled', true) !== true) {
            return $next($request);
        }

        // Mint this request's nonce BEFORE the view renders, so the tags that go out
        // carry the same value the header will allow. Laravel's Vite helper stamps the
        // bundle tags with it and Livewire reads it back off the same helper for its
        // runtime + inline init script, so one call covers every script the app emits.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        foreach ((array) config('security.headers', []) as $name => $value) {
            if ($value !== null && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $policy = $this->contentSecurityPolicy($nonce);

        if ($policy !== '') {
            $header = config('security.csp_report_only', false) === true
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($header, $policy);
        }

        return $response;
    }

    /**
     * Assemble the configured CSP directives into a single header value, in source
     * order, skipping any directive set to null/empty.
     *
     * The literal source expression `'nonce'` in config is a placeholder for this
     * request's nonce; it is not valid CSP on its own, so a directive that forgets to
     * carry it simply gets no nonce rather than silently allowing anything.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        $parts = [];

        foreach ((array) config('security.csp', []) as $directive => $sources) {
            if ($sources === null || $sources === '') {
                continue;
            }

            $parts[] = trim($directive.' '.str_replace("'nonce'", "'nonce-{$nonce}'", (string) $sources));
        }

        return implode('; ', $parts);
    }
}
