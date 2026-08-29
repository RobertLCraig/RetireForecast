<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forecast\QuickWhatIf;
use App\Forecast\WhatIfWriter;
use App\Models\Scenario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creates a one-click what-if from a preset ({@see QuickWhatIf}) — a delta-child of the
 * base, just like a hand-built what-if, then opens its results. A preset that would change
 * nothing for the household creates nothing and says so, rather than leaving an empty
 * what-if behind (no silent no-op). The child itself is written by the shared
 * {@see WhatIfWriter}, the one place a what-if is persisted.
 */
class QuickWhatIfController extends Controller
{
    public function store(Request $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // Quick what-ifs are always children of the base, even when launched from a child.
        $base = $scenario->baseScenario();
        $built = QuickWhatIf::build($base, (string) $request->input('preset'));

        if ($built === null) {
            return back()->with('status', 'That quick what-if would not change anything for this household.');
        }

        $child = WhatIfWriter::create($base, $built['name'], $built['overrides']);

        return redirect()->route('scenarios.results', $child)
            ->with('status', 'What-if created. Run it to see how it compares.');
    }
}
