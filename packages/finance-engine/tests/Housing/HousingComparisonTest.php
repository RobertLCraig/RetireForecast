<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class HousingComparisonTest extends TestCase
{
    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** A house-rich, cash-poor couple who need more income than their pensions give. */
    private function houseRichCashPoor(): Household
    {
        return new Household(
            'House-rich',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(30_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                runningCosts: Money::fromPounds(4_000),
            ),
        );
    }

    private function action(): HousingAction
    {
        return new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(200_000),
            annualRent: Money::fromPounds(14_000),
            rentInflationReal: Percent::fromPercent(0.5),
        );
    }

    public function test_compare_returns_all_three_variants(): void
    {
        $results = $this->comparison()->compare(
            $this->houseRichCashPoor(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $this->action(),
            nPaths: 120,
            seed: 11,
        );

        $this->assertArrayHasKey('stay_put', $results);
        $this->assertArrayHasKey('buy_outright', $results);
        $this->assertArrayHasKey('rent', $results);
        $this->assertSame(120, $results['stay_put']->nPaths);
    }

    public function test_downsizing_frees_liquidity_and_improves_success(): void
    {
        $results = $this->comparison()->compare(
            $this->houseRichCashPoor(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $this->action(),
            nPaths: 150,
            seed: 3,
        );

        // Staying put leaves the couple cash-poor (equity locked in the home), so
        // they run short; buying cheaper frees ~£190k of investable surplus.
        $this->assertGreaterThan(
            $results['stay_put']->successProbabilityEssentials,
            $results['buy_outright']->successProbabilityEssentials,
        );
    }

    public function test_comparison_is_reproducible_on_the_same_seed(): void
    {
        $a = $this->comparison()->compare($this->houseRichCashPoor(), new ForecastSettings(baseYear: 2026), AssumptionSetLibrary::default(), $this->action(), 80, seed: 9);
        $b = $this->comparison()->compare($this->houseRichCashPoor(), new ForecastSettings(baseYear: 2026), AssumptionSetLibrary::default(), $this->action(), 80, seed: 9);

        $this->assertSame(
            $a['buy_outright']->terminalWealthPercentiles['p50']->pence,
            $b['buy_outright']->terminalWealthPercentiles['p50']->pence,
        );
        $this->assertSame(
            $a['rent']->successProbabilityEssentials,
            $b['rent']->successProbabilityEssentials,
        );
    }
}
