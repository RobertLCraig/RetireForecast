<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScenarioStatus;
use App\Forecast\BuyVsRentCompare;
use App\Models\Scenario;
use Illuminate\Http\RedirectResponse;

/**
 * One-click "compare buy vs rent": generates the alternative housing strategies for a base
 * as delta-child what-ifs ({@see BuyVsRentCompare}) and opens Compare, so the strategies are
 * read side by side as deliberate plans. Generating a strategy that already has its
 * what-if is skipped, so repeated clicks don't pile up duplicates (no silent no-op — if
 * everything already exists it simply opens Compare).
 */
class BuyVsRentController extends Controller
{
    public function store(Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // Always operate on the base of the family, even when launched from a child.
        $base = $scenario->baseScenario();
        $existing = $this->existingStrategyChildren($base);

        foreach (BuyVsRentCompare::children($base) as $built) {
            if (isset($existing[$built['variant']])) {
                continue; // this strategy already has its generated what-if
            }

            $child = new Scenario;
            $child->user_id = auth()->id();
            $child->parent_scenario_id = $base->id;
            $child->setRelation('parent', $base);
            $child->overrides = ['name' => $built['name']] + $built['overrides'];
            $child->builder_state = [];
            $child->status = ScenarioStatus::Ready;
            $child->projectFrom($child->effectiveBuilderState());
            $child->save();
        }

        return redirect()->route('scenarios.compare', $base)
            ->with('status', 'Buy-vs-rent options ready. Run each to compare the full range of futures.');
    }

    /**
     * The base's existing generated strategy children, keyed by variant — a child whose
     * override is the variant alone (besides its name), so a hand-built what-if that merely
     * also changed the variant is not mistaken for one of these.
     *
     * @return array<string, true>
     */
    private function existingStrategyChildren(Scenario $base): array
    {
        $found = [];
        foreach ($base->children()->get() as $child) {
            $keys = array_values(array_diff(array_keys($child->overrides ?? []), ['name']));
            if ($keys === ['variant']) {
                $found[$child->variant->value] = true;
            }
        }

        return $found;
    }
}
