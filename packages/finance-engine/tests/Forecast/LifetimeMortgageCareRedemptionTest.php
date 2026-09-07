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
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
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
 * Board card 0056. Permanent entry into residential care by the LAST surviving borrower is a
 * redemption event under every standard equity-release contract: the home is sold and the lender
 * is paid first. The projector used to settle a rolled-up balance only at the end of the path, so
 * the tool showed that household living in its home to the end of the plan with an estate.
 *
 * The care spell is injected through the deterministic care stress, which places one episode on
 * the last-surviving person — exactly the borrower the contract turns on.
 */
final class LifetimeMortgageCareRedemptionTest extends TestCase
{
    /** Zero inflation and zero house growth, so real == nominal and the balance is predictable. */
    private function flatEconomy()
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', homeToDescendants: true);
    }

    private function withCare(Household $household): ForecastResult
    {
        return $this->forecaster()->forecastWithCareStress(
            $household, $this->flatEconomy(), $this->settings(), CareStressScenario::adverseDefault(),
        );
    }

    /** The first year of the injected care spell — the first year anyone is charged for care. */
    private function firstCareYear(ForecastResult $result): int
    {
        foreach ($result->years as $i => $year) {
            if ($year->essentialSpend->pence > 50_000_00) { // a nursing fee dwarfs ordinary essentials
                return $i;
            }
        }

        self::fail('no care year found in the projection');
    }

    /**
     * #1 — the last surviving borrower goes into care with a rolled-up balance on the home: the
     * home is sold that year and the lender is paid out of the proceeds.
     */
    public function test_care_entry_by_the_last_borrower_sells_the_home_and_repays_the_roll_up(): void
    {
        $result = $this->withCare($this->couple(500_000, 100_000, Percent::fromPercent(6.5)));
        $care = $this->firstCareYear($result);

        $before = $result->years[$care - 1];
        $this->assertTrue($before->propertyWealth->isPositive(), 'the home is still held the year before care');
        $this->assertTrue($before->mortgageBalance()->isPositive(), 'and it still carries a rolled-up balance');

        $year = $result->years[$care];
        $this->assertSame(0, $year->propertyWealth->pence, 'the home is sold in the year care begins');
        $this->assertSame(0, $year->mortgageBalance()->pence, 'and the roll-up balance is redeemed from the sale');

        // The residue reaches the household as spendable money, not as a figure that vanishes.
        $this->assertGreaterThan(
            $before->liquidWealth->pence,
            $year->liquidWealth->pence,
            'the equity left after the lender is credited to liquid assets',
        );
    }

    /**
     * #2 — the year of the sale is assessed on the NEW position: no home, and the proceeds in
     * hand. A household whose care fee its cash cannot meet used to defer the unfundable part onto
     * the home (board card 0055); once the home is sold there is nothing to defer onto and nothing
     * to defer, because the proceeds pay the fee.
     */
    public function test_the_household_is_reassessed_on_the_proceeds_with_no_home(): void
    {
        $result = $this->withCare($this->lonePropertyRich());
        $care = $this->firstCareYear($result);

        foreach (array_slice($result->years, $care) as $year) {
            $this->assertSame(0, $year->propertyWealth->pence, "year {$year->calendarYear}: no home is held");
            $this->assertSame(
                0,
                $year->deferredCareBalance()->pence,
                "year {$year->calendarYear}: nothing is deferred onto a home that has been sold",
            );
            $this->assertTrue($year->essentialsMet, "year {$year->calendarYear}: the fee is met from the proceeds");
        }
    }

    /**
     * The trigger is the LAST borrower. While the other one is still living in the home, the
     * contract has not matured and the home is not sold.
     *
     * Both partners die in 2045; the stress lands its spell on p1 (the tie goes to the first
     * declared), so p1 is in care from 2042 while p2 lives on at home to 2044.
     */
    public function test_one_borrower_in_care_while_the_other_lives_there_does_not_redeem(): void
    {
        $result = $this->withCare($this->couple(500_000, 100_000, Percent::fromPercent(6.5), firstDeathAge: 90));
        $care = $this->firstCareYear($result);

        $year = $result->years[$care];
        $this->assertSame(2, $year->aliveCount, 'the spell starts while both borrowers are alive');
        $this->assertTrue($year->propertyWealth->isPositive(), 'the home is kept while a borrower still lives in it');
        $this->assertTrue($year->mortgageBalance()->isPositive(), 'so the balance is still owed');
    }

    /** An ordinary serviced mortgage is not an equity-release plan, so care does not call it in. */
    public function test_a_mortgage_with_no_roll_up_rate_is_not_redeemed_on_entry_to_care(): void
    {
        $result = $this->withCare($this->couple(500_000, 100_000, null));
        $care = $this->firstCareYear($result);

        $this->assertTrue($result->years[$care]->propertyWealth->isPositive(), 'no equity-release contract, no redemption event');
        $this->assertSame(10_000_000, $result->years[$care]->mortgageBalance()->pence);
    }

    /**
     * A retired couple with plenty of assets, a home carrying a mortgage that either rolls up or
     * stays static. p2 outlives p1 unless $firstDeathAge is raised to tie with them.
     */
    private function couple(int $homeValue, int $mortgage, ?Percent $rollUpRate, int $firstDeathAge = 80): Household
    {
        return new Household(
            'Equity release',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge($firstDeathAge)),
                new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88)), // dies 2045
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(300_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(300_000)),
            ],
            primaryResidence: new Property(
                Money::fromPounds($homeValue),
                OwnershipType::Outright,
                outstandingMortgage: Money::fromPounds($mortgage),
                mortgageRollUpRate: $rollUpRate,
            ),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    /**
     * One person, most of their money in the bricks and a lifetime mortgage on them: the household
     * the card is about. Their cash cannot carry a nursing fee for a year, so under the old
     * behaviour the shortfall was deferred onto a home they were shown keeping to the end.
     */
    private function lonePropertyRich(): Household
    {
        return new Household(
            'Property rich, cash poor',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1942-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88))], // dies 2030
            new ExpenseProfile(Money::fromPounds(14_000), Money::zero(), Percent::fromPercent(100)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200))],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
            primaryResidence: new Property(
                Money::fromPounds(600_000),
                OwnershipType::Outright,
                outstandingMortgage: Money::fromPounds(50_000),
                mortgageRollUpRate: Percent::fromPercent(6.5),
            ),
        );
    }
}
