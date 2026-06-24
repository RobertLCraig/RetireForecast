<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Gdpr\GdprService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in user's GDPR controls: download everything we hold about them, or
 * erase their account entirely. Both routes are behind auth, so anonymous callers
 * cannot reach them (and anonymous use writes nothing in the first place).
 */
class AccountController extends Controller
{
    public function export(Request $request, GdprService $gdpr): StreamedResponse
    {
        $data = $gdpr->export($request->user());

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'retireforecast-export.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function destroy(Request $request, GdprService $gdpr): JsonResponse
    {
        $user = $request->user();

        if ($request->hasSession()) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $gdpr->erase($user);

        return response()->json(['status' => 'account_erased']);
    }
}
