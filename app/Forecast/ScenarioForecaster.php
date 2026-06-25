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
        return (new HousingComparison($this->config($scenario), new CohortLifeTable))->compare(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            $this->housingAction($scenario),
            $nPaths,
            $seed,
            $onProgress,
        );
    }

    public function config(Scenario $scenario): TaxYearConfig
    {
        return TaxYearRegistry::for($scenario->base_tax_year, $this->household($scenario)->region);
    }

    public function assumptions(Scenario $scenario): AssumptionSet
    {
        return $scenario->assumptionSet?->toDto() ?? AssumptionSetLibrary::default();
    }

    private function household(Scenario $scenario): Household
    {
        return $scenario->toHousehold();
    }

    private function housingAction(Scenario $scenario): HousingAction
    {
        return $scenario->toHousingAction();
    }

    private function settings(Scenario $scenario): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: (int) substr($scenario->base_tax_year, 0, 4),
            baseTaxYear: $scenario->base_tax_year,
        );
    }
}
