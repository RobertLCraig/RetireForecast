<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DecisionSupport\FrontierPresenter;
use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\DecisionSupport\ThresholdPresenter;
use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationStatus;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;

/**
 * The "How far can we go?" panel (decision-support Phase 2), nested on the results page. It
 * answers, for one lever, "how far can we move this before the money stops lasting?" for a
 * decision-maker who is not a numbers person: drag the lever and watch a plain net-position
 * line dive under the £0 floor (an INSTANT deterministic redraw — the cheap part), then ask for
 * the limit, which runs the queued Monte Carlo threshold (the slow, correct part) and paints a
 * green→red meter. The analyst can open the full sweep (S-curve + grid + CSV) underneath.
 *
 * The live redraw is deterministic (one path, instant); the meter's boundary and the word
 * verdict come from the Phase-1 job on completion. The two are honest about what they are — the
 * caption says "a quick central estimate" for the line and the meter carries the Monte Carlo
 * confidence — so the instant line is never mistaken for the probability answer.
 */
class ThresholdExplorer extends Component
{
    public Scenario $scenario;

    /** Which lever is being explored ({@see LeverKey} value). */
    public string $lever = '';

    /** The slider position in the lever's own units (a price, an age, an annual spend). */
    public ?float $leverValue = null;

    /** The success bar the limit answers — the chance the essentials last (a caller choice, 0.90 default). */
    public float $target = 0.90;

    /** The queued/completed threshold for the current lever, or null before one is requested. */
    public ?int $thresholdId = null;

