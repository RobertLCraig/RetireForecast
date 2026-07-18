<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DecisionSupport\CombinationComparisonData;
use App\Forecast\AffordabilityAssessment;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * "What you can afford" — the plain-English screen for a reader who wants the answer, not the
 * workings. It runs the base plan and each of its delta-child what-ifs through the deterministic
 * central projection (so it shows immediately, no Monte Carlo run needed), asks one question of
 * each — do the essentials stay paid for life? — and leads with the plans that pass.
 *
 * The verdict is the expected path; a plan's stored full Monte Carlo success ("how sure") is
 * shown beside it whenever a run exists ({@see AffordabilityAssessment}). The one directive
 * "the plan to lean towards" sentence is added only behind the `interpret` ability (on in
 * personal-use mode), like {@see ScenarioCompare}, so the guidance-only partition holds otherwise.
 */
#[Layout('components.layouts.app')]
class Affordability extends Component
{
    public Scenario $base;

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // Base-centric, like Compare: opening it on a what-if assesses its base's whole family.
        $this->base = $scenario->isChild() ? $scenario->parent : $scenario;
    }

    /**
     * Queue a full (10,000-path) Monte Carlo run for every plan that has no fresh result yet — the
     * honest "how sure" figure. The runs execute on the worker in the background, so this hands off
     * to the Compare page, which already shows the batch's live progress + cancel (it picks up any
     * in-flight family run on mount). Reuses the same {@see SimulationRunner} + one surface for
     * progress rather than duplicating the polling machinery here.
     */
    public function checkHowSure(): void
    {
        $runner = app(SimulationRunner::class);
        foreach ($this->plans() as $plan) {
            if ($this->storedMonteCarlo($plan, $plan->variant->value) === null) {
                $runner->dispatch($plan);
            }
        }

        $this->redirectRoute('scenarios.compare', $this->base, navigate: true);
    }

    /** The plans assessed: the base plus its ready what-if children, base first (one shared home). */
    private function plans(): Collection
    {
        return CombinationComparisonData::plans($this->base);
    }

    public function render(): View
    {
        $forecaster = app(ScenarioForecaster::class);
        $baseYear = (int) substr($this->base->base_tax_year, 0, 4);

        $rows = [];
        $anyUnchecked = false;
        foreach ($this->plans() as $plan) {
            $variant = $plan->variant->value;
            $mc = $this->storedMonteCarlo($plan, $variant);
            $anyUnchecked = $anyUnchecked || $mc === null;

            $rows[] = [
                'scenario' => $plan,
                'variant' => $variant,
                'forecast' => $forecaster->deterministicVariants($plan)[$variant],
                // The "if significant care is needed" companion, on the same path (A2), so the verdict
                // is never "lasts for life" against a silently care-free projection.
                'careStress' => $forecaster->deterministicCareStressVariants($plan)[$variant],
                'household' => $plan->toHousehold(),
                'baseYear' => $baseYear,
                'monthlyRent' => $this->monthlyRent($plan, $variant),
                'mc' => $mc,
            ];
        }

        $cards = AffordabilityAssessment::cards($rows);
        $bottomLine = AffordabilityAssessment::bottomLine($cards);

        return view('livewire.affordability', [
            'working' => array_values(array_filter($cards, fn (array $c): bool => $c['works'])),
            'failing' => array_values(array_filter($cards, fn (array $c): bool => ! $c['works'])),
            'bottomLine' => $bottomLine,
            // Whether any plan still lacks a full Monte Carlo result, so the view can offer to run it.
            'anyUnchecked' => $anyUnchecked,
            // Directive guidance only behind the walled-off ability (personal-use mode), never public.
            'canInterpret' => Gate::allows('interpret'),
        ])->title('What you can afford');
    }

    /** The monthly rent a sell-and-rent plan pays, for the card label; null for any other plan. */
    private function monthlyRent(Scenario $plan, string $variant): ?int
    {
        if ($variant !== 'rent') {
            return null;
        }
        $annual = $plan->effectiveBuilderState()['housing']['annualRent'] ?? null;

        return $annual === null || $annual === '' ? null : (int) round(((float) $annual) / 12);
    }

    /**
     * The plan's own-variant result from its latest completed full Monte Carlo run, if any — the
     * honest "how sure" figure. Null when no run has been computed for this plan yet (the new
     * what-ifs), so the view offers to run one rather than implying certainty.
     */
    private function storedMonteCarlo(Scenario $plan, string $variant): ?SimulationResult
    {
        $result = $plan->latestCompletedRun()?->results
            ->first(fn (Result $r): bool => $r->variant->value === $variant);

        return $result?->simulationResult();
    }
}
