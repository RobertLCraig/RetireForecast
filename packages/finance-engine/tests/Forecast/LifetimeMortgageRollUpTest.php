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
 * A lifetime mortgage (equity release): when its {@see Property::$mortgageRollUpRate} is set and
 * no payments are made, the outstanding balance ROLLS UP — compounding at its fixed nominal rate
 * each year — and is repaid from the estate on death, capped at the home's value by the
 * No-Negative-Equity Guarantee. A null rate leaves the balance static (a repayment/interest-
 * serviced mortgage), the pre-existing behaviour. This is what makes the "roll-up vs serviced"
 * equity-release comparison faithful: the roll-up frees the cashflow now but hollows out the
 * inheritance, and that estate erosion has to reach the result.
 */
final class LifetimeMortgageRollUpTest extends TestCase
{
    /** Zero inflation and zero house growth, so the nominal roll-up is exactly the real figure and the home value is flat — the balance is predictable to the penny. */
    private function flatEconomy()
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    /**
     * A retired couple with plenty of assets (so nothing depletes to disturb the home), a
     * £{$homeValue} home carrying a £{$mortgage} mortgage that either rolls up at $rollUpRate or
     * stays static (null).
     */
    private function couple(int $homeValue, int $mortgage, ?Percent $rollUpRate): Household
    {
        return new Household(
            'Equity release',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(80)),   // dies 2035
                new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88)), // dies 2045 (final)
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
                new DcPension('p1', Money::fromPounds(300_000), Money::zero(), Money::zero(), earliestAccessAge: 57, withdrawalPlan: []),
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

    private function forecast(Household $h, bool $modelIht = false): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatEconomy(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                modelIht: $modelIht,
                homeToDescendants: true,
            ));
    }

    public function test_an_unpaid_roll_up_balance_compounds_at_its_fixed_rate_to_the_penny(): void
    {
        // £100k at 6.5% on a £500k home (well below the NNEG cap for the years asserted).
        $result = $this->forecast($this->couple(500_000, 100_000, Percent::fromPercent(6.5)));

        $balances = array_map(static fn ($y) => $y->mortgageBalance()->pence, $result->years);
        $this->assertGreaterThan(5, count($balances));

        // The balance rolls up each year: next == round(prev × 1.065), exactly — the same
        // integer-pence rounding the engine applies (zero inflation, so real == nominal).
        for ($i = 0; $i < 5; $i++) {
            $expected = (int) round($balances[$i] * 1.065);
            $this->assertSame($expected, $balances[$i + 1], "year {$i}→".($i + 1).' roll-up');
        }

        // And it genuinely grows (not a static balance dressed up).
        $this->assertGreaterThan($balances[0], $balances[5]);
    }

    public function test_a_static_mortgage_does_not_roll_up(): void
    {
        // Same home and starting mortgage, but no roll-up rate: the balance stays put (the
        // pre-existing behaviour — a repayment/interest-serviced mortgage, interest as an expense).
        $result = $this->forecast($this->couple(500_000, 100_000, null));

        $balances = array_map(static fn ($y) => $y->mortgageBalance()->pence, $result->years);
        foreach ($balances as $b) {
            $this->assertSame(10_000_000, $b, 'a static mortgage balance never changes');
        }
    }

    public function test_the_no_negative_equity_guarantee_caps_the_balance_at_the_home_value(): void
    {
        // A small home (£120k) and a £100k roll-up: within a few years the compounding balance
        // overtakes the home value and must be capped there — the debt can never exceed the home.
        $result = $this->forecast($this->couple(120_000, 100_000, Percent::fromPercent(6.5)));

        $last = $result->years[count($result->years) - 1];
        $this->assertSame(12_000_000, $last->mortgageBalance()->pence, 'the balance is capped at the £120k home value');
        $this->assertSame(0, $last->homeEquity()->pence, 'a fully rolled-up home leaves no equity (NNEG floor)');

        // No year ever shows the balance above the home value.
        foreach ($result->years as $y) {
            $this->assertLessThanOrEqual($y->propertyWealth->pence, $y->mortgageBalance()->pence);
        }

        // Net worth excludes the consumed home entirely and reconciles from its parts.
        $this->assertSame(
            $last->liquidWealth->plus($last->pensionWealth)->pence,
            $last->netWealth()->pence,
            'net worth = liquid + pension when the home equity is gone',
        );
    }

    public function test_net_worth_is_total_wealth_less_the_mortgage(): void
    {
        $result = $this->forecast($this->couple(500_000, 100_000, Percent::fromPercent(6.5)));
        $y = $result->years[3];

        // netWealth is reconciled from the reported legs (liquid + pension + home equity), and is
        // below the gross totalWealth by exactly the mortgage owed (home equity still positive here).
        $this->assertSame(
            $y->liquidWealth->plus($y->pensionWealth)->plus($y->homeEquity())->pence,
            $y->netWealth()->pence,
        );
        $this->assertSame($y->totalWealth->minus($y->mortgageBalance())->pence, $y->netWealth()->pence);
        $this->assertLessThan($y->totalWealth->pence, $y->netWealth()->pence);
    }

    public function test_a_roll_up_erodes_the_estate_versus_a_serviced_mortgage(): void
    {
        // Same household, same starting £100k mortgage; one rolls up unpaid, one stays serviced.
        $rollUp = $this->forecast($this->couple(500_000, 100_000, Percent::fromPercent(6.5)), modelIht: true);
        $served = $this->forecast($this->couple(500_000, 100_000, null), modelIht: true);

        $this->assertNotNull($rollUp->iht);
        $this->assertNotNull($served->iht);

        // The rolled-up debt (a decade+ of compounding) is far larger at the final death, so the
        // estate net of it — what actually passes to heirs — is materially smaller.
        $this->assertLessThan(
            $served->iht->secondDeath->totalEstate->pence,
            $rollUp->iht->secondDeath->totalEstate->pence,
            'an unpaid roll-up leaves a smaller estate than a serviced mortgage',
        );

        // Terminal net worth (net of the grown debt) is lower under the roll-up too — the estate
        // erosion reaches the wealth line, not just the IHT panel.
        $rollUpFinal = $rollUp->years[count($rollUp->years) - 1];
        $servedFinal = $served->years[count($served->years) - 1];
        $this->assertLessThan($servedFinal->netWealth()->pence, $rollUpFinal->netWealth()->pence);
    }
}
