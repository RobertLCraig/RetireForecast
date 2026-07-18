<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
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
 * Stochastic house-price growth in the Monte Carlo. A home is a large, variable slice
 * of most households' wealth, so treating its growth as a certainty understates the risk
 * of the stay-put and buy options relative to renting-and-investing. When the assumption
 * set carries a house volatility, the simulator draws a per-year house-price shock
 * (correlated to the equity shock); with no house volatility it stays deterministic at
 * the mean — the v1 behaviour — so old sets and stored runs reproduce byte-identically.
 *
 * The trust-critical properties: the mean case is untouched (no volatility => the flat
 * path), the shock is reproducible under a seed, and — completeness — the house risk
 * demonstrably reaches the aggregate (it widens a homeowner's terminal-wealth spread).
 */
final class StochasticHouseGrowthTest extends TestCase
{
    /** A three-asset set (equities, gilts, cash) with a 1% real house-growth mean and the given house volatility. */
    private function set(?Percent $houseVol): AssumptionSet
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
            houseGrowthVolatility: $houseVol,
            houseEquityCorrelation: 0.2,
        );
    }

    /** A home-dominated couple: State Pension covers the modest spend, so terminal wealth is mostly the £500k home. */
    private function homeowner(): Household
    {
        return new Household(
            'Home-dominated couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(92)),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(94)),
            ],
            new ExpenseProfile(Money::fromPounds(16_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(60_000), Money::zero(), Money::zero(), 55),
            ],
            primaryResidence: new Property(Money::fromPounds(500_000), OwnershipType::Outright),
        );
    }

    public function test_house_growth_is_deterministic_at_its_mean_when_the_set_has_no_house_volatility(): void
    {
        $model = new ReturnModel($this->set(null), new PortfolioAllocation([0.40, 0.60, 0.0]));
        $path = $model->generatePath(20, new Randomizer(new Mt19937(99)));

        // Every year is exactly the 1% mean — the flat, v1 path.
        foreach ($path['house'] as $h) {
            $this->assertEqualsWithDelta(0.01, $h, 1e-9);
        }
    }

    public function test_house_growth_varies_year_to_year_when_the_set_has_a_house_volatility(): void
    {
        $model = new ReturnModel($this->set(Percent::fromPercent(9)), new PortfolioAllocation([0.40, 0.60, 0.0]));
        $path = $model->generatePath(40, new Randomizer(new Mt19937(99)));

        // Not a straight line any more...
        $rounded = array_map(static fn (float $h): float => round($h, 6), $path['house']);
        $this->assertGreaterThan(1, count(array_unique($rounded)), 'house growth should vary across years');

        // ...but still centred near the 1% mean, and genuinely spread (a std dev in the ~9% ballpark,
        // certainly well above the near-zero spread of a deterministic path).
        $n = count($path['house']);
        $mean = array_sum($path['house']) / $n;
        $variance = array_sum(array_map(static fn (float $h): float => ($h - $mean) ** 2, $path['house'])) / $n;
        $this->assertEqualsWithDelta(0.01, $mean, 0.05);
        $this->assertGreaterThan(0.03, sqrt($variance), 'the sampled house-growth spread should be substantial');
    }

    public function test_the_house_path_is_reproducible_under_a_fixed_seed(): void
    {
        $model = new ReturnModel($this->set(Percent::fromPercent(9)), new PortfolioAllocation([0.40, 0.60, 0.0]));
        $a = $model->generatePath(30, new Randomizer(new Mt19937(7)));
        $b = $model->generatePath(30, new Randomizer(new Mt19937(7)));

        $this->assertSame($a['house'], $b['house']);
    }

    public function test_house_volatility_widens_a_homeowner_terminal_wealth_spread(): void
    {
        // Completeness: the house risk must reach the aggregate, not sit inert in the set. The same
        // home-owning couple (lifespans pinned, so deaths are identical either way) has a WIDER
        // terminal total-wealth spread — total wealth includes home equity — under stochastic house
        // growth than under the deterministic mean. If it did not, the new draw would be changing
        // nothing that matters, exactly the silent-drop failure the completeness rule guards against.
        $simulator = new Simulator(TaxYearRegistry::for('2026-27'));
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');

        $stochastic = $simulator->run($this->homeowner(), $settings, $this->set(Percent::fromPercent(9)), new CohortLifeTable, 500, seed: 11);
        $deterministic = $simulator->run($this->homeowner(), $settings, $this->set(null), new CohortLifeTable, 500, seed: 11);

        $spread = static fn ($result): int => $result->terminalWealthPercentiles['p90']->pence - $result->terminalWealthPercentiles['p10']->pence;

        $this->assertGreaterThan($spread($deterministic), $spread($stochastic));
    }
}
