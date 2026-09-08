<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\ReturnModel;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Inflation with a memory, and inflation that moves with markets (board card 0064).
 *
 * Two faults with one home. The Monte Carlo drew inflation from an independent normal each
 * year, so (a) a high year told you nothing about the next one, when real inflation arrives in
 * multi-year episodes (1973-75, 2021-23), and (b) inflation was uncorrelated with the asset
 * shocks, so the model could never produce the single worst year a bond-heavy retiree has
 * actually lived through: high inflation AND deeply negative real gilt returns at once.
 *
 * The trust-critical properties pinned here: persistence widens the CUMULATIVE price level
 * without widening any single year (the unconditional spread stays the reader's stated
 * volatility, so nothing is double-counted); the inflation shock co-moves negatively with real
 * returns; both are off by default on a hand-rolled set, so a set that states neither is the
 * old memoryless independent draw; and both demonstrably reach the aggregate rather than
 * sitting inert in the assumption set.
 */
final class InflationPersistenceTest extends TestCase
{
    /**
     * A three-asset set (equities, gilts, cash) with the given inflation dynamics.
     *
     * @param  list<float>|null  $inflationAssetCorrelations
     */
    private function set(float $persistence = 0.0, ?array $inflationAssetCorrelations = null): AssumptionSet
    {
        return new AssumptionSet(
            name: 'test',
            sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Global equities', Percent::fromPercent(4.4), Percent::fromPercent(23)),
                new AssetClassAssumption('Gilts/bonds', Percent::fromPercent(0.0), Percent::fromPercent(13)),
                new AssetClassAssumption('Cash', Percent::fromPercent(-0.5), Percent::fromPercent(2)),
            ],
            correlationMatrix: [[1.0, 0.30, 0.10], [0.30, 1.0, 0.30], [0.10, 0.30, 1.0]],
            inflationMean: Percent::fromPercent(2.0),
            inflationVolatility: Percent::fromPercent(1.5),
            houseGrowth: Percent::fromPercent(1.0),
            rentInflation: Percent::fromPercent(0.5),
            salaryGrowth: Percent::fromPercent(1.0),
            investmentIncomeYield: Percent::fromPercent(2.0),
            inflationPersistence: $persistence,
            inflationAssetCorrelations: $inflationAssetCorrelations,
        );
    }

    private function model(AssumptionSet $set): ReturnModel
    {
        return new ReturnModel($set, new PortfolioAllocation([0.40, 0.60, 0.0]));
    }

    /** @param list<float> $xs */
    private function mean(array $xs): float
    {
        return array_sum($xs) / count($xs);
    }

    /** @param list<float> $xs */
    private function sd(array $xs): float
    {
        $m = $this->mean($xs);

        return sqrt(array_sum(array_map(static fn (float $x): float => ($x - $m) ** 2, $xs)) / count($xs));
    }

    /**
     * @param  list<float>  $xs
     * @param  list<float>  $ys
     */
    private function correlation(array $xs, array $ys): float
    {
        $mx = $this->mean($xs);
        $my = $this->mean($ys);
        $cov = 0.0;
        foreach ($xs as $i => $x) {
            $cov += ($x - $mx) * ($ys[$i] - $my);
        }
        $cov /= count($xs);

        return $cov / ($this->sd($xs) * $this->sd($ys));
    }

    /** The lag-1 autocorrelation of a series: how much of a year survives into the next. @param list<float> $xs */
    private function lagOneAutocorrelation(array $xs): float
    {
        return $this->correlation(array_slice($xs, 0, -1), array_slice($xs, 1));
    }

    public function test_inflation_is_memoryless_when_the_set_states_no_persistence(): void
    {
        // The v1 behaviour, kept: a set that states nothing draws independent years, so an old
        // stored run reproduces exactly as it did.
        $path = $this->model($this->set())->generatePath(5_000, new Randomizer(new Mt19937(17)));

        $this->assertEqualsWithDelta(0.0, $this->lagOneAutocorrelation($path['inflation']), 0.05);
    }

    public function test_inflation_carries_its_persistence_from_one_year_into_the_next(): void
    {
        $path = $this->model($this->set(persistence: 0.7))->generatePath(5_000, new Randomizer(new Mt19937(17)));

        // An AR(1) series' lag-1 autocorrelation IS its persistence coefficient.
        $this->assertEqualsWithDelta(0.7, $this->lagOneAutocorrelation($path['inflation']), 0.05);
    }

    public function test_persistence_widens_the_cumulative_price_level_without_widening_a_single_year(): void
    {
        // The economic point of the change. A single year's spread must stay exactly the reader's
        // stated 1.5% volatility (otherwise persistence has quietly raised the volatility they
        // typed), while thirty years compounded must fan out much further than independent draws.
        $independentYears = [];
        $persistentYears = [];
        $independentSums = [];
        $persistentSums = [];

        $flat = $this->model($this->set());
        $sticky = $this->model($this->set(persistence: 0.7));
        for ($p = 0; $p < 400; $p++) {
            $a = $flat->generatePath(30, new Randomizer(new Mt19937(1_000 + $p)))['inflation'];
            $b = $sticky->generatePath(30, new Randomizer(new Mt19937(1_000 + $p)))['inflation'];
            $independentYears = [...$independentYears, ...$a];
            $persistentYears = [...$persistentYears, ...$b];
            $independentSums[] = array_sum($a);
            $persistentSums[] = array_sum($b);
        }

        $this->assertEqualsWithDelta(0.015, $this->sd($independentYears), 0.002);
        $this->assertEqualsWithDelta(0.015, $this->sd($persistentYears), 0.002);

        $this->assertGreaterThan(
            1.5 * $this->sd($independentSums),
            $this->sd($persistentSums),
            'thirty years of sticky inflation should fan the price level far wider than independent draws',
        );
    }

    public function test_a_high_inflation_year_coincides_with_negative_real_returns(): void
    {
        // 2022 in one line: the inflation shock and the real return on the portfolio move against
        // each other, so the model can produce the year a bond-heavy retiree has actually lived.
        $set = $this->set(inflationAssetCorrelations: [-0.30, -0.50, -0.55]);
        $path = $this->model($set)->generatePath(5_000, new Randomizer(new Mt19937(23)));

        $this->assertLessThan(
            -0.20,
            $this->correlation($path['inflation'], $path['investment']),
            'inflation should move against real investment returns',
        );

        // And most strongly against the nominal bond leg, which is what a real-return framework
        // says a price shock does to gilts.
        $this->assertLessThan(
            -0.30,
            $this->correlation($path['inflation'], $path['cash']),
            'inflation should move hard against the real return on cash',
        );
    }

    public function test_inflation_is_independent_of_returns_when_the_set_states_no_correlations(): void
    {
        $path = $this->model($this->set())->generatePath(5_000, new Randomizer(new Mt19937(23)));

        $this->assertEqualsWithDelta(0.0, $this->correlation($path['inflation'], $path['investment']), 0.05);
    }

    public function test_the_shipped_sets_all_carry_a_persistence_and_asset_correlations(): void
    {
        foreach (AssumptionSetLibrary::all() as $set) {
            $this->assertGreaterThan(0.0, $set->inflationPersistence, "{$set->name} should model inflation persistence");
            $this->assertNotNull($set->inflationAssetCorrelations, "{$set->name} should correlate inflation with its assets");
            foreach ($set->inflationAssetCorrelations as $i => $rho) {
                $this->assertLessThan(0.0, $rho, "{$set->name}: asset {$i} should have a negative real-return correlation with inflation");
            }
        }
    }

    public function test_sticky_correlated_inflation_reaches_the_aggregate(): void
    {
        // Completeness: the new dynamics must move a result, not sit inert in the set. The same
        // couple, lifespans pinned, has a WIDER terminal-wealth spread once inflation is sticky and
        // moves against markets, because both changes widen exactly the tail this tool is read for.
        $simulator = new Simulator(TaxYearRegistry::for('2026-27'));
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $household = new Household(
            'Drawdown couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(92)),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(94)),
            ],
            new ExpenseProfile(Money::fromPounds(24_000), Money::fromPounds(6_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(180, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(180, 0)),
                new DcPension('p2', Money::fromPounds(400_000), Money::zero(), Money::zero(), 55),
            ],
        );

        $memoryless = $simulator->run($household, $settings, $this->set(), new CohortLifeTable, 400, seed: 5);
        $sticky = $simulator->run(
            $household,
            $settings,
            $this->set(persistence: 0.7, inflationAssetCorrelations: [-0.30, -0.50, -0.55]),
            new CohortLifeTable,
            400,
            seed: 5,
        );

        $spread = static fn ($r): int => $r->terminalWealthPercentiles['p90']->pence - $r->terminalWealthPercentiles['p10']->pence;

        $this->assertGreaterThan($spread($memoryless), $spread($sticky));
    }
}
