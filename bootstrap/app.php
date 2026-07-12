<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Set the CSP + hardening headers on every web-group response (landing,
        // auth screens, the Livewire forecast UI). Appended so it runs last and
        // wraps the outgoing response. Filament `/admin` uses its own stack.
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // Trust the loopback reverse proxy (Tailscale Serve → app over 127.0.0.1) so the
        // forwarded scheme is honoured and Laravel sees the request as HTTPS. Only the
        // proto/port/for headers are trusted — NOT X-Forwarded-Host — so the public host
        // cannot be spoofed; the external host is pinned via APP_EXTERNAL_URL instead.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
