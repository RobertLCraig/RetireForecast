<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\JointLifeSampler;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Runs the household through many sampled paths and aggregates them.
 *
 * Each path samples death ages then a correlated return/inflation sequence from a
 * single seeded generator, runs the shared {@see PathProjector}, and is collected.
 * A fixed seed gives byte-identical results (the golden-master property); the seed
 * is always recorded on the result. With zero volatility every path collapses to the
 * deterministic forecast, which is the simulator's sanity check.
 */
final class Simulator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function run(
        Household $household,
        ForecastSettings $settings,
        AssumptionSet $assumptions,
        CohortLifeTable $lifeTable,
        int $nPaths,
        int $seed,
    ): SimulationResult {
        $rng = new Randomizer(new Mt19937($seed));
        $returnModel = new ReturnModel($assumptions, $settings->allocation());
        $jointLife = new JointLifeSampler($lifeTable);
        $projector = new PathProjector($this->config);

        $people = [];
        $minBaseAge = PHP_INT_MAX;
        foreach ($household->persons as $person) {
            $currentAge = $settings->baseYear - (int) $person->dob->format('Y');
            $people[] = ['id' => $person->id, 'sex' => $person->sex, 'currentAge' => $currentAge];
            $minBaseAge = min($minBaseAge, $currentAge);
        }
        $horizon = CohortLifeTable::MAX_AGE - $minBaseAge + 2;

        $essentials = 0;
        $fullSpend = 0;
        $depleted = 0;
        $depletionYears = [];
        $terminalWealth = [];
        $wealthByYearIndex = []; // yearIndex => list<int pence>

        for ($p = 0; $p < $nPaths; $p++) {
            $deathAges = $jointLife->sampleHousehold($people, $settings->baseYear, $rng);
            $path = $returnModel->generatePath($horizon, $rng);
            $draws = new SampledPathDraws($path, $assumptions, $deathAges);

            $result = $projector->project($household, $settings, $draws);

            $essentials += $result->essentialsAlwaysMet ? 1 : 0;
            $fullSpend += $result->fullSpendAlwaysMet ? 1 : 0;
            if ($result->depletionCalendarYear !== null) {
                $depleted++;
                $depletionYears[] = $result->depletionCalendarYear;
            }
            $terminalWealth[] = $result->terminalTotalWealth->pence;

            foreach ($result->years as $year) {
                $wealthByYearIndex[$year->yearIndex][] = $year->totalWealth->pence;
            }
        }

        return new SimulationResult(
            nPaths: $nPaths,
            seed: $seed,
            successProbabilityEssentials: $essentials / $nPaths,
            successProbabilityFullSpend: $fullSpend / $nPaths,
            depletionRate: $depleted / $nPaths,
            medianDepletionYear: $depletionYears === [] ? null : (int) round($this->percentile($depletionYears, 0.5)),
            terminalWealthPercentiles: $this->moneyPercentiles($terminalWealth),
            fanChart: $this->fanChart($wealthByYearIndex, $settings->baseYear),
        );
    }

    /**
     * @param  array<int, list<int>>  $wealthByYearIndex
     * @return list<array{calendarYear: int, paths: int, p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}>
     */
    private function fanChart(array $wealthByYearIndex, int $baseYear): array
    {
        ksort($wealthByYearIndex);
        $bands = [];
        foreach ($wealthByYearIndex as $yearIndex => $values) {
            $pct = $this->moneyPercentiles($values);
            $bands[] = [
                'calendarYear' => $baseYear + $yearIndex,
                'paths' => count($values),
                ...$pct,
            ];
        }

        return $bands;
    }

    /**
     * @param  list<int>  $pence
     * @return array{p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}
     */
    private function moneyPercentiles(array $pence): array
    {
        return [
            'p10' => Money::fromPence((int) round($this->percentile($pence, 0.10))),
            'p25' => Money::fromPence((int) round($this->percentile($pence, 0.25))),
            'p50' => Money::fromPence((int) round($this->percentile($pence, 0.50))),
            'p75' => Money::fromPence((int) round($this->percentile($pence, 0.75))),
            'p90' => Money::fromPence((int) round($this->percentile($pence, 0.90))),
        ];
    }

    /**
     * Linear-interpolated percentile of a list of numbers.
     *
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }

        $rank = $p * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        $frac = $rank - $low;

        return $values[$low] + ($values[$high] - $values[$low]) * $frac;
    }
}
