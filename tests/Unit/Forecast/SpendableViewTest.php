<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use App\Import\MoneyText;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * "Available capital" and "monthly allowance" — the two figures a non-financial reader plans
 * against. Everything else the tool reports is annual, and net worth (the number a reader reaches
 * for) includes a home they cannot spend.
 *
 * The trust-critical properties, in order of how badly they would mislead if wrong:
 *  1. the monthly parts reconcile to the annual figure they are derived from (the data-layer rule
 *     applied to a derived unit — a total that drifts from its parts);
 *  2. a shortfall year reports what the plan can FUND, not what it targets (otherwise the tool
 *     tells a household it can spend money it does not have — in exactly the years it cannot);
 *  3. available capital excludes home equity, and pension money is never silently added to it.
 */
final class SpendableViewTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function forecast(array $overrides = []): ForecastResult
    {
        // A couple with a real discretionary layer, a big home and modest savings — so home equity
        // and available capital differ by a lot, and the exclusion is testable.
        $household = (new HouseholdAssembler)->household(array_merge([
            'householdName' => 'Spendable', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [
                ['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'dob' => '1945-01-01', 'sex' => 'male', 'employmentStatus' => 'retired', 'longevityMode' => 'fixed_age', 'longevityValue' => '82'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '90000', 'earliestAccessAge' => '57'],
            ],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '40000']],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'd1', 'amount' => '6000', 'category' => 'discretionary'],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '500000', 'ownership' => 'outright'],
        ], $overrides));

        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    private static function pence(string $formatted): int
    {
        return MoneyText::toPence($formatted);
    }

    public function test_the_monthly_parts_reconcile_to_the_funded_annual_spend(): void
    {
        // essential + free == allowance, and allowance x 12 == the funded annual spend to within
        // the rounding remainder. Divide-once: three intdiv results can each lose up to 11p.
        foreach (ResultPresenter::ladder($this->forecast())['rows'] as $row) {
            $allowance = self::pence($row['monthlyAllowance']);
            $essential = self::pence($row['monthlyEssential']);
            $free = self::pence($row['monthlyFree']);

            $this->assertSame(
                $allowance,
                $essential + $free,
                "year {$row['year']}: essential + free must equal the monthly allowance",
            );
        }
    }

    public function test_the_monthly_allowance_times_twelve_matches_the_annual_spend(): void
    {
        foreach (ResultPresenter::ladder($this->forecast())['rows'] as $row) {
            $annual = self::pence($row['spend']);
            $monthlyTimes12 = self::pence($row['monthlyAllowance']) * 12;

            // In a fully funded year the annual figure IS the target, so the two must agree bar
            // the divide-once remainder (< 12p).
            if ($row['shortfall'] === null) {
                $this->assertLessThan(
                    12,
                    abs($annual - $monthlyTimes12),
                    "year {$row['year']}: the monthly allowance must reconcile to the annual spend",
                );
            }
        }
    }

    public function test_a_shortfall_year_reports_what_the_plan_can_fund_not_what_it_targets(): void
    {
        // The defect this figure exists to fix: spendTarget is an input echoed back, so in a year
        // the plan cannot fund it promises money the household does not have.
        //
        // Needs a household that genuinely runs short: no savings and no pension pot, so a £26,000
        // spend meets only the two State Pensions (~£23,920) and the gap is real from year one.
        $rows = ResultPresenter::ladder($this->forecast([
            'accounts' => [],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
        ]))['rows'];

        $shortfallRows = array_values(array_filter($rows, static fn (array $r): bool => $r['shortfall'] !== null));
        $this->assertNotEmpty($shortfallRows, 'the fixture must run short somewhere, or this asserts nothing');

        $materiallyShort = 0;
        foreach ($shortfallRows as $row) {
            $targetMonthly = intdiv(self::pence($row['spend']), 12);
            $unmet = self::pence($row['shortfall']);

            // Never ABOVE the target, in any short year.
            $this->assertLessThanOrEqual(
                $targetMonthly,
                self::pence($row['monthlyAllowance']),
                "year {$row['year']}: a shortfall year's allowance cannot exceed the target",
            );

            // Strictly below once the gap is big enough to move a monthly figure at all. (The
            // engine produces the odd 1p unmet from rounding; a penny a year cannot and should not
            // change a monthly allowance, so only assert where it is meaningful.)
            if ($unmet >= 12) {
                $materiallyShort++;
                $this->assertLessThan(
                    $targetMonthly,
                    self::pence($row['monthlyAllowance']),
                    "year {$row['year']}: a materially short year's allowance must be BELOW the target",
                );
            }

            // ...and it must equal target − unmet, divided once, whatever the size of the gap.
            $funded = self::pence($row['spend']) - $unmet;
            $this->assertSame(intdiv(max(0, $funded), 12), self::pence($row['monthlyAllowance']));
        }

        $this->assertGreaterThan(0, $materiallyShort, 'the fixture must have a materially short year');
    }

    public function test_available_capital_is_liquid_only_and_excludes_the_home(): void
    {
        $forecast = $this->forecast();
        $rows = ResultPresenter::ladder($forecast)['rows'];

        foreach ($forecast->years as $i => $year) {
            $this->assertSame(
                $year->liquidWealth->pence,
                self::pence($rows[$i]['availableCapital']),
                "year {$year->calendarYear}: available capital is liquid wealth exactly",
            );

            // The home is excluded — and on this fixture (a £500k home) that is a large difference,
            // so an accidental inclusion could not pass unnoticed.
            $this->assertNotSame($year->totalWealth->pence, self::pence($rows[$i]['availableCapital']));
        }

        $this->assertGreaterThan(
            self::pence($rows[0]['availableCapital']),
            $forecast->years[0]->propertyWealth->pence,
            'the fixture must have far more home equity than cash for this test to bite',
        );
    }

    public function test_pension_money_is_carried_separately_and_never_added_to_available_capital(): void
    {
        // £1 of pension is not £1 in the hand (drawing it is taxable). The ladder's existing
        // usableWealth adds the two at face value; this figure must not.
        $forecast = $this->forecast();
        $rows = ResultPresenter::ladder($forecast)['rows'];
        $year = $forecast->years[0];

        $this->assertGreaterThan(0, $year->pensionWealth->pence, 'the fixture needs a pension pot');
        $this->assertSame($year->pensionWealth->pence, self::pence($rows[0]['pensionCapital']));
        $this->assertSame(
            $year->liquidWealth->pence,
            self::pence($rows[0]['availableCapital']),
            'available capital must NOT include the pension',
        );
        $this->assertNotSame(
            $year->liquidWealth->plus($year->pensionWealth)->pence,
            self::pence($rows[0]['availableCapital']),
            'available capital must differ from usableWealth (liquid + pension)',
        );
    }

    public function test_the_free_to_spend_figure_is_the_discretionary_money(): void
    {
        // The holiday/treats budget: funded spend above the essential floor.
        $forecast = $this->forecast();
        $rows = ResultPresenter::ladder($forecast)['rows'];
        $year = $forecast->years[0];

        $funded = $year->spendTarget->minus($year->unmetSpend)->pence;
        $essential = min($year->essentialSpend->pence, $funded);

        $this->assertSame(intdiv($funded - $essential, 12), self::pence($rows[0]['monthlyFree']));
        $this->assertGreaterThan(0, self::pence($rows[0]['monthlyFree']), 'the fixture has a discretionary layer');
    }

    public function test_essentials_never_exceed_what_the_plan_can_fund(): void
    {
        // When money runs short the essential floor is capped at the funded amount, so the split
        // can never claim the household met essentials it could not pay for.
        foreach (ResultPresenter::ladder($this->forecast())['rows'] as $row) {
            $this->assertLessThanOrEqual(
                self::pence($row['monthlyAllowance']),
                self::pence($row['monthlyEssential']),
                "year {$row['year']}: essentials cannot exceed the allowance"
            );
            $this->assertGreaterThanOrEqual(0, self::pence($row['monthlyFree']));
        }
    }

    public function test_the_summary_reports_the_survivor_step_down(): void
    {
        // The point of the summary block: a couple's plan can look comfortable and then halve when
        // one of them dies. p2 is fixed to die at 82 (2027), so a survivor phase exists.
        $summary = ResultPresenter::spendableSummary($this->forecast());

        $this->assertNotNull($summary['survivor'], 'the fixture must have survivor-only years');
        $this->assertNotNull($summary['survivorFromYear']);
        $this->assertGreaterThan(2026, $summary['survivorFromYear']);

        $this->assertLessThan(
            self::pence($summary['now']['monthlyAllowance']),
            self::pence($summary['survivor']['monthlyAllowance']),
            'the survivor allowance must be lower than the couple\'s — that is the warning',
        );
    }

    public function test_the_budget_panel_shows_the_computed_mortgage_instalment_not_zero(): void
    {
        // A repayment mortgage's "Mortgage" spend line is deliberately zeroed (the schedule owns the
        // payment). Echoing that £0 back unqualified reads as "the mortgage isn't being charged" —
        // it was mistaken for a bug in review. The panel must show the real instalment, flag it as
        // computed, and count it in the totals.
        $state = [
            'householdName' => 'Repayment', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'm1', 'label' => 'Mortgage', 'amount' => '0', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'f1', 'label' => 'Food', 'amount' => '3000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '400000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '160000',
                'mortgageRepaymentTermMonths' => '192', 'mortgageRepaymentStartYear' => '2026',
                'mortgageRepaymentStartMonth' => '9', 'mortgageRepaymentRate' => '6.23',
                'mortgageRepaymentInitialMonths' => '60', 'mortgageRepaymentRevertRate' => '7.24',
            ],
        ];

        $household = (new HouseholdAssembler)->household($state);
        $breakdown = ResultPresenter::expenseBreakdown($state, $household);

        $mortgage = null;
        foreach ($breakdown['tiers'] as $tier) {
            foreach ($tier['lines'] as $line) {
                if ($line['label'] === 'Mortgage') {
                    $mortgage = $line;
                }
            }
        }

        $this->assertNotNull($mortgage);
        // The first FULL year's instalments (12 x £1,318.54), not the 4-payment part year.
        $this->assertSame('£15,822.48', $mortgage['amount'], 'the panel must show the real instalment');
        $this->assertTrue($mortgage['computed'], 'and mark it as computed, not typed in');
        $this->assertNotSame('£0.00', $mortgage['amount']);

        // ...and it counts in the total, so the panel sums to what they will actually pay.
        $this->assertSame('£18,822.48', $breakdown['spendingTotal']);
    }

    public function test_a_scenario_with_no_repayment_terms_echoes_its_lines_unchanged(): void
    {
        // Back-compat: without terms the panel is the plain form-state echo it always was.
        $state = [
            'householdName' => 'Interest only', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'm1', 'label' => 'Mortgage', 'amount' => '7080', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '118000'],
        ];

        $breakdown = ResultPresenter::expenseBreakdown($state, (new HouseholdAssembler)->household($state));

        $this->assertSame('£7,080.00', $breakdown['tiers'][0]['lines'][0]['amount']);
        $this->assertFalse($breakdown['tiers'][0]['lines'][0]['computed']);
    }

    public function test_each_spending_tier_keeps_its_own_name(): void
    {
        // REGRESSION. The tier heading was being overwritten with its own LAST line's label, so
        // "Essential" displayed as "Commute Fuel" and "Discretionary" as "TV Licence" — on the
        // results page AND the PDF, which share this presenter. It shipped because every existing
        // test on this panel checked amounts and totals, never the labels.
        $state = [
            'householdName' => 'Tiers', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'e1', 'label' => 'Food', 'amount' => '3000', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'e2', 'label' => 'Commute Fuel', 'amount' => '2200', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'd1', 'label' => 'Netflix', 'amount' => '180', 'category' => 'discretionary', 'savedAsAsset' => false],
                ['id' => 'd2', 'label' => 'TV Licence', 'amount' => '175', 'category' => 'discretionary', 'savedAsAsset' => false],
                ['id' => 's1', 'label' => 'ISA top-up', 'amount' => '1200', 'category' => 'self_investment', 'savedAsAsset' => true],
            ],
            'expense' => ['survivorFactor' => '70'],
        ];

        $breakdown = ResultPresenter::expenseBreakdown($state, (new HouseholdAssembler)->household($state));

        $byKey = [];
        foreach ($breakdown['tiers'] as $tier) {
            $byKey[$tier['key']] = $tier['label'];
        }

        // Compared against the constant that owns the names, so the test cannot drift from them.
        foreach (ResultPresenter::EXPENSE_TIERS as $key => $canonical) {
            $this->assertSame($canonical, $byKey[$key] ?? null, "tier '{$key}' must keep its own name");
        }

        // And specifically not the last line of each tier, which is what it used to show.
        $this->assertNotSame('Commute Fuel', $byKey['essential']);
        $this->assertNotSame('TV Licence', $byKey['discretionary']);
    }

    public function test_the_mortgage_substitution_does_not_rename_its_tier(): void
    {
        // The substitution is what introduced the bug, so pin the two together: the instalment is
        // shown AND the tier keeps its name.
        $state = [
            'householdName' => 'Both', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'm1', 'label' => 'Mortgage', 'amount' => '0', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'f1', 'label' => 'Food', 'amount' => '3000', 'category' => 'essential', 'savedAsAsset' => false],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '400000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '160000',
                'mortgageRepaymentTermMonths' => '192', 'mortgageRepaymentStartYear' => '2026',
                'mortgageRepaymentStartMonth' => '9', 'mortgageRepaymentRate' => '6.23',
            ],
        ];

        $breakdown = ResultPresenter::expenseBreakdown($state, (new HouseholdAssembler)->household($state));

        $this->assertSame('Essential', $breakdown['tiers'][0]['label']);
        $this->assertSame('Mortgage', $breakdown['tiers'][0]['lines'][0]['label']);
        $this->assertTrue($breakdown['tiers'][0]['lines'][0]['computed']);
    }

    public function test_a_single_person_household_has_no_survivor_block(): void
    {
        $summary = ResultPresenter::spendableSummary($this->forecast([
            'people' => [['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
        ]));

        $this->assertNull($summary['survivor'], 'nobody outlives a partner in a one-person household');
        $this->assertNull($summary['survivorFromYear']);
        $this->assertNotEmpty($summary['now']);
    }
}
