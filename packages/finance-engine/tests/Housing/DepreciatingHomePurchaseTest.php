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
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Buying a home that is NOT an ordinary appreciating freehold — the park-home case.
 *
 * Two things had to become expressible, because the defaults get a park home backwards:
 *  - {@see HousingAction::$buyGrowthOverride} — the bought home's own REAL growth, which may be
 *    NEGATIVE. Without it a bought home can only appreciate at the assumption set's house rate, so
 *    a depreciating home looks strictly better than it is (the estate is overstated by the whole
 *    difference, and a reader comparing plans on wealth left picks the wrong one).
 *  - {@see HousingAction::$buyRunningCosts} — the bought home's own annual cost. A park home's
 *    pitch fee is a flat charge unrelated to value, which the 1%-of-value maintenance proxy can
 *    understate by thousands a year.
 *
 * Both null = the pre-existing behaviour exactly, so every stored scenario is unaffected.
 */
final class DepreciatingHomePurchaseTest extends TestCase
{
    /** Flat economy: zero inflation and zero house growth, so real == nominal and figures are exact. */
    private function flatEconomy()
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    /** A retired couple in a £400,000 home with plenty of savings, so nothing depletes. */
    private function household(): Household
    {
        return new Household(
            'Downsizers',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90)),
                new Person('p2', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88)),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(220)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(220)),
            ],
            accounts: [new Account('p1', AccountType::Isa, Money::fromPounds(150_000))],
            primaryResidence: new Property(Money::fromPounds(400_000), OwnershipType::Outright),
        );
    }

    private function buyForecast(?Percent $growth, ?Money $runningCosts): ForecastResult
    {
        $action = new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(150_000),
            buyRunningCosts: $runningCosts,
            buyGrowthOverride: $growth,
        );

        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $assumptions = $this->flatEconomy();
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);

        $variants = (new HousingComparison($config, new CohortLifeTable))
            ->variantInputs($this->household(), $settings, $assumptions, $action);

        return (new DeterministicForecaster($config, new CohortLifeTable))
            ->forecast($variants['buy_outright']['household'], $assumptions, $variants['buy_outright']['settings']);
    }

    public function test_a_negative_growth_rate_makes_the_bought_home_lose_value(): void
    {
        // -8%/yr real on a £150,000 park home, zero inflation: each year's value is 92% of the last.
        $years = $this->buyForecast(Percent::fromPercent(-8), null)->years;

        $values = array_map(static fn ($y) => $y->propertyWealth->pence, $years);
        $this->assertSame(15_000_000, $values[0], 'the plan opens at the purchase price');

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(
                (int) round($values[$i] * 0.92),
                $values[$i + 1],
                "year {$i} to ".($i + 1).': the home must fall by 8%',
            );
        }

        // And it genuinely erodes — not a rounding wobble.
        $this->assertLessThan($values[0] / 2, $values[count($values) - 1], 'over the plan it loses most of its value');
    }

    public function test_a_depreciating_home_leaves_a_smaller_estate_than_an_appreciating_one(): void
    {
        // The reason this must be modelled: the same purchase, same costs, different growth. If the
        // engine cannot express depreciation it overstates the inheritance by the whole difference.
        $depreciating = $this->buyForecast(Percent::fromPercent(-8), null);
        $appreciating = $this->buyForecast(null, null);

        $this->assertLessThan(
            $appreciating->terminalTotalWealth->pence,
            $depreciating->terminalTotalWealth->pence,
            'a home that loses value must leave less behind',
        );
    }

    public function test_an_explicit_running_cost_replaces_the_one_percent_default(): void
    {
        // A £150,000 home defaults to £1,500/yr upkeep (1% of value). A park home's pitch fee is
        // £3,000/yr — so the default understates it by £1,500 a year, every year.
        $defaulted = $this->buyForecast(null, null);
        $pitchFee = $this->buyForecast(null, Money::fromPounds(3_000));

        $extra = $pitchFee->years[0]->essentialSpend->pence - $defaulted->years[0]->essentialSpend->pence;
        $this->assertSame(150_000, $extra, 'the explicit £3,000 replaces the £1,500 default (a £1,500 increase)');

        // ...and it is not ADDED to the default (that would be £4,500 of upkeep, a double count).
        $this->assertNotSame(300_000, $extra);
    }

    public function test_a_lower_running_cost_than_the_default_is_honoured_too(): void
    {
        // The point of the park-home option is cheaper running costs, so the field must be able to
        // go DOWN as well as up — £900/yr against the £1,500 default.
        $defaulted = $this->buyForecast(null, null);
        $cheap = $this->buyForecast(null, Money::fromPounds(900));

        $this->assertLessThan(
            $defaulted->years[0]->essentialSpend->pence,
            $cheap->years[0]->essentialSpend->pence,
            'a cheaper running cost must reduce the essential floor',
        );
    }

    public function test_both_fields_null_reproduce_the_previous_behaviour_exactly(): void
    {
        // Back-compatibility: every stored scenario passes null for both, and must be untouched.
        $a = $this->buyForecast(null, null);
        $b = $this->buyForecast(null, null);

        $this->assertSame($a->terminalTotalWealth->pence, $b->terminalTotalWealth->pence);
        // The bought home grows at the assumption set's (here zero) house rate, so it stays put.
        foreach ($a->years as $year) {
            $this->assertSame(15_000_000, $year->propertyWealth->pence, 'flat house growth leaves the value alone');
        }
    }

    public function test_a_positive_override_is_still_allowed(): void
    {
        // The field is a general growth override, not a park-home flag: a home expected to beat the
        // market can use it too.
        $years = $this->buyForecast(Percent::fromPercent(3), null)->years;

        $this->assertGreaterThan($years[0]->propertyWealth->pence, $years[5]->propertyWealth->pence);
    }
}
