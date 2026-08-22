<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Every reported year carries {@see YearResult::$nominal}: the SAME year in the projector's own
 * pre-deflation pounds. This is what a nominal-pounds view on screen must read, and the reason it
 * exists is that the obvious alternative (multiplying the reported real figure back up by the
 * price level) is not the engine's arithmetic: deflation rounds to the penny, so re-inflating a
 * rounded figure recovers a number the projector never held.
 *
 * The two views are the same money on two yardsticks, so these pin the cases where that can be
 * checked exactly: with no inflation the two must be identical figure for figure, and with
 * inflation they must still be identical in the base year (price level 1.0) and must deflate back
 * to one another in every later year. Plus the guard against a vacuous pass: with inflation on,
 * the nominal figures must actually be bigger.
 */
final class NominalTwinTest extends TestCase
{
    /** A comfortable couple with income, tax, spend, a home and pots, so every reported leg is non-trivial. */
    private function forecast(float $inflationPercent): ForecastResult
    {
        $household = new Household(
            'Nominal',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
            ],
            accounts: [new Account('p1', AccountType::Isa, Money::fromPounds(50_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                runningCosts: Money::fromPounds(3_000),
            ),
        );

        $assumptions = AssumptionSetLibrary::default()->withInflationMean(Percent::fromPercent($inflationPercent));

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable))
            ->forecast($household, $assumptions, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /**
     * Every money figure a year reports, as label => pence, so the two bases are compared
     * field for field rather than on a hand-picked few (a figure added to YearResult and
     * forgotten in the twin would otherwise pass silently).
     *
     * @return array<string, int>
     */
    private function figures(YearResult $year): array
    {
        $figures = [
            'grossIncome' => $year->grossIncome->pence,
            'totalTax' => $year->totalTax->pence,
            'netIncome' => $year->netIncome->pence,
            'spendTarget' => $year->spendTarget->pence,
            'essentialSpend' => $year->essentialSpend->pence,
            'shortfallFunded' => $year->shortfallFunded->pence,
            'unmetSpend' => $year->unmetSpend->pence,
            'liquidWealth' => $year->liquidWealth->pence,
            'pensionWealth' => $year->pensionWealth->pence,
            'propertyWealth' => $year->propertyWealth->pence,
            'totalWealth' => $year->totalWealth->pence,
            'homeEquity' => $year->homeEquity()->pence,
            'mortgageBalance' => $year->mortgageBalance()->pence,
            'investmentGrowth' => $year->investmentGrowth()->pence,
            'investmentCharges' => $year->investmentCharges()->pence,
        ];

        foreach ($year->incomeBySource as $source => $money) {
            $figures["income.{$source}"] = $money->pence;
        }

        return $figures;
    }

    public function test_every_year_carries_a_pre_deflation_twin_and_the_twin_carries_none(): void
    {
        $result = $this->forecast(3.0);
        $this->assertNotEmpty($result->years);

        foreach ($result->years as $year) {
            $this->assertInstanceOf(YearResult::class, $year->nominal, "year {$year->calendarYear} must carry its nominal twin");
            // The twin is one level deep on purpose: it IS the nominal view, so it has no
            // second copy of itself to drift from.
            $this->assertNull($year->nominal->nominal);
            // Same year, same people, same lives: only the yardstick differs.
            $this->assertSame($year->calendarYear, $year->nominal->calendarYear);
            $this->assertSame($year->ages, $year->nominal->ages);
            $this->assertSame($year->essentialsMet, $year->nominal->essentialsMet);
        }
    }

    public function test_with_no_inflation_the_two_bases_are_the_same_figures(): void
    {
        // The known case: with a flat price level there is nothing to deflate, so real and
        // nominal must agree to the penny on every reported figure, every year.
        foreach ($this->forecast(0.0)->years as $year) {
            $this->assertSame(
                $this->figures($year),
                $this->figures($year->nominal),
                "real and nominal must be identical with no inflation ({$year->calendarYear})",
            );
        }
    }

    public function test_with_inflation_the_nominal_figures_deflate_back_to_the_reported_real_ones(): void
    {
        $inflation = 0.03;
        $result = $this->forecast($inflation * 100);

        foreach ($result->years as $year) {
            $priceLevel = (1.0 + $inflation) ** $year->yearIndex;
            $real = $this->figures($year);
            $nominal = $this->figures($year->nominal);
            $this->assertSame(array_keys($real), array_keys($nominal));

            foreach ($real as $label => $pence) {
                // Growth and charges are year N to N+1 flows, so the projector deflates them by
                // NEXT year's price level, not this year's (see PathProjector::project). The
                // twin holds them undivided, so deflating them back uses that same level.
                $level = in_array($label, ['investmentGrowth', 'investmentCharges'], true)
                    ? $priceLevel * (1.0 + $inflation)
                    : $priceLevel;

                if ($year->yearIndex === 0 && $level === 1.0) {
                    // The base year IS today, so its price level is exactly 1.0 and the two
                    // bases must coincide with no rounding slack at all.
                    $this->assertSame($pence, $nominal[$label], "{$label} must match in the base year");

                    continue;
                }

                // Deflating the engine's own nominal figure returns the reported real one. A
                // penny of slack, because the price level is a running product here and a power
                // there; anything larger would mean the two views are not the same money.
                $this->assertLessThanOrEqual(
                    1,
                    abs((int) round($nominal[$label] / $level) - $pence),
                    "{$label} must deflate back to the real figure in {$year->calendarYear}",
                );
            }
        }
    }

    public function test_inflation_makes_the_nominal_figures_visibly_larger(): void
    {
        // The guard against a vacuous pass: if the twin were the real figure by accident, every
        // assertion above would still hold. Late-life spend in cash pounds must be well clear of
        // the same spend in today's money.
        $result = $this->forecast(3.0);
        $terminal = $result->years[array_key_last($result->years)];

        $this->assertGreaterThanOrEqual(15, $terminal->yearIndex, 'the fixture must run long enough for inflation to bite');
        $this->assertGreaterThan(
            $terminal->spendTarget->pence * 1.4,
            $terminal->nominal->spendTarget->pence,
            'the nominal spend must have grown with prices',
        );
    }
}
