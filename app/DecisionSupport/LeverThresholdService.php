<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use InvalidArgumentException;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\BuyPriceLever;
use RetireForecast\FinanceEngine\Sweep\Lever\CareModellingLever;
use RetireForecast\FinanceEngine\Sweep\Lever\DiscretionarySpendLever;
use RetireForecast\FinanceEngine\Sweep\Lever\EssentialSpendLever;
use RetireForecast\FinanceEngine\Sweep\Lever\PersonLongevityLever;
use RetireForecast\FinanceEngine\Sweep\Lever\RetirementAgeLever;
use RetireForecast\FinanceEngine\Sweep\Lever\StatePensionDeferralLever;
use RetireForecast\FinanceEngine\Sweep\Lever\SurvivorAnnuityFractionLever;
use RetireForecast\FinanceEngine\Sweep\Lever\SurvivorDbFractionLever;
use RetireForecast\FinanceEngine\Sweep\SweepEngine;
use RetireForecast\FinanceEngine\Sweep\SweepLever;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;

/**
 * The app-layer bridge from a persisted {@see Scenario} to the engine's {@see SweepEngine}: resolve
 * the scenario's household, settings and assumptions (through the single {@see ScenarioForecaster},
 * so a swept threshold rests on the SAME inputs the results page forecasts), pick the lever, run the
 * sweep and find where it crosses the target.
 *
 * The compute itself is a set of Monte Carlo runs — a LONG run — so it is meant to be driven by a
 * queued job (mirroring the simulation run: progress via $onProgress, cancel by throwing), never
 * synchronously on a web request (the plan's "no silent long-runs"). The seed is FIXED, not random:
 * a sweep is common-random-numbers across its grid, and a fixed seed makes a threshold reproducible
 * and cacheable by its inputs.
 */
final class LeverThresholdService
{
    /** The pinned seed for every threshold sweep (0x12345678) — recorded on the curve as provenance. */
    public const SEED = 305_419_896;

    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    /**
     * Compute the threshold for $lever on $scenario against a $targetProbability of $metric success.
     * $grid defaults to a sensible per-lever range; $onProgress (points done, total) lets the job
     * report progress and cancel by throwing.
     *
     * $leverParam is the per-person target for a parameterised lever (the person id the
     * per-person longevity lever moves); null for the household-wide levers.
     *
     * @param  list<float>|null  $grid
     * @param  (callable(int $done, int $total): void)|null  $onProgress
     */
    public function compute(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        ?array $grid = null,
        int $nPaths = 500,
        ?callable $onProgress = null,
        ?string $leverParam = null,
    ): ThresholdOutcome {
        $engine = new SweepEngine($this->forecaster->config($scenario));
        $household = $scenario->toHousehold();
        $settings = $this->forecaster->settings($scenario);
        $assumptions = $this->forecaster->assumptions($scenario);
        $action = $scenario->toHousingAction();

        $sweepLever = $this->buildLever($scenario, $lever, $assumptions, $action, $leverParam);
        $grid ??= $this->defaultGrid($lever, $household, $action);

        $curve = $engine->sweep(
            $household, $settings, $assumptions, new CohortLifeTable,
            $sweepLever, $grid, $metric, $nPaths, self::SEED, $onProgress,
        );

        return new ThresholdOutcome($lever, $metric, $targetProbability, $curve, $engine->findCrossing($curve, $targetProbability));
    }

