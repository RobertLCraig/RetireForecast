<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
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
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Card 0029. A per-property growth override used to REPLACE the sampled house-price draw, so
 * the moment somebody said "this flat grows at 1%" or "this park home loses 3% a year" their
 * home became a straight line in the Monte Carlo. That is backwards: the properties people
 * override are the ones whose value is LEAST certain, and overriding is exactly how they say so.
 *
 * Two properties this test pins:
 *
 * 1. An override sets the MEAN and nothing else. The year-to-year shock still applies around it,
 *    exactly as the per-person salary override already works.
 * 2. The modelled house volatility is an INDEX figure, and an index has diversified away the
 *    property-specific half of the risk. One home is not an index, so a single property is
 *    modelled over a wider spread ({@see AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE}).
 */
final class SinglePropertyVolatilityTest extends TestCase
{
    /** A three-asset set (equities, gilts, cash) with a 1% real house-growth mean and the given index volatility. */
    private function set(?Percent $houseVol, ?Percent $propertyVol = null): AssumptionSet
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
            singlePropertyVolatility: $propertyVol,
        );
    }

    /** A home-dominated couple, whose home optionally carries a growth override of its own. */
    private function homeowner(?Percent $growthOverride): Household
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
            primaryResidence: new Property(
                Money::fromPounds(500_000),
                OwnershipType::Outright,
                growthAssumptionOverride: $growthOverride,
            ),
        );
    }

    private function simulate(Household $household, AssumptionSet $set): SimulationResult
    {
        return (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            $set,
            new CohortLifeTable,
            400,
            seed: 11,
        );
    }

    /** The p90-p10 band of terminal total wealth (which includes the home), in pence. */
    private function spread(SimulationResult $result): int
    {
        return $result->terminalWealthPercentiles['p90']->pence - $result->terminalWealthPercentiles['p10']->pence;
    }

    public function test_a_growth_override_sets_the_mean_and_keeps_the_year_to_year_variation(): void
    {
        // A household who has said their own home grows at 3% real rather than the set's 1%.
        $overridden = $this->homeowner(Percent::fromPercent(3.0));

        $stochastic = $this->simulate($overridden, $this->set(Percent::fromPercent(9)));
        $flat = $this->simulate($overridden, $this->set(null));

        // The override must not switch the house risk off: the same overridden home under a
        // stochastic set has a WIDER terminal-wealth band than under a set with no house
        // volatility at all. Until card 0029 the two were identical, because the override
        // replaced the draw instead of re-centring it.
        $this->assertGreaterThan(
            $this->spread($flat),
            $this->spread($stochastic),
            'an overridden home must still carry house-price risk',
        );

        // ...and the override is still what the fan is centred ON: raising it from the set's
        // 1% to 3% moves the median outcome up. It sets the mean, not the risk.
        $atSetMean = $this->simulate($this->homeowner(null), $this->set(Percent::fromPercent(9)));
        $this->assertGreaterThan(
            $atSetMean->terminalWealthPercentiles['p50']->pence,
            $stochastic->terminalWealthPercentiles['p50']->pence,
            'the override must still set the central rate the home grows at',
        );
    }

    public function test_a_single_property_is_modelled_over_a_wider_spread_than_the_index(): void
    {
        // An index diversifies away the property-specific component of house-price risk. A
        // household whose net worth is one flat is not exposed to index risk, so the same home
        // over the same seed must carry a wider band than the index figure alone would give.
        $home = $this->homeowner(null);
        $index = Percent::fromPercent(9);

        $asShipped = $this->simulate($home, $this->set($index));
        $atIndexOnly = $this->simulate($home, $this->set($index, propertyVol: $index));

        $this->assertGreaterThan(
            $this->spread($atIndexOnly),
            $this->spread($asShipped),
            'one property is not an index, so its modelled spread must be the wider of the two',
        );
    }

    public function test_a_depreciating_park_home_is_modelled_over_a_wider_spread_than_the_index(): void
    {
        // A park home is entered as a home that LOSES value, a negative growth override, which
        // is precisely the case the old code turned into a certainty. It is also the least
        // index-like home there is: a depreciating chattel on a pitch, sold into a thin market.
        $parkHome = $this->homeowner(Percent::fromPercent(-3.0));
        $index = Percent::fromPercent(9);

        $noHouseRisk = $this->simulate($parkHome, $this->set(null));
        $atIndexOnly = $this->simulate($parkHome, $this->set($index, propertyVol: $index));
        $asShipped = $this->simulate($parkHome, $this->set($index));

        $this->assertGreaterThan(
            $this->spread($noHouseRisk),
            $this->spread($atIndexOnly),
            'a depreciating home must carry house-price risk at all',
        );
        $this->assertGreaterThan(
            $this->spread($atIndexOnly),
            $this->spread($asShipped),
            'and a wider spread than the index default, because a park home is not an index',
        );

        // Reconciliation: the home is still modelled as depreciating. The wider fan must not have
        // quietly turned a losing asset into a rising one.
        $rising = $this->simulate($this->homeowner(Percent::fromPercent(3.0)), $this->set($index));
        $this->assertLessThan(
            $rising->terminalWealthPercentiles['p50']->pence,
            $asShipped->terminalWealthPercentiles['p50']->pence,
        );
    }

    public function test_the_uplift_scales_the_index_figure_rather_than_replacing_it(): void
    {
        // The uplift is a MULTIPLE of whichever set is on display, so re-sourcing the index
        // volatility moves the single-property figure with it and the two cannot drift.
        $set = $this->set(Percent::fromPercent(9));

        $this->assertSame(
            (int) round(900 * AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE),
            $set->singlePropertyVolatility()?->basisPoints,
        );

        // An explicit figure is the reader's own, so it wins outright and is not reported as assumed.
        $explicit = $set->withSinglePropertyVolatility(Percent::fromPercent(12));
        $this->assertSame(1200, $explicit->singlePropertyVolatility()?->basisPoints);
        $this->assertTrue($set->singlePropertyVolatilityIsAssumed());
        $this->assertFalse($explicit->singlePropertyVolatilityIsAssumed());

        // A set with no house volatility has no property volatility either: there is no index
        // spread to widen, so nothing is assumed and nothing is disclosed.
        $this->assertNull($this->set(null)->singlePropertyVolatility());
        $this->assertFalse($this->set(null)->singlePropertyVolatilityIsAssumed());
    }
}
