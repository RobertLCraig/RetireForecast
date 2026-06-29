<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfChanges;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;

/**
 * Compares a base plan with its delta-child what-ifs side by side (Phase C2). Each
 * plan is run through the deterministic central projection — so the comparison shows
 * immediately, no Monte Carlo run needed — and the figures are laid out in one
 * accessible table: housing choice, whether essentials are covered every year, whether
 * the money lasts, and the usable / total wealth left.
 *
 * Nothing here ranks the what-ifs or names a "best" one: the figures are shown per
 * plan and the reader draws their own conclusion (guidance only).
 */
#[Layout('components.layouts.app')]
class ScenarioCompare extends Component
{
    public Scenario $base;

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // Compare is base-centric: opening it on a what-if compares its base's family.
        $this->base = $scenario->isChild() ? $scenario->parent : $scenario;
    }

    public function render(): View
    {
        $forecaster = app(ScenarioForecaster::class);

        // One deterministic projection per plan, reused for both the summary table and the
        // wealth-over-time burndown overlay (so the chart can't drift from the table).
        $forecasts = $this->plans()->map(fn (Scenario $plan): array => [
            'scenario' => $plan,
            'forecast' => $forecaster->deterministic($plan),
        ]);

        $plans = $forecasts->map(fn (array $pf): array => $this->summarise($pf['scenario'], $pf['forecast']));

        $burndown = ResultPresenter::burndown(
            $forecasts->map(fn (array $pf): array => ['name' => $pf['scenario']->name, 'forecast' => $pf['forecast']])->all(),
        );

        return view('livewire.scenario-compare', [
            'base' => $this->base,
            'plans' => $plans,
            'burndown' => $burndown,
        ])->title('Compare what-ifs');
    }

    /** The base plan first, then its ready what-if children. */
    private function plans(): Collection
    {
        return collect([$this->base])->concat(
            $this->base->children()->where('status', ScenarioStatus::Ready)->latest()->get(),
        );
    }

    /**
     * One plan's deterministic headline figures, framed neutrally (no ranking).
     *
     * @return array<string, mixed>
     */
    private function summarise(Scenario $plan, ForecastResult $forecast): array
    {
        return [
            'name' => $plan->name,
            'isBase' => ! $plan->isChild(),
            // What this what-if changed from the base (empty for the base itself), so the
            // comparison says not just how each plan turns out but what makes it different.
            'changes' => WhatIfChanges::of($plan),
            'variant' => ResultPresenter::variantLabel($plan->variant),
            'essentialsMet' => $forecast->essentialsAlwaysMet,
            'fullSpendMet' => $forecast->fullSpendAlwaysMet,
            'moneyLasts' => $forecast->depletionCalendarYear === null,
            'depletionYear' => $forecast->depletionCalendarYear,
            'finalYear' => $forecast->finalCalendarYear,
            'usableWealth' => $forecast->terminalUsableWealth->format(),
            'totalWealth' => $forecast->terminalTotalWealth->format(),
            'orphans' => $plan->orphanedOverrides(),
            'editUrl' => route('scenarios.edit', $plan),
            'resultsUrl' => route('scenarios.results', $plan),
        ];
    }
}
