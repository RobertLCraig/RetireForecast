<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScenarioStatus;
use App\Forecast\QuickWhatIf;
use App\Models\Scenario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creates a one-click what-if from a preset ({@see QuickWhatIf}) — a delta-child of the
 * base, just like a hand-built what-if, then opens its results. A preset that would change
 * nothing for the household creates nothing and says so, rather than leaving an empty
 * what-if behind (no silent no-op).
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

        $child = new Scenario;
        $child->user_id = auth()->id();
        $child->parent_scenario_id = $base->id;
        $child->setRelation('parent', $base);
        $child->overrides = ['name' => $this->uniqueName($base, $built['name'])] + $built['overrides'];
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return redirect()->route('scenarios.results', $child)
            ->with('status', 'What-if created. Run it to see how it compares.');
    }

    /** Keep repeated quick what-ifs distinct: "Retire 2 years later", then "… (2)", "… (3)". */
    private function uniqueName(Scenario $base, string $name): string
    {
        $existing = $base->children()->pluck('name')->all();
        if (! in_array($name, $existing, true)) {
            return $name;
        }

        $n = 2;
        while (in_array("{$name} ({$n})", $existing, true)) {
            $n++;
        }

        return "{$name} ({$n})";
    }
}
