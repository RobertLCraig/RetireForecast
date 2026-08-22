<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Security response headers
    |--------------------------------------------------------------------------
    |
    | Master switch for the App\Http\Middleware\SecurityHeaders middleware, which
    | sets a Content-Security-Policy plus a small set of hardening headers on every
    | response in the `web` group (the public landing, the auth screens and the
    | Livewire forecast UI). The Filament `/admin` panel runs its own middleware
    | stack and is deliberately out of scope here.
    |
    | The documented local flow is `npm run build` + `php artisan serve`, which the
    | CSP does not impede. If you run Vite HMR (`npm run dev`), its dev-server and
    | websocket origins are not in the policy below, so set SECURITY_HEADERS_ENABLED
    | to false while developing with HMR.
    |
    */

    'enabled' => env('SECURITY_HEADERS_ENABLED', true),

    /*
    | Send the CSP as Content-Security-Policy-Report-Only (logs violations in the
    | browser console, enforces nothing) so a rollout can be staged and confirmed in
    | a real browser before switching to enforce.
    */

    'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', false),

    /*
    | The Content-Security-Policy, one directive per key, assembled in source order.
    |
    | The literal source expression 'nonce' is a PLACEHOLDER: App\Http\Middleware\
    | SecurityHeaders replaces it with this request's 'nonce-<random>' and hands the same
    | value to Laravel's Vite helper, which Livewire also reads, so every script tag the
    | app emits (the Vite bundle, the Livewire runtime and its inline init script) carries
    | it, and an injected inline script does not. Note the browser rule: once a nonce is
    | present in script-src, 'unsafe-inline' is IGNORED, so it is gone from below rather
    | than merely redundant.
    |
    | 'unsafe-eval' STAYS on script-src, and is the residual relaxation: Livewire 4 bundles
    | Alpine, which evaluates its expressions through the Function constructor. Removing it
    | needs Alpine's CSP build, which Livewire 4 does not expose (its own wire: directives
    | compile to Alpine expressions), so it is a front-end rewrite rather than a config
    | change. style-src keeps 'unsafe-inline' because ApexCharts injects inline styles.
    |
    | The structural directives below (default-src, object-src, base-uri, form-action,
    | frame-ancestors) are the high value protections and do not depend on how inline
    | scripts are handled.
    |
    | Set a directive to null to omit it.
    */

    'csp' => [
        'default-src' => "'self'",
        'script-src' => "'self' 'nonce' 'unsafe-eval'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => "'self' data:",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
    ],

    /*
    | Static hardening headers that need no browser verification and add protection on
    | their own. Set a value to null to omit that header.
    */

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ],

];
