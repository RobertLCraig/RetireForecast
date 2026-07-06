<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Sweep;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\CareModellingLever;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepEngine;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The care-on/off "pinned" lever: a binary toggle of {@see ForecastSettings::modelCareCost}, the
 * first lever that flips a SETTING rather than the household. It is a pin-and-compare, not a
 * monotone sweep — turning care on inserts extra random draws that desync the return path, so the
 * two states are two independent Monte Carlo samples, not a common-random-numbers pair. These
 * tests pin that the lever flips only the setting (the household is untouched — reconciliation),
 * that the wither preserves every other setting, and that modelling care demonstrably lowers the
 * chance the money lasts (the plan's "care-on lowers the ceiling" completeness).
 */
final class CareModellingLeverTest extends TestCase
{
    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    private function engine(): SweepEngine
    {
        return new SweepEngine(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));
    }

    public function test_the_care_lever_flips_only_the_settings_and_leaves_the_household(): void
    {
        $household = $this->careSensitiveCouple();
        $lever = new CareModellingLever;

        $off = $lever->apply($household, $this->settings(), 0.0);
        $on = $lever->apply($household, $this->settings(), 1.0);

        $this->assertFalse($off->settings->modelCareCost, 'value 0 = care not modelled');
        $this->assertTrue($on->settings->modelCareCost, 'value 1 = care modelled');

        // The lever touches ONLY the setting — the household passes through untouched (it is care,
        // a ForecastSettings flag, not a household attribute). Same instance both ways.
        $this->assertSame($household, $off->household, 'the household is not the lever, care is a setting');
        $this->assertSame($household, $on->household);

        // A pinned before/after, never monotone-fit — the two states are not CRN-comparable.
        $this->assertSame(LeverDirection::Unknown, $lever->direction());
    }

    public function test_with_model_care_cost_preserves_every_other_setting(): void
    {
        // A fully-populated settings object: flipping care must leave every other field identical
        // (no-drift), so the pinned off/on differ in nothing but the care toggle.
        $settings = new ForecastSettings(
            baseYear: 2027,
            baseTaxYear: '2026-27',
            drawdownStrategy: DrawdownStrategy::TaxEfficient,
            allocation: new PortfolioAllocation([0.5, 0.5, 0.0]),
            freezeEndYear: 2030,
            annualRent: Money::fromPounds(14_000),
            rentInflationReal: Percent::fromPercent(1),
            modelCareCost: false,
            modelIht: true,
            homeToDescendants: false,
        );

        $on = $settings->withModelCareCost(true);

        $this->assertTrue($on->modelCareCost);
        $this->assertSame($settings->baseYear, $on->baseYear);
        $this->assertSame($settings->baseTaxYear, $on->baseTaxYear);
        $this->assertSame($settings->drawdownStrategy, $on->drawdownStrategy);
        $this->assertSame($settings->allocation, $on->allocation);
        $this->assertSame($settings->freezeEndYear, $on->freezeEndYear);
        $this->assertSame($settings->annualRent, $on->annualRent);
        $this->assertSame($settings->rentInflationReal, $on->rentInflationReal);
        $this->assertSame($settings->modelIht, $on->modelIht);
        $this->assertSame($settings->homeToDescendants, $on->homeToDescendants);

        // And flipping back off restores it without disturbing anything else.
        $this->assertFalse($on->withModelCareCost(false)->modelCareCost);
    }

    public function test_modelling_care_lowers_the_ceiling_and_the_care_tail_reaches_the_result(): void
    {
        // A couple whose State Pensions fall short of essentials, so a modest pot is load-bearing —
        // draining it on a six-figure care bill tips those paths into an essentials shortfall. Run at
        // the production path count (the two points are independent samples, not a CRN pair, so the
        // delta carries full sampling noise — a tight per-point estimate keeps the inequality robust).
        $household = $this->careSensitiveCouple();
        $paths = 2_000;
        $seed = 9;

        $curve = $this->engine()->sweep(
            $household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            new CareModellingLever, [0.0, 1.0], SweepMetric::Essentials, nPaths: $paths, seed: $seed,
        );

        [$off, $on] = $curve->points;
        $this->assertSame(0.0, $off->leverValue);
        $this->assertSame(1.0, $on->leverValue);

        // The lever carries the care flag through the sweep pipeline: modelling the fat care tail
        // lowers the chance the money lasts (care is a cost — it can only lower success, never raise it).
        $this->assertLessThan(
            $off->successProbability,
            $on->successProbability,
            'modelling the care tail lowers the chance the money lasts',
        );

        // Completeness (no silent drop): care demonstrably REACHES the on-state result — it occurs in
        // a real share of paths, with a real bill — so the lower success is care biting, not noise. The
        // care-off run reports no such impact (the risk is surfaced explicitly, not buried).
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
        $simulator = new Simulator($config);
        $onResult = $simulator->run($household, $this->settings()->withModelCareCost(true), AssumptionSetLibrary::default(), new CohortLifeTable, $paths, $seed);
        $offResult = $simulator->run($household, $this->settings()->withModelCareCost(false), AssumptionSetLibrary::default(), new CohortLifeTable, $paths, $seed);

        $this->assertNull($offResult->careImpact, 'care off: no care impact reported');
        $this->assertNotNull($onResult->careImpact, 'care on: the impact is reported');
        $this->assertGreaterThan(0.0, $onResult->careImpact->shareOfPathsWithCare, 'care actually occurs across the futures');
        $this->assertTrue($onResult->careImpact->medianCareCost->isPositive(), 'with a real bill');
    }

    /**
     * A couple leaning on a modest pot: two full State Pensions (~£25k) below the £30k essential
     * floor, so the £200k pot funds the gap — comfortably without care, but a six-figure end-of-life
     * care spell can exhaust it, tipping those paths into an essentials shortfall. This exposes the
     * fat-tail care risk the lever is built to surface (high success without care, materially lower
     * with it).
     */
    private function careSensitiveCouple(): Household
    {
        return new Household(
            'Care sensitive',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1955-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(200_000), Money::zero(), Money::zero(), earliestAccessAge: 55),
            ],
        );
    }
}
