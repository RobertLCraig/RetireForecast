<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the forecast pages behind a one-time acceptance of the guidance-only
 * disclaimer. A signed-in user who has not yet acknowledged it is sent to the
 * acknowledgement screen first, so nobody reaches a result without having seen the
 * "guidance, not advice" framing. GDPR/account routes are deliberately left outside
 * this gate (data-subject rights are not withheld pending acknowledgement).
 */
class EnsureDisclaimerAcknowledged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasAcknowledgedDisclaimer()) {
            return redirect()->route('disclaimer.show');
        }

        return $next($request);
    }
}
