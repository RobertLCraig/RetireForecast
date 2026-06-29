<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Contingent costs (one home per cost, charged only while its condition holds — the
 * correctness fix for the buy-vs-rent bias). The reconciliation invariants the plan
 * requires: the current home's housing-linked costs are charged **only** in "stay put"
 * (the sell variants drop them, so the comparison stops paying a phantom mortgage on a
 * property it no longer owns), and employment-linked costs (commuting) are charged **only
 * while someone earns** (so they stop from the retirement year).
 */
final class ContingentCostsTest extends TestCase
{
    public function test_without_property_costs_reduces_the_essential_floor_and_zeroes_the_marker(): void
    {
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(30_000),
            discretionaryAnnualSpend: Money::fromPounds(10_000),
            survivorSpendFactor: Percent::fromPercent(70),
            propertyCosts: Money::fromPounds(12_000),
        );
        $sold = $profile->withoutPropertyCosts();

        // The £12k housing cost comes out of the essential floor; discretionary is untouched.
        $this->assertSame(Money::fromPounds(18_000)->pence, $sold->essentialAnnualSpend->pence);
        $this->assertSame(Money::fromPounds(10_000)->pence, $sold->discretionaryAnnualSpend->pence);
        $this->assertSame(0, $sold->propertyCosts()->pence);
        $this->assertSame(Money::fromPounds(28_000)->pence, $sold->targetAnnualSpend()->pence);
        // The original is unchanged (immutable).
        $this->assertSame(Money::fromPounds(40_000)->pence, $profile->targetAnnualSpend()->pence);
    }

    public function test_property_costs_are_charged_only_in_the_stay_put_variant(): void
    {
        $variants = $this->comparison()->variantInputs(
            $this->owners(propertyCosts: Money::fromPounds(12_000)),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000), annualRent: Money::fromPounds(14_000)),
        );

        // Stay put keeps the mortgage/service charge; both sell variants drop them entirely.
        $this->assertSame(Money::fromPounds(12_000)->pence, $variants['stay_put']['household']->expenseProfile->propertyCosts()->pence);
        $this->assertSame(0, $variants['buy_outright']['household']->expenseProfile->propertyCosts()->pence);
        $this->assertSame(0, $variants['rent']['household']->expenseProfile->propertyCosts()->pence);

        // The sell variants' target spend is exactly £12k lower — the costs removed, nothing else.
        $stay = $variants['stay_put']['household']->expenseProfile->targetAnnualSpend()->pence;
        $this->assertSame($stay - Money::fromPounds(12_000)->pence, $variants['rent']['household']->expenseProfile->targetAnnualSpend()->pence);
        $this->assertSame($stay - Money::fromPounds(12_000)->pence, $variants['buy_outright']['household']->expenseProfile->targetAnnualSpend()->pence);
    }

    public function test_employment_costs_stop_when_the_earner_retires(): void
    {
        // P1 employed, born 1962, retires at 67 (so works through 2028, retired from 2029);
        // a £3,000 commute sits within essential spend.
        $household = $this->worker(employmentCosts: Money::fromPounds(3_000), retireAge: 67);
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $spend = [];
        foreach ($forecast->years as $year) {
            $spend[$year->calendarYear] = $year->spendTarget->pence;
        }

        // 2028 (age 66, still working) charges the commute; 2029 (age 67, retired) drops it.
        // Spend is real terms and otherwise flat across these all-alive years, so the fall is
        // the commute (allow a few pounds of deflation rounding).
        $this->assertArrayHasKey(2028, $spend);
        $this->assertArrayHasKey(2029, $spend);
        $this->assertEqualsWithDelta(Money::fromPounds(3_000)->pence, $spend[2028] - $spend[2029], Money::fromPounds(50)->pence);
    }

    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function owners(Money $propertyCosts): Household
    {
        return new Household(
            'Owners',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(30_000), Money::fromPounds(5_000), Percent::fromPercent(70), propertyCosts: $propertyCosts),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
        );
    }

    private function worker(Money $employmentCosts, int $retireAge): Household
    {
        // A couple (both alive across the retirement boundary) so the survivor factor stays
        // 1.0 and the spend fall is the commute itself, not a survivor-scaled version of it.
        return new Household(
            'Worker',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1962-06-15'), Sex::Male, EmploymentStatus::Employed, grossSalary: Money::fromPounds(40_000), plannedRetirementAge: $retireAge),
                new Person('p2', new DateTimeImmutable('1960-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70), employmentCosts: $employmentCosts),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
        );
    }
}
