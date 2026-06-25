<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The first-run guidance-only acknowledgement. The user must accept it once before
 * reaching any forecast; the acceptance time is recorded against their account so it
 * is auditable and not asked again.
 */
class DisclaimerController extends Controller
{
    public function show(Request $request): RedirectResponse|View
    {
        if ($request->user()->hasAcknowledgedDisclaimer()) {
            return redirect()->route('dashboard');
        }

        return view('disclaimer');
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasAcknowledgedDisclaimer()) {
            $user->forceFill(['disclaimer_acknowledged_at' => now()])->save();
        }

        return redirect()->intended(route('dashboard'));
    }
}