    /**
     * The 2-D frontier (Phase 5): the $thresholdLever's crossing of $targetProbability at each held
     * value of $conditionLever — e.g. the buy-price ceiling at each retirement age. Resolves the
     * scenario exactly as {@see compute} does (same forecaster, same pinned seed), builds BOTH
     * levers through the same {@see buildLever}, and runs the engine's frontier: per condition
     * value, a full common-random-numbers sweep of the threshold lever, keeping every measured
     * cell (the heatmap) and the crossing it implies (the iso-line).
     *
     * The two levers must differ (the second `apply` would otherwise overwrite the first), and the
     * care toggle is refused on either axis — it is a categorical pin-and-compare, not a range a
     * frontier can hold at or sweep. v1 pairs the household-wide levers only (the headline pair is
     * buy price × retirement age); the per-person levers keep to the 1-D explorer.
     *
     * $onProgress is (cells done, total cells) — a frontier is |conditionGrid| × |thresholdGrid|
     * Monte Carlo runs, the longest compute in the app, so it is queued and never silent.
     *
     * @param  list<float>|null  $thresholdGrid
     * @param  list<float>|null  $conditionGrid
     * @param  (callable(int $done, int $total): void)|null  $onProgress
     */
    public function computeFrontier(
        Scenario $scenario,
        LeverKey $thresholdLever,
        LeverKey $conditionLever,
        SweepMetric $metric,
        float $targetProbability,
        ?array $thresholdGrid = null,
        ?array $conditionGrid = null,
        int $nPaths = 500,
        ?callable $onProgress = null,
    ): FrontierOutcome {
        if ($thresholdLever === $conditionLever) {
            throw new InvalidArgumentException('A frontier needs two different levers — one swept, one held.');
        }
        if ($thresholdLever === LeverKey::Care || $conditionLever === LeverKey::Care) {
            throw new InvalidArgumentException('The care toggle is a pinned before/after, not a range a frontier can sweep or hold.');
        }

        $engine = new SweepEngine($this->forecaster->config($scenario));
        $household = $scenario->toHousehold();
        $settings = $this->forecaster->settings($scenario);
        $assumptions = $this->forecaster->assumptions($scenario);
        $action = $scenario->toHousingAction();

        $thresholdGrid ??= $this->defaultGrid($thresholdLever, $household, $action);
        $conditionGrid ??= $this->defaultConditionGrid($conditionLever, $household, $action);

        $frontier = $engine->frontier(
            $household, $settings, $assumptions, new CohortLifeTable,
            $this->buildLever($scenario, $thresholdLever, $assumptions, $action), $thresholdGrid,
            $this->buildLever($scenario, $conditionLever, $assumptions, $action), $conditionGrid,
            $metric, $targetProbability, $nPaths, self::SEED, $onProgress,
        );

        return new FrontierOutcome($thresholdLever, $conditionLever, $metric, $targetProbability, $frontier);
    }

    /**
     * The default HELD grid when a lever is a frontier's condition axis: five evenly-spaced values
     * over the same span as its 1-D {@see defaultGrid}. Coarser than the swept grid on purpose —
     * every held value multiplies the frontier's Monte Carlo cost by a full sweep, and five
     * columns already show how the ceiling shifts across the range.
     *
     * @return list<float>
     */
    public function defaultConditionGrid(LeverKey $key, Household $household, HousingAction $action): array
    {
        $grid = $this->defaultGrid($key, $household, $action);

        return self::linspace($grid[0], (float) end($grid), 5);
    }

    /**
     * A single cheap DETERMINISTIC forecast at one lever value — the instant "live redraw" behind
     * the decision-support slider (the Monte Carlo threshold is the slow, queued part). It applies
     * the lever to the scenario's household + settings exactly as the sweep does — through the same
     * {@see buildLever} and {@see SweepLever::apply} — so the transient line reconciles with the
     * swept curve's inputs (and with the results page, since both resolve through `ScenarioForecaster`).
     * No Monte Carlo, no persistence: builder-state in, one `ForecastResult` out.
     */
    public function deterministicForecastAt(Scenario $scenario, LeverKey $lever, float $value, ?string $leverParam = null): ForecastResult
    {
        $household = $scenario->toHousehold();
        $settings = $this->forecaster->settings($scenario);
        $assumptions = $this->forecaster->assumptions($scenario);
        $action = $scenario->toHousingAction();

        $inputs = $this->buildLever($scenario, $lever, $assumptions, $action, $leverParam)->apply($household, $settings, $value);

        return (new DeterministicForecaster($this->forecaster->config($scenario), new CohortLifeTable))
            ->forecast($inputs->household, $assumptions, $inputs->settings);
    }

