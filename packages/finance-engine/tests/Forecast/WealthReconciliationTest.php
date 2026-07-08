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
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Reconciliation invariant for the wealth boundary: total wealth must always equal the
 * sum of its parts (liquid + pension + home EQUITY, i.e. property net of any mortgage,
 * NNEG-floored), every year and at the terminal year, and the two terminal headlines the
 * UI leads with (usable vs total) must reconcile to that final year. This is the
 * data-layer integrity rule applied to the forecast output — a stored/reported total can
 * never drift from the components it is built from, and it never counts the lender's
 * share of the bricks as the household's wealth.
 */
final class WealthReconciliationTest extends TestCase
{
    private function forecast(bool $withRollUpMortgage = false): ForecastResult
    {
        // A comfortable couple who never deplete, with a home and an ISA, so every wealth
        // leg (liquid, pension, property) is non-trivial throughout the projection.
        $household = new Household(
            'Reconcile',
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
                outstandingMortgage: $withRollUpMortgage ? Money::fromPounds(100_000) : null,
                mortgageRollUpRate: $withRollUpMortgage ? Percent::fromPercent(6.5) : null,
            ),
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_every_year_total_wealth_equals_its_parts(): void
    {
        // Mortgage-free and mortgaged alike: the same one definition must hold.
        foreach ([false, true] as $withRollUpMortgage) {
            $result = $this->forecast($withRollUpMortgage);
            $this->assertNotEmpty($result->years);

            foreach ($result->years as $year) {
                $this->assertSame(
                    $year->totalWealth->pence,
                    $year->liquidWealth->pence + $year->pensionWealth->pence + $year->homeEquity()->pence,
                    "total wealth must equal liquid + pension + home equity in {$year->calendarYear}",
                );
            }
        }
    }

    public function test_a_mortgage_is_never_counted_as_the_household_wealth(): void
    {
        // Completeness guard for the liability: the rolled-up debt must reach every total —
        // gross bricks (liquid + pension + property, ignoring the mortgage) would be higher,
        // and that difference is exactly the (NNEG-capped) balance owed.
        $result = $this->forecast(withRollUpMortgage: true);

        foreach ($result->years as $year) {
            $this->assertSame(
                $year->liquidWealth->pence + $year->pensionWealth->pence
                    + max(0, $year->propertyWealth->pence - $year->mortgageBalance()->pence),
                $year->totalWealth->pence,
                "the mortgage owed must be netted off total wealth in {$year->calendarYear}",
            );
            $this->assertLessThan(
                $year->liquidWealth->pence + $year->pensionWealth->pence + $year->propertyWealth->pence,
                $year->totalWealth->pence,
                "gross property must never be reported as wealth while a mortgage is owed ({$year->calendarYear})",
            );
        }

        // And the debt reaches the terminal HEADLINE the UI and assistant lead with — the
        // figure this guard exists for (a roll-up once reached every year row but not this).
        $terminal = $result->years[array_key_last($result->years)];
        $this->assertSame($terminal->totalWealth->pence, $result->terminalTotalWealth->pence);
        $this->assertLessThan(
            $result->terminalUsableWealth->pence + $terminal->propertyWealth->pence,
            $result->terminalTotalWealth->pence,
            'the terminal headline must be net of the rolled-up mortgage, not gross bricks',
        );
    }

    public function test_terminal_headlines_reconcile_to_the_final_year(): void
    {
        $result = $this->forecast();
        $terminal = $result->years[array_key_last($result->years)];

        $this->assertSame($terminal->calendarYear, $result->finalCalendarYear);
        $this->assertSame($terminal->totalWealth->pence, $result->terminalTotalWealth->pence);

        // Usable wealth is the spendable part (liquid + pension); total adds the illiquid
        // home's equity (== full property value here: no mortgage in this fixture).
        $this->assertSame(
            $terminal->liquidWealth->pence + $terminal->pensionWealth->pence,
            $result->terminalUsableWealth->pence,
        );
        $this->assertSame(
            $result->terminalUsableWealth->pence + $terminal->homeEquity()->pence,
            $result->terminalTotalWealth->pence,
        );
    }

    public function test_property_leg_actually_contributes(): void
    {
        // Guard against a vacuous reconciliation: the home must genuinely add to total
        // wealth, so usable and total are not the same figure.
        $result = $this->forecast();
        $terminal = $result->years[array_key_last($result->years)];

        $this->assertTrue($terminal->propertyWealth->isPositive());
        $this->assertNotSame($result->terminalUsableWealth->pence, $result->terminalTotalWealth->pence);
    }
}
