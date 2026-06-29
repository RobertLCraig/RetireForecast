<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Turns a persisted {@see Scenario} into the engine's input DTOs and runs the
 * forecast for it. This is the one place the app assembles a forecast: it resolves
 * the household, assumption set, housing action, tax-year config and settings, then
 * hands them to the framework-free engine.
 *
 * The base year is taken from the scenario's tax year (e.g. 2026-27 -> 2026) so the
 * run is deterministic and clock-free, matching the engine's no-clock rule.
 */
final class ScenarioForecaster
{
    /** A stamp recorded on each run so any stored result is auditable back to its inputs. */
    public const ENGINE_VERSION = 'finance-engine/phase-3';

    /** The central best-estimate forecast: median death ages, expected returns, no sampling. */
    public function deterministic(Scenario $scenario): ForecastResult
    {
        return $this->deterministicWith($scenario, $this->assumptions($scenario));
    }

    /**
     * The central best-estimate forecast under an explicit assumption set — the basis of
     * the compare-assumptions overlay, which runs this once per shipped set.
     */
    public function deterministicWith(Scenario $scenario, AssumptionSet $assumptions): ForecastResult
    {
        return (new DeterministicForecaster($this->config($scenario), new CohortLifeTable))
            ->forecast($this->household($scenario), $assumptions, $this->settings($scenario));
    }

    /**
     * The central best-estimate forecast for EACH housing strategy (stay put / buy cheaper /
     * sell & rent), so the cashflow ladder can show the year-by-year picture by strategy
     * rather than only the raw household. Each variant household + settings comes from
     * {@see HousingComparison::variantInputs()} — the SAME single source the Monte Carlo
     * comparison runs — so the deterministic ladder and the simulated comparison transform
     * the household for a sale identically and cannot drift. With the contingent-cost rule
     * the sell variants carry no property cost and no home value, and invest the freed
     * proceeds; `stay_put` is byte-identical to {@see deterministic()} (the raw household).
     *
     * @return array{stay_put: ForecastResult, buy_outright: ForecastResult, rent: ForecastResult}
     */
    public function deterministicVariants(Scenario $scenario): array
    {
        $assumptions = $this->assumptions($scenario);
        $variants = $this->housingComparison($scenario)->variantInputs(
            $this->household($scenario),
            $this->settings($scenario),
            $assumptions,
            $this->housingAction($scenario),
        );

        $forecaster = new DeterministicForecaster($this->config($scenario), new CohortLifeTable);

        return array_map(
            fn (array $variant): ForecastResult => $forecaster->forecast($variant['household'], $assumptions, $variant['settings']),
            $variants,
        );
    }

    /** One variant's Monte Carlo run (the scenario's household as it stands). */
    public function simulate(Scenario $scenario, int $nPaths, int $seed): SimulationResult
    {
        return (new Simulator($this->config($scenario)))->run(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            new CohortLifeTable,
            $nPaths,
            $seed,
        );
    }

    /**
     * The buy-vs-rent headline: stay-put, buy-cheaper-outright and sell-and-rent run
     * on identical seeds, so any difference is the housing choice alone.
     *
     * @param  (callable(float $fraction): void)|null  $onProgress  overall 0..1; throwing aborts the run
     * @return array{stay_put: SimulationResult, buy_outright: SimulationResult, rent: SimulationResult}
     */
    public function compareHousing(Scenario $scenario, int $nPaths, int $seed, ?callable $onProgress = null): array
    {
        return $this->housingComparison($scenario)->compare(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            $this->housingAction($scenario),
            $nPaths,
            $seed,
            $onProgress,
        );
    }

    /**
     * The housing-comparison engine for this scenario. Exposed so the deterministic sale
     * decomposition ({@see HousingComparison::saleProceeds} / {@see HousingComparison::buyOutcome})
     * can be surfaced on the results page and reconciled, rather than recomputed in the app.
     */
    public function housingComparison(Scenario $scenario): HousingComparison
    {
        return new HousingComparison($this->config($scenario), new CohortLifeTable);
    }

    public function config(Scenario $scenario): TaxYearConfig
    {
        return TaxYearRegistry::for($scenario->base_tax_year, $this->household($scenario)->region);
    }

    /**
     * The economic assumptions the forecast runs against: the scenario's chosen sourced
     * preset (or the engine default), overlaid with any figures the user has edited into
     * a derived custom set ({@see AssumptionOverrides}). This is the ONE place overrides
     * are applied, so the deterministic forecast, the per-variant ladder, the Monte Carlo
     * and the frozen run snapshot all run against the same set and cannot drift.
     */
    public function assumptions(Scenario $scenario): AssumptionSet
    {
        $base = $scenario->assumptionSet?->toDto() ?? AssumptionSetLibrary::default();
        $overrides = $scenario->effectiveBuilderState()['assumptionOverrides'] ?? [];

        return AssumptionOverrides::apply($base, $overrides, $this->settings($scenario)->allocation());
    }

    private function household(Scenario $scenario): Household
    {
        return $scenario->toHousehold();
    }

    private function housingAction(Scenario $scenario): HousingAction
    {
        return $scenario->toHousingAction();
    }

    /**
     * The run settings (start year, allocation, drawdown strategy, freeze-end year). Public
     * so the results page can read the blended real return the invested proceeds grow at
     * (`settings()->allocation()->blendedRealReturn($assumptions)`) for the assumptions panel.
     */
    public function settings(Scenario $scenario): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: (int) substr($scenario->base_tax_year, 0, 4),
            baseTaxYear: $scenario->base_tax_year,
        );
    }
}
