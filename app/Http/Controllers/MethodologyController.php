<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The public methodology page: how RetireForecast computes its figures, rendered from the single
 * source `docs/spec/METHODOLOGY.md`. That one document is BOTH this page AND part of the local
 * assistant's methodology corpus (config `assistant.methodology_docs`), so "how does it model X?"
 * has one home — the page a reader can open and the passage the assistant retrieves are the same words.
 *
 * Education/guidance content (no user data), so it is public and outside the disclaimer gate.
 */
class MethodologyController extends Controller
{
    public function show(): View
    {
        $path = base_path('docs/spec/METHODOLOGY.md');
        abort_unless(is_file($path), 404);

        // Render is pure function of the file; cache it keyed by the file's mtime so an edited doc
        // re-renders without a manual clear, but a hot page costs no markdown parse.
        $html = Cache::rememberForever('methodology.html.'.filemtime($path), static fn (): string => Str::markdown((string) file_get_contents($path)));

        return view('methodology', ['html' => $html]);
    }
}
