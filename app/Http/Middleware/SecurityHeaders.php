<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 * structural vectors (default-src, object-src, base-uri, form-action, frame-ancestors).
 *
 * The Filament `/admin` panel is out of scope by design: it runs its own middleware
 * stack (not the `web` group), manages its own asset loading, and is admin-only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('security.enabled', true) !== true) {
            return $response;
        }

        foreach ((array) config('security.headers', []) as $name => $value) {
            if ($value !== null && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $policy = $this->contentSecurityPolicy();

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
     */
    private function contentSecurityPolicy(): string
    {
        $parts = [];

        foreach ((array) config('security.csp', []) as $directive => $sources) {
            if ($sources === null || $sources === '') {
                continue;
            }

            $parts[] = trim($directive.' '.$sources);
        }

        return implode('; ', $parts);
    }
}
