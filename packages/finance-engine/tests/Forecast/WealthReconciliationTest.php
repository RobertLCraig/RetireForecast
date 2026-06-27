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
 * sum of its parts (liquid + pension + property), every year and at the terminal year,
 * and the two terminal headlines the UI leads with (usable vs total) must reconcile to
 * that final year. This is the data-layer integrity rule applied to the forecast output —
 * a stored/reported total can never drift from the components it is built from.
 */
final class WealthReconciliationTest extends TestCase
{
    private function forecast(): ForecastResult
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
            ),
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_every_year_total_wealth_equals_its_parts(): void
    {
        $result = $this->forecast();
        $this->assertNotEmpty($result->years);

        foreach ($result->years as $year) {
            $this->assertSame(
                $year->totalWealth->pence,
                $year->liquidWealth->pence + $year->pensionWealth->pence + $year->propertyWealth->pence,
                "total wealth must equal liquid + pension + property in {$year->calendarYear}",
            );
        }
    }

    public function test_terminal_headlines_reconcile_to_the_final_year(): void
    {
        $result = $this->forecast();
        $terminal = $result->years[array_key_last($result->years)];

        $this->assertSame($terminal->calendarYear, $result->finalCalendarYear);
        $this->assertSame($terminal->totalWealth->pence, $result->terminalTotalWealth->pence);

        // Usable wealth is the spendable part (liquid + pension); total adds the illiquid home.
        $this->assertSame(
            $terminal->liquidWealth->pence + $terminal->pensionWealth->pence,
            $result->terminalUsableWealth->pence,
        );
        $this->assertSame(
            $result->terminalUsableWealth->pence + $terminal->propertyWealth->pence,
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
