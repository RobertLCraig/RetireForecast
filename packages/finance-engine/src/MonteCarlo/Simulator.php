<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Care\CareCostSampler;
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

    /**
     * @param  (callable(int $completedPaths, int $totalPaths): void)|null  $onProgress
     *                                                                                   Optional progress hook, called periodically as paths complete. It carries no
     *                                                                                   I/O itself (the engine stays clock- and I/O-free); a caller may use it to
     *                                                                                   report progress or, by throwing, to abort the run early.
     */
    public function run(
        Household $household,
        ForecastSettings $settings,
        AssumptionSet $assumptions,
        CohortLifeTable $lifeTable,
        int $nPaths,
        int $seed,
        ?callable $onProgress = null,
    ): SimulationResult {
        $progressStep = max(1, intdiv($nPaths, 100));
        $rng = new Randomizer(new Mt19937($seed));
        $returnModel = new ReturnModel($assumptions, $settings->allocation());
        $jointLife = new JointLifeSampler($lifeTable);
        $careSampler = $settings->modelCareCost ? new CareCostSampler(CareAssumptions::default()) : null;
        $projector = new PathProjector($this->config);

        $people = [];
        $minBaseAge = PHP_INT_MAX;
        foreach ($household->persons as $person) {
            $currentAge = $settings->baseYear - (int) $person->dob->format('Y');
            $people[] = ['id' => $person->id, 'sex' => $person->sex, 'currentAge' => $currentAge, 'longevity' => $person->longevity];
            $minBaseAge = min($minBaseAge, $currentAge);
        }
        $horizon = CohortLifeTable::MAX_AGE - $minBaseAge + 2;

        $essentials = 0;
        $fullSpend = 0;
        $fullSpendMostYears = 0;
        $depleted = 0;
        $depletionYears = [];
        $terminalWealth = [];
        $terminalUsable = [];
        $careCosts = [];          // per path with care: total real care cost (pence)
        $ihtTotals = [];          // per path: total IHT due across the household's deaths (real pence); only when IHT is modelled
        $lastSurvivorAges = [];   // per path: the age the longest-living person reaches
        $lastSurvivorYears = [];  // per path: the calendar year the household ends
        $wealthByYearIndex = [];       // yearIndex => list<int pence> total wealth (incl. home equity, net of mortgage)
        $usableByYearIndex = [];       // yearIndex => list<int pence> usable wealth (excl. home)
        $netByYearIndex = [];          // yearIndex => list<int pence> net position (usable − cumulative unmet spend)

        for ($p = 0; $p < $nPaths; $p++) {
            $deathAges = $jointLife->sampleHousehold($people, $settings->baseYear, $rng);
            // Sample care spells after deaths (care sits at end of life) and before the return
            // path, so the RNG stream is consistent for the seed. Empty unless care is modelled.
            $careEpisodes = $careSampler?->sampleHousehold(
                array_map(fn (array $person): array => [
                    'id' => $person['id'],
                    'sex' => $person['sex'],
                    'currentAge' => $person['currentAge'],
                    'deathAge' => $deathAges[$person['id']] ?? CohortLifeTable::MAX_AGE,
                ], $people),
                $rng,
            ) ?? [];
            $path = $returnModel->generatePath($horizon, $rng);
            $draws = new SampledPathDraws($path, $assumptions, $deathAges, $careEpisodes);

            $result = $projector->project($household, $settings, $draws);
            if ($result->careCostReal()->isPositive()) {
                $careCosts[] = $result->careCostReal()->pence;
            }
            // Each path computes its own IHT (death timing + terminal wealth vary), so collect the
            // total to build the distribution. Present only when IHT is modelled (else iht is null).
            if ($result->iht !== null) {
                $ihtTotals[] = $result->iht->total->pence;
            }

            // The last survivor (the person who lives longest) sets how long the money must
            // last. Death year = base year + (death age − current age); the latest is the household's end.
            $lastYear = PHP_INT_MIN;
            $lastAge = 0;
            foreach ($people as $person) {
                $deathAge = $deathAges[$person['id']] ?? CohortLifeTable::MAX_AGE;
                $deathYear = $settings->baseYear + ($deathAge - $person['currentAge']);
                if ($deathYear > $lastYear) {
                    $lastYear = $deathYear;
                    $lastAge = $deathAge;
                }
            }
            $lastSurvivorAges[] = $lastAge;
            $lastSurvivorYears[] = $lastYear;

            $essentials += $result->essentialsAlwaysMet ? 1 : 0;
            $fullSpend += $result->fullSpendAlwaysMet ? 1 : 0;
            // The "nearly always" companion: one short year in fifty takes the all-or-nothing
            // figure above to zero, which reads as a plan that never worked at all.
            $fullSpendMostYears += $result->fullSpendYearsMetFraction() >= SimulationResult::FULL_SPEND_MOST_YEARS_THRESHOLD ? 1 : 0;
            if ($result->depletionCalendarYear !== null) {
                $depleted++;
                $depletionYears[] = $result->depletionCalendarYear;
            }
            $terminalWealth[] = $result->terminalTotalWealth->pence;
            $terminalUsable[] = $result->terminalUsableWealth->pence;

            $cumulativeUnmet = 0; // real pence of target spend this path could not fund, running total
            foreach ($result->years as $year) {
                $wealthByYearIndex[$year->yearIndex][] = $year->totalWealth->pence;
                // Usable = liquid + pension (excl. home) — the SAME definition the cashflow
                // ladder and burndown use, so the spendable series can't drift between views.
                $usable = $year->liquidWealth->plus($year->pensionWealth)->pence;
                $usableByYearIndex[$year->yearIndex][] = $usable;
                // Net position continues the usable series below zero once assets are gone:
                // it subtracts the shortfall the household could not fund (accumulated), so the
                // fan shows how deep the funding gap gets instead of flatlining at £0. Equal to
                // usable while solvent (unmet is zero until assets run out).
                $cumulativeUnmet += $year->unmetSpend->pence;
                $netByYearIndex[$year->yearIndex][] = $usable - $cumulativeUnmet;
            }

            if ($onProgress !== null) {
                $completed = $p + 1;
                if ($completed === $nPaths || $completed % $progressStep === 0) {
                    $onProgress($completed, $nPaths);
                }
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
            usableWealthPercentiles: $this->moneyPercentiles($terminalUsable),
            usableFanChart: $this->fanChart($usableByYearIndex, $settings->baseYear),
            longevity: $this->longevityDistribution($lastSurvivorAges, $lastSurvivorYears, $settings->baseYear),
            careImpact: $settings->modelCareCost ? $this->careImpact($careCosts, $nPaths) : null,
            netPositionFanChart: $this->fanChart($netByYearIndex, $settings->baseYear),
            ihtDistribution: $settings->modelIht ? $this->ihtDistribution($ihtTotals, $nPaths) : null,
            successProbabilityFullSpendMostYears: $fullSpendMostYears / $nPaths,
        );
    }

    /**
     * The spread of Inheritance Tax across the sampled futures: the share of paths that leave any
     * IHT bill, and the median / p90 total across ALL paths (so the median reflects the central
     * outcome — often £0 or modest — and p90 the long-life, strong-return tail).
     *
     * @param  list<int>  $ihtTotals  per-path total IHT (real pence)
     */
    private function ihtDistribution(array $ihtTotals, int $nPaths): IhtDistribution
    {
        $withAny = count(array_filter($ihtTotals, static fn (int $t): bool => $t > 0));

        return new IhtDistribution(
            shareWithAnyIht: $nPaths > 0 ? $withAny / $nPaths : 0.0,
            medianIht: Money::fromPence((int) round($this->percentile($ihtTotals, 0.50))),
            p90Iht: Money::fromPence((int) round($this->percentile($ihtTotals, 0.90))),
        );
    }

    /**
     * The modelled care risk: the share of ALL paths in which care cost the household anything
     * (post-means-test — a rare fully-LA-funded spell does not count), and the median / p90
     * household-borne care bill AMONG those paths (the conditional cost a household would face).
     *
     * @param  list<int>  $careCosts  per-path total real care cost (pence), only for paths with a positive cost
     */
    private function careImpact(array $careCosts, int $nPaths): CareImpact
    {
        return new CareImpact(
            shareOfPathsWithCare: $nPaths > 0 ? count($careCosts) / $nPaths : 0.0,
            medianCareCost: Money::fromPence((int) round($this->percentile($careCosts, 0.50))),
            p90CareCost: Money::fromPence((int) round($this->percentile($careCosts, 0.90))),
        );
    }

    /**
     * The spread of how long the household lasts, from the sampled last-survivor ages/years.
     *
     * @param  list<int>  $ages  per-path age the longest-living person reaches
     * @param  list<int>  $years  per-path calendar year the household ends
     */
    private function longevityDistribution(array $ages, array $years, int $baseYear): LongevityDistribution
    {
        $n = max(1, count($ages));
        $planYears = array_map(static fn (int $y): int => $y - $baseYear, $years);
        $reaches = static fn (int $age): float => count(array_filter($ages, static fn (int $a): bool => $a >= $age)) / $n;

        return new LongevityDistribution(
            lastSurvivorAgeP10: (int) round($this->percentile($ages, 0.10)),
            lastSurvivorAgeP50: (int) round($this->percentile($ages, 0.50)),
            lastSurvivorAgeP90: (int) round($this->percentile($ages, 0.90)),
            planYearsP50: (int) round($this->percentile($planYears, 0.50)),
            planYearsP90: (int) round($this->percentile($planYears, 0.90)),
            reaches95: $reaches(95),
            reaches100: $reaches(100),
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