    /**
     * Build the engine lever for $key, wired with the scenario's context (housing for buy-price,
     * and $leverParam — the person id — for a per-person lever). A per-person lever falls back to
     * the first person when no target is given (defensive; the UI always names one).
     */
    private function buildLever(Scenario $scenario, LeverKey $key, AssumptionSet $assumptions, HousingAction $action, ?string $leverParam = null): SweepLever
    {
        return match ($key) {
            LeverKey::BuyPrice => new BuyPriceLever($this->forecaster->housingComparison($scenario), $assumptions, $action),
            LeverKey::RetirementAge => new RetirementAgeLever,
            LeverKey::EssentialSpend => new EssentialSpendLever,
            LeverKey::DiscretionarySpend => new DiscretionarySpendLever,
            LeverKey::SurvivorDbFraction => new SurvivorDbFractionLever,
            LeverKey::SurvivorAnnuityFraction => new SurvivorAnnuityFractionLever,
            LeverKey::PersonLongevity => new PersonLongevityLever($leverParam ?? $scenario->toHousehold()->persons[0]->id),
            LeverKey::StatePensionDeferral => new StatePensionDeferralLever($leverParam ?? $scenario->toHousehold()->persons[0]->id),
            LeverKey::Care => new CareModellingLever,
        };
    }

    /**
     * A sensible default grid of lever values when the caller does not supply one: a spread around
     * the scenario's own figures, so the sweep brackets the threshold without the caller guessing.
     *
     * @return list<float>
     */
    public function defaultGrid(LeverKey $key, Household $household, HousingAction $action): array
    {
        return match ($key) {
            // Buy price: from £100k up to the current home's sale value (buying dearer than that is
            // not "buying cheaper"). Nine points across the range.
            LeverKey::BuyPrice => self::linspace(100_000.0, max(150_000.0, (float) intdiv($action->salePrice->pence, 100)), 9),
            // Retirement age: the builder's 55–75 window, every two years.
            LeverKey::RetirementAge => self::linspace(55.0, 75.0, 11),
            // Essential spend: from half to one-and-a-half times the current essential floor.
            LeverKey::EssentialSpend => self::linspace(
                0.5 * (float) intdiv($household->expenseProfile->essentialAnnualSpend->pence, 100),
                1.5 * (float) intdiv($household->expenseProfile->essentialAnnualSpend->pence, 100),
                9,
            ),
            // Discretionary spend: from nothing (essentials only) to three times what they spend on
            // choices today. Starts at zero because the honest answer for a stretched household is
            // sometimes "there is no room for extras", and the grid must be able to say so.
            LeverKey::DiscretionarySpend => self::linspace(
                0.0,
                max(3_000.0, 3.0 * (float) intdiv($household->expenseProfile->discretionaryAnnualSpend->pence, 100)),
                9,
            ),
            // Survivor's DB fraction: the full 0–100% range, every 10 points (a spouse's pension is
            // commonly half, sometimes two-thirds — the sweep spans none through the whole pension).
            LeverKey::SurvivorDbFraction => self::linspace(0.0, 100.0, 11),
            // Survivor's annuity fraction: likewise the whole 0–100% joint-life range.
            LeverKey::SurvivorAnnuityFraction => self::linspace(0.0, 100.0, 11),
            // Per-person longevity: a ± year offset from the cohort peer, −5 to +15 years, every
            // two years (0 = peer). Spans both a shorter life (a health condition) and a much
            // longer one — the survivor-cliff case being "what if the survivor lives well beyond
            // average"; the grid is the same whichever person the lever targets.
            LeverKey::PersonLongevity => self::linspace(-5.0, 15.0, 11),
            // State Pension deferral: 0 to 5 whole years (you can defer as long as you like, but
            // beyond a few years the forgone income rarely pays back within a normal lifespan).
            // Same grid whichever person the lever targets.
            LeverKey::StatePensionDeferral => self::linspace(0.0, 5.0, 6),
            // Care: a categorical toggle, NOT a range — exactly two points, 0 = care not modelled,
            // 1 = care modelled. The two are a pinned before/after, never interpolated.
            LeverKey::Care => [0.0, 1.0],
        };
    }

    /**
     * $count evenly-spaced values from $from to $to inclusive.
     *
     * @return list<float>
     */
    private static function linspace(float $from, float $to, int $count): array
    {
        if ($count <= 1) {
            return [$from];
        }
        $step = ($to - $from) / ($count - 1);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $from + $step * $i;
        }

        return $out;
    }
}