    /** The queued/completed 2-D trade-off map (buy price × retirement age), or null before one is requested. */
    public ?int $frontierId = null;

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        $this->scenario = $scenario;
        $first = $this->leverChoices()[0];
        $this->lever = $first['id'];
        $this->leverValue = $this->defaultValue($first['key']);
    }

    /** Switch the explored lever: reset the slider to that lever's mid-range and drop the old (lever-specific) threshold. */
    public function setLever(string $lever): void
    {
        $choice = $this->choiceFor($lever);
        if ($choice === null) {
            return; // ignore a lever this scenario doesn't offer (or a tampered value)
        }

        $this->lever = $choice['id'];
        $this->leverValue = $this->defaultValue($choice['key']);
        $this->thresholdId = null;
    }

    /** Queue the Monte Carlo threshold for the current lever (or hit the cache if it's already computed). */
    public function findLimit(): void
    {
        $run = app(ThresholdRunner::class)->request(
            $this->scenario, $this->currentKey(), SweepMetric::Essentials, $this->target,
            leverParam: $this->currentParam(),
        );
        $this->thresholdId = $run->id;
    }

    public function cancelLimit(): void
    {
        if ($threshold = $this->currentThreshold()) {
            app(ThresholdRunner::class)->cancel($threshold);
        }
    }

    /**
     * Queue the 2-D trade-off map (or hit the cache): the buy-price ceiling at each held
     * retirement age — Phase 5's answer to "a single limit hides the pairing".
     */
    public function mapFrontier(): void
    {
        if (! $this->frontierOffered()) {
            return; // this scenario has no buy to price or no one still working
        }

        $run = app(ThresholdRunner::class)->requestFrontier(
            $this->scenario, LeverKey::BuyPrice, LeverKey::RetirementAge, SweepMetric::Essentials, $this->target,
        );
        $this->frontierId = $run->id;
    }

    public function cancelFrontier(): void
    {
        if ($frontier = $this->currentFrontier()) {
            app(ThresholdRunner::class)->cancel($frontier);
        }
    }

    /** wire:poll target while the threshold is computing; the render pass re-reads its status. */
    public function pollThreshold(): void
    {
        // intentionally empty
    }

    public function render(): View
    {
        $choice = $this->currentChoice();
        $lever = $choice['key'];
        $param = $choice['param'];
        // Care is a binary pin-and-compare, not a monotone sweep: it shows no slider, no live line,
        // no meter and no S-curve (those all imply an ordered lever with an interpolated limit that
        // does not exist for an off/on toggle) — it branches to a two-state before/after readout.
        $isCare = $lever === LeverKey::Care;
        $grid = $this->grid($lever);
        $value = $this->clampedValue($lever, $grid);

        $threshold = $this->currentThreshold();
        $outcome = ($threshold !== null && $threshold->status === SimulationStatus::Done)
            ? $threshold->thresholdOutcome()
            : null;
        $csvUrl = $outcome !== null ? route('scenarios.threshold.csv', [$this->scenario, $threshold]) : null;

        $netPosition = null;
        $slider = null;
        $meter = null;
        $sCurve = null;
        $careComparison = null;

        if ($isCare) {
            // Two independently-seeded runs (care off vs on), read side by side — never interpolated.
            $careComparison = $outcome !== null ? ThresholdPresenter::careComparison($outcome) : null;
        } else {
            // The instant deterministic net-position line at the current lever value.
            $forecast = app(LeverThresholdService::class)->deterministicForecastAt($this->scenario, $lever, $value, $param);
            $netPosition = ThresholdPresenter::netPosition(
                $forecast,
                $this->scenario->toHousehold(),
                'Central estimate at '.ThresholdPresenter::formatLeverValue($lever, $value),
            );
            $slider = [
                'min' => $grid[0],
                'max' => end($grid),
                'step' => $this->step($grid),
                'value' => $value,
                'valueLabel' => ThresholdPresenter::formatLeverValue($lever, $value),
            ];
            if ($outcome !== null) {
                $meter = ThresholdPresenter::meter($outcome, $lever, $value);
                $sCurve = ThresholdPresenter::sCurve($outcome, $lever);
            }
        }

        $frontier = $this->currentFrontier();
        $frontierOutcome = ($frontier !== null && $frontier->status === SimulationStatus::Done)
            ? $frontier->frontierOutcome()
            : null;
        $frontierView = $frontierOutcome !== null ? FrontierPresenter::view($frontierOutcome) : null;

        return view('livewire.threshold-explorer', [
            'leverKey' => $lever,
            'isCare' => $isCare,
            // The selected menu id + its person-aware label: the id marks the active button (a
            // per-person lever's id carries the person, so it can't match on the bare LeverKey),
            // and the label names the specific person ("How long Alex lives").
            'selectedLever' => $choice['id'],
            'selectedLabel' => $choice['label'],
            'levers' => $this->leverOptions(),
            // What moving this lever does that its odds curve cannot show (null for most levers).
            'leverCaveat' => ThresholdPresenter::leverCaveat($lever, $this->scenario->toHousehold(), $this->scenario->base_tax_year),
            'slider' => $slider,
            'netPosition' => $netPosition,
            'threshold' => $threshold,
            'meter' => $meter,
            'sCurve' => $sCurve,
            'careComparison' => $careComparison,
            'csvUrl' => $csvUrl,
            // The 2-D trade-off map (Phase 5): offered only when both its axes are real levers
            // here; the view model appears once its queued run completes.
            'frontierOffered' => $this->frontierOffered(),
            'frontier' => $frontier,
            'frontierView' => $frontierView,
            'frontierCsvUrl' => $frontierView !== null ? route('scenarios.threshold.csv', [$this->scenario, $frontier]) : null,
            // Headline: the current plan's Monte Carlo odds as a natural-frequency pictograph
            // (year-first, never a bare %). Null until a full forecast has run.
            'pictograph' => $this->pictograph(),
        ]);
    }

    /** The frontier record, owner-scoped like {@see currentThreshold} (a tampered id can't load another user's). */
    private function currentFrontier(): ?ThresholdResult
    {
        return $this->frontierId
            ? ThresholdResult::where('user_id', auth()->id())->where('scenario_id', $this->scenario->id)->find($this->frontierId)
            : null;
    }

    /**
     * Whether the trade-off map is offered: both of its axes must be levers this scenario can
     * actually move — a configured buy to sweep the price of, and someone still working to hold
     * the retirement age at. The same gates as the 1-D lever menu, required together.
     */
    private function frontierOffered(): bool
    {
        $ids = array_column($this->leverChoices(), 'id');

        return in_array(LeverKey::BuyPrice->value, $ids, true)
            && in_array(LeverKey::RetirementAge->value, $ids, true);
    }

    /** The threshold record for the current lever, owner-scoped (a tampered id can't load another user's). */
    private function currentThreshold(): ?ThresholdResult
    {
        return $this->thresholdId
            ? ThresholdResult::where('user_id', auth()->id())->where('scenario_id', $this->scenario->id)->find($this->thresholdId)
            : null;
    }

    /**
     * The natural-frequency pictograph from the current plan's latest completed Monte Carlo run
     * (its own chosen variant). Null when no run has completed — the panel then invites one.
     *
     * @return array{filled: int, empty: int, runsOutYear: ?int}|null
     */
    private function pictograph(): ?array
    {
        $run = $this->scenario->latestCompletedRun();
        if ($run === null) {
            return null;
        }

        $result = $run->results->firstWhere(fn ($r) => $r->variant === $this->scenario->variant) ?? $run->results->first();
        if ($result === null) {
            return null;
        }

        $sim = $result->simulationResult();

        return ThresholdPresenter::pictograph($sim->successProbabilityEssentials, $sim->medianDepletionYear);
    }

    /**
     * The levers this scenario can explore, each a stable menu id → (engine lever key, its
     * per-person parameter, a human label): buy price only when a sale frees proceeds to buy with,
     * retirement age only when someone is still working, essential spending always, the survivor's
     * DB / annuity share only for a couple whose scheme actually provides a survivor benefit, and
     * per-person longevity (one entry per person) only for a couple.
     *
     * A household-wide lever's id is just its {@see LeverKey} value; a per-person lever's id is
     * "person_longevity:<personId>" so each person is a distinct menu choice. The id is the single
     * value the wire:model, the gate check and the threshold request all agree on (storage still
     * splits it back into lever_key + lever_param).
     *
     * @return list<array{id: string, key: LeverKey, param: ?string, label: string}>
     */
    private function leverChoices(): array
    {
        $state = $this->scenario->effectiveBuilderState();
        $action = $this->scenario->toHousingAction();
        $household = $this->scenario->toHousehold();
        $choices = [];

        $add = static function (LeverKey $key, ?string $param = null, ?string $label = null) use (&$choices): void {
            $id = $param === null ? $key->value : $key->value.':'.$param;
            $choices[] = ['id' => $id, 'key' => $key, 'param' => $param, 'label' => $label ?? $key->label()];
        };

        // Buy price is a lever only when the plan actually buys a home (a sale that funds a
        // purchase), mirroring the ladder's buy-strategy gating — renting or staying put has no
        // buy price to move.
        if ($action->salePrice->isPositive() && ($action->buyPrice?->isPositive() ?? false)) {
            $add(LeverKey::BuyPrice);
        }
        $working = false;
        foreach ($state['people'] ?? [] as $person) {
            if (in_array($person['employmentStatus'] ?? '', ['employed', 'self_employed'], true)) {
                $working = true;
                break;
            }
        }
        if ($working) {
            $add(LeverKey::RetirementAge);
        }
        $add(LeverKey::EssentialSpend);

        if ($this->hasSurvivorDbPension()) {
            $add(LeverKey::SurvivorDbFraction);
        }
        if ($this->hasSurvivorAnnuity()) {
            $add(LeverKey::SurvivorAnnuityFraction);
        }

        // Per-person longevity — one entry per person, only for a couple. Whose longevity binds is
        // the insight (extending the better-provided partner helps, the survivor hurts); a lone
        // person's longevity is the whole household's, already covered by a quick what-if. Named by
        // the person so "How long Alex lives" and "How long Sam lives" are distinct choices.
        if (count($household->persons) >= 2) {
            foreach ($household->persons as $i => $person) {
                $name = $person->name !== null && $person->name !== '' ? $person->name : 'Person '.($i + 1);
                $add(LeverKey::PersonLongevity, $person->id, "How long {$name} lives");
            }
        }

        // Deferring one partner's State Pension — one entry per person who actually has a State
        // Pension, only for a couple. Whose SP to defer is the insight: deferring the likely
        // SURVIVOR's raises the floor they lean on after the first death, while the first-dier's is
        // largely wasted (a State Pension is not inherited). A lone person's deferral is a plain
        // income-timing choice, not a survivor question.
        if (count($household->persons) >= 2) {
            foreach ($household->persons as $i => $person) {
                if (! $this->hasStatePension($person->id)) {
                    continue;
                }
                $name = $person->name !== null && $person->name !== '' ? $person->name : 'Person '.($i + 1);
                $add(LeverKey::StatePensionDeferral, $person->id, "How long {$name} defers their State Pension");
            }
        }

        // Whether the late-life care-fee tail is modelled — a binary (off vs on), ungated: care risk
        // is off by default and applies to a lone person as much as a couple. It sits last because it
        // is a sensitivity check on the other levers (that six-figure tail flatters every ceiling when
        // it is left out), not a change to the plan itself.
        $add(LeverKey::Care);

        return $choices;
    }

    /**
     * The choice for a menu id, or null if this scenario doesn't offer it (a tampered/stale value).
     *
     * @return array{id: string, key: LeverKey, param: ?string, label: string}|null
     */
    private function choiceFor(string $id): ?array
    {
        foreach ($this->leverChoices() as $choice) {
            if ($choice['id'] === $id) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The currently-selected choice, falling back to the first available when the id is unknown
     * (so a tampered `lever` prop can never resolve to another scenario's lever or crash render).
     *
     * @return array{id: string, key: LeverKey, param: ?string, label: string}
     */
    private function currentChoice(): array
    {
        return $this->choiceFor($this->lever) ?? $this->leverChoices()[0];
    }

    private function currentKey(): LeverKey
    {
        return $this->currentChoice()['key'];
    }

    private function currentParam(): ?string
    {
        return $this->currentChoice()['param'];
    }

    /**
     * Whether the survivor's-DB-share lever applies: the household is a couple (there is a survivor
     * to inherit the pension) and at least one DB scheme already provides a survivor's fraction (the
     * lever varies an existing benefit, never invents one on a scheme that offers none).
     */
    private function hasSurvivorDbPension(): bool
    {
        $household = $this->scenario->toHousehold();
        if (count($household->persons) < 2) {
            return false;
        }

        foreach ($household->pensions as $pension) {
            if ($pension instanceof DbPension && $pension->spousePensionFraction !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the joint-life-annuity survivor lever applies: a couple with a DC annuity that is
     * already joint-life (a non-null survivor fraction). Like the DB lever, it varies an existing
     * survivor benefit — it never turns a single-life annuity joint-life at a single-life rate.
     */
    private function hasSurvivorAnnuity(): bool
    {
        $household = $this->scenario->toHousehold();
        if (count($household->persons) < 2) {
            return false;
        }

        foreach ($household->pensions as $pension) {
            if ($pension instanceof DcPension
                && $pension->annuityPurchase !== null
                && $pension->annuityPurchase->survivorFraction !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $personId holds a State Pension entitlement — the thing the deferral lever moves. The
     * couple gate lives at the call site (whose SP to defer is only a survivor question for a
     * couple); this just refuses to offer the lever for a person with no State Pension to defer.
     */
    private function hasStatePension(string $personId): bool
    {
        foreach ($this->scenario->toHousehold()->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $personId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{value: string, label: string}> */
    private function leverOptions(): array
    {
        return array_map(static fn (array $c): array => ['value' => $c['id'], 'label' => $c['label']], $this->leverChoices());
    }

    /**
     * The lever grid (min..max) the slider spans — the same default the sweep brackets the
     * scenario's figures with, so the slider range and the threshold agree.
     *
     * @return list<float>
     */
    private function grid(LeverKey $lever): array
    {
        return app(LeverThresholdService::class)->defaultGrid($lever, $this->scenario->toHousehold(), $this->scenario->toHousingAction());
    }

    /** Start the slider at the grid's mid-point (a real grid value, always in range). */
    private function defaultValue(LeverKey $lever): float
    {
        $grid = $this->grid($lever);

        return $grid[intdiv(count($grid), 2)];
    }

    /** A whole, sensible slider step: ~40 stops across the range, at least 1 (integer ages). */
    private function step(array $grid): float
    {
        $span = end($grid) - $grid[0];

        return max(1.0, round($span / 40));
    }

    /** Clamp the (public, tamperable) slider value into the lever's range before it reaches the engine. */
    private function clampedValue(LeverKey $lever, array $grid): float
    {
        $value = $this->leverValue ?? $this->defaultValue($lever);

        return max($grid[0], min((float) end($grid), (float) $value));
    }
}
