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
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
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
        // Sell £400k → net £384k; buy £500k + £15k SDLT + £2k moving = £517k. No savings, no
        // mortgage → £133k unfunded. The gap must land as a year-0 one-off charge. Ordinary
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
        $this->assertSame(Money::fromPounds(133_000)->pence, $oneOffs[0]['amount']->pence);

        // Projected, the gap is unmet spend in year 0 — a visible failure, not free equity.
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $year0 = $forecast->years[0];
        $this->assertSame(2026, $year0->calendarYear);
        $this->assertSame(Money::fromPounds(133_000)->pence, $year0->unmetSpend->pence, 'the whole gap is unmet — nothing funds it');
        foreach (array_slice($forecast->years, 1) as $year) {
            $this->assertSame(0, $year->unmetSpend->pence, "year {$year->calendarYear} is an ordinary, exactly-met year");
        }

        // The whole of that unmet spend is the ONE-OFF lump, not the recurring budget: the
        // household's year-to-year spending was met in full, in year 0 and in every year after.
        $this->assertSame(Money::fromPounds(133_000)->pence, $year0->unmetOneOffSpend()->pence);
        $this->assertTrue($year0->fullSpendMet(), 'the recurring budget was met — only the purchase lump was not');
    }

    /**
     * The card-0025 defect. A year-0 purchase gap is a constant, identical on every sampled path,
     * so folding it into the all-or-nothing full-spend test reported a fifty-year plan whose
     * ordinary spending is met in EVERY year as a total failure (and, in the Monte Carlo, as a
     * full-spend probability of exactly 0.000) while the essentials measure read a clean 100%.
     */
    public function test_an_unfunded_one_off_does_not_fail_the_full_spend_measure_on_the_whole_path(): void
    {
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
            essentialPounds: 17_114,
        );

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $this->assertTrue($forecast->fullSpendAlwaysMet, 'the recurring spending target is met in every year');
        $this->assertSame(1.0, $forecast->fullSpendYearsMetFraction());

        // AC #3: the two measures may not diverge by more than the years actually unfunded. No
        // year's recurring budget went short here, so they must agree exactly.
        $this->assertSame($forecast->essentialsYearsMetFraction(), $forecast->fullSpendYearsMetFraction());
    }

    /**
     * AC #2: the gap is not simply forgiven for being a lump. It stays inside the year's unmet
     * spend, and the year carries its own warning NAMING the cost and its size, so the reader is
     * told which purchase has no money behind it rather than reading a depressed probability.
     */
    public function test_an_unfunded_one_off_raises_a_distinct_warning_naming_the_cost(): void
    {
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
            essentialPounds: 17_114,
        );

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $unfunded = array_values(array_filter(
            $forecast->years[0]->warnings,
            static fn (Warning $w): bool => $w->code === WarningCode::UNFUNDED_ONE_OFF_COST,
        ));

        $this->assertCount(1, $unfunded);
        $this->assertStringContainsString('Unfunded purchase shortfall', $unfunded[0]->message);
        $this->assertStringContainsString(Money::fromPounds(133_000)->format(), $unfunded[0]->message);

        // No later year repeats it: the cost falls once, so the warning does too.
        foreach (array_slice($forecast->years, 1) as $year) {
            $this->assertSame([], array_filter(
                $year->warnings,
                static fn (Warning $w): bool => $w->code === WarningCode::UNFUNDED_ONE_OFF_COST,
            ), "year {$year->calendarYear} carries no unfunded-cost warning");
        }
    }

    /**
     * The separation is an ATTRIBUTION, not an amnesty: recurring spend is funded first, so a
     * household short of BOTH still fails on the recurring part. Here the essential floor is
     * lifted well above the net income with nothing to draw on, so the ordinary budget goes short
     * every year and the fraction reflects it.
     */
    public function test_a_household_short_of_its_recurring_budget_still_fails_beside_an_unfunded_one_off(): void
    {
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
            essentialPounds: 30_000,
        );

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($buy, $this->flat(), $settings);

        $this->assertFalse($forecast->fullSpendAlwaysMet);
        $this->assertSame(0.0, $forecast->fullSpendYearsMetFraction());
        $this->assertFalse($forecast->essentialsAlwaysMet);

        // The year-0 shortfall is bigger than the purchase gap, and only the gap is attributed to
        // the one-off — the rest is the recurring budget, which is what fails the measure.
        $year0 = $forecast->years[0];
        $this->assertGreaterThan($year0->unmetOneOffSpend()->pence, $year0->unmetSpend->pence);
        $this->assertSame(Money::fromPounds(133_000)->pence, $year0->unmetOneOffSpend()->pence);
    }

    /**
     * The headline symptom the card names: because the purchase gap is a year-0 constant,
     * independent of the sampled draws, it used to produce unmet spend on 100% of paths and the
     * Monte Carlo full-spend probability read exactly 0.000 while the essentials figure read 1.000.
     * Both must now agree, and the "met in most years" companion must agree with them.
     */
    public function test_the_monte_carlo_full_spend_probability_is_not_zeroed_by_the_purchase_gap(): void
    {
        [$buy, $settings] = $this->buyHousehold(
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
            essentialPounds: 17_114,
        );

        $mc = (new Simulator(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)))
            ->run($buy, $settings, $this->flat(), new CohortLifeTable, nPaths: 40, seed: 7);

        $this->assertSame(1.0, $mc->successProbabilityEssentials);
        $this->assertSame(1.0, $mc->successProbabilityFullSpend, 'the gap must no longer zero this');
        $this->assertSame(1.0, $mc->successProbabilityFullSpendMostYears);
    }

    /**
     * AC #3, as a standing invariant rather than two worked examples: a year may only be counted
     * against the full-spend measure when its RECURRING budget genuinely went short. An unfunded
     * one-off on its own must never do it, so the two measures can never diverge by more than the
     * years actually unfunded. This is the assertion the pre-fix engine failed.
     */
    public function test_the_two_measures_diverge_only_by_years_genuinely_short(): void
    {
        // A comfortably-funded household beside one whose essential floor is far above its income:
        // the first has only the purchase gap unmet, the second is short every year as well.
        foreach ([17_114, 30_000] as $essentialPounds) {
            [$buy, $settings] = $this->buyHousehold(
                new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000)),
                essentialPounds: $essentialPounds,
            );

            $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
                ->forecast($buy, $this->flat(), $settings);

            $genuinelyShort = 0;
            foreach ($forecast->years as $year) {
                if ($year->fullSpendMet()) {
                    continue;
                }
                $genuinelyShort++;
                $this->assertGreaterThan(
                    0,
                    $year->unmetSpend->minus($year->unmetOneOffSpend())->pence,
                    "{$year->calendarYear} is counted short with nothing but a one-off lump behind it",
                );
            }

            $this->assertLessThanOrEqual(
                $genuinelyShort / count($forecast->years),
                $forecast->essentialsYearsMetFraction() - $forecast->fullSpendYearsMetFraction(),
                "essentials and full spend diverge by more than the {$genuinelyShort} years actually unfunded",
            );
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
