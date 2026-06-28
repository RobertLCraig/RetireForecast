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
    | script-src and style-src keep 'unsafe-inline'/'unsafe-eval' because the current
    | front-end stack needs them: Livewire injects an inline init script, Alpine
    | evaluates its expressions via the Function constructor, and ApexCharts injects
    | inline styles. Tightening these to nonce-based (dropping 'unsafe-inline' and
    | 'unsafe-eval') requires Alpine's CSP build and a real-browser verification pass,
    | and is tracked as the residual go-live item. The structural directives below
    | (default-src, object-src, base-uri, form-action, frame-ancestors) are the high
    | value protections and do not depend on how inline scripts are handled.
    |
    | Set a directive to null to omit it.
    */

    'csp' => [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
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
