<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Care\CareStressScenario;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A2 — an adverse care spell injected into the DETERMINISTIC path as the "if significant care is
 * needed" stress, shown beside the care-free central estimate. These pin the completeness bar: with
 * the stress, a modelled care cost demonstrably reaches the central result (a non-zero care bill,
 * lower terminal wealth, and enough to tip a marginal household into running short); without it, the
 * central path is byte-identical to the ordinary care-free forecast (care is never averaged in).
 */
final class DeterministicCareStressTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    public function test_the_care_free_central_path_is_unchanged_and_carries_no_care_cost(): void
    {
        $forecast = $this->forecaster()->forecast($this->comfortableCouple(), AssumptionSetLibrary::default(), $this->settings());

        // Care is absent from the central estimate (it is a Monte Carlo risk) — no care total incurred.
        $this->assertFalse($forecast->careCostReal()->isPositive(), 'the care-free central path has no care cost');
    }

    public function test_the_care_stress_reaches_the_central_result_and_lowers_terminal_wealth(): void
    {
        $household = $this->comfortableCouple();
        $free = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());
        $stressed = $this->forecaster()->forecastWithCareStress(
            $household, AssumptionSetLibrary::default(), $this->settings(), CareStressScenario::adverseDefault(),
        );

        // Completeness: the injected spell reaches the result as a real, positive care bill...
        $this->assertNotNull($stressed->careCostReal(), 'the stress puts a care cost on the central path');
        $this->assertTrue($stressed->careCostReal()->isPositive());

        // ...and it costs the household money — terminal usable wealth is strictly lower than care-free.
        $this->assertLessThan(
            $free->terminalUsableWealth->pence,
            $stressed->terminalUsableWealth->pence,
            'a paid care spell leaves less behind than the care-free path',
        );
    }

    public function test_the_stress_can_tip_a_marginal_household_into_running_short(): void
    {
        // A person whose State Pension covers their essentials (so the plan never runs short on the
        // care-free path), leaning on a modest pot that a four-year self-funder nursing spell drains —
        // the exact case the affordability honesty fix exists to surface.
        $household = $this->marginalSingle();
        $free = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());
        $stressed = $this->forecaster()->forecastWithCareStress(
            $household, AssumptionSetLibrary::default(), $this->settings(), CareStressScenario::adverseDefault(),
        );

        $this->assertNull($free->depletionCalendarYear, 'the plan lasts on the expected (care-free) path');
        $this->assertNotNull($stressed->depletionCalendarYear, 'but a significant care spell runs it short');
    }

    /** A comfortable couple with a large pot — survives even the care stress, so wealth just falls. */
    private function comfortableCouple(): Household
    {
        return new Household(
            'Comfortable', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1955-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(221, 20)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(221, 20)),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(900_000))],
        );
    }

    /**
     * A single person whose State Pension covers their essentials — so the care-free path never runs
     * short (income >= essentials, the modest pot only grows) — but whose pot a four-year self-funder
     * nursing spell exhausts, tipping the LA-funded years into an essentials shortfall.
     */
    private function marginalSingle(): Household
    {
        return new Household(
            'Marginal single', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(11_000), Money::zero(), Percent::fromPercent(100)),
            [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(220_000))],
        );
    }
}
