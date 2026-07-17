<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The no-magic-money rule for a home purchase: any part of the buy that no documented
 * source funds (proceeds → savings → mortgage) is charged as a year-0 one-off cost, so
 * the projection shows the shortfall — the plan visibly fails instead of being handed
 * the home for free. A fully funded buy carries no such charge.
 */
final class UnfundedPurchaseTest extends TestCase
{
    /** Zero growth + zero inflation, so nominal == real and every figure is the entered value. */
    private function flat(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * A household whose income, net of tax, exactly covers its ordinary spend (the tuned
     * essential floor + £2k discretionary + the bought home's default 1%-of-value running
     * costs), so the ONLY possible unmet spend is the unfunded purchase gap. The income is
     * TAXABLE on purpose: a tax-free stream is disregarded by the Pension Credit means test,
     * and the award would quietly co-fund the gap. £27,000 gross − £2,886 tax (PA £12,570,
     * 20% basic) = £24,114 net, and no Pension Credit at that income. The survivor factor is
     * 100% because a single-person household is charged survivor-level spend from the start.
     */
    private function household(int $essentialPounds): Household
    {
        return new Household(
            'Unfunded',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds($essentialPounds), Money::fromPounds(2_000), Percent::fromPercent(100)),
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(27_000), true, false, 60)],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
            ),
        );
    }

    private function buyHousehold(HousingAction $action, int $essentialPounds): array
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $variants = (new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->variantInputs($this->household($essentialPounds), $settings, $this->flat(), $action);

        return [$variants['buy_outright']['household'], $settings];
    }

    public function test_an_unfunded_buy_charges_the_gap_as_a_year_zero_one_off_and_visibly_fails(): void
    {
        // Sell £400k → net £392k; buy £500k + £15k SDLT + £2k moving = £517k. No savings, no
        // mortgage → £125k unfunded. The gap must land as a year-0 one-off charge. Ordinary
        // spend = £17,114 essential + £2,000 discretionary + £5,000 running costs (1% of £500k)
        // = the £24,114 net income exactly, so every ordinary year is exactly met.
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
            essentialPounds: 17_114,
        );

        $oneOffs = $buy->expenseProfile->oneOffCosts;
        $this->assertCount(1, $oneOffs);
        $this->assertSame('Unfunded purchase shortfall', $oneOffs[0]['label']);
        $this->assertSame(68, $oneOffs[0]['atAge'], 'keyed to the first person\'s base-year age (born 1958, base 2026)');
        $this->assertSame(Money::fromPounds(125_000)->pence, $oneOffs[0]['amount']->pence);

        // Projected, the gap is unmet spend in year 0 — a visible failure, not free equity.
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $year0 = $forecast->years[0];
        $this->assertSame(2026, $year0->calendarYear);
        $this->assertSame(Money::fromPounds(125_000)->pence, $year0->unmetSpend->pence, 'the whole gap is unmet — nothing funds it');
        $this->assertFalse($forecast->fullSpendAlwaysMet);
        foreach (array_slice($forecast->years, 1) as $year) {
            $this->assertSame(0, $year->unmetSpend->pence, "year {$year->calendarYear} is an ordinary, exactly-met year");
        }
    }

    public function test_a_fully_funded_buy_carries_no_one_off_charge_and_never_fails(): void
    {
        // Buying well below the proceeds: surplus invested, nothing unfunded, no charge. Net
        // income covers the target + the £2k running costs (1% of £200k); the invested surplus
        // mops up any residue.
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000)),
            essentialPounds: 20_114,
        );

        $this->assertSame([], $buy->expenseProfile->oneOffCosts);

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $this->assertTrue($forecast->fullSpendAlwaysMet);
    }
}
