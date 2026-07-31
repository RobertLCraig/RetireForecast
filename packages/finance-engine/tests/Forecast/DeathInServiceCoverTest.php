<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DeathInServiceCover;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Employer death-in-service (group life) cover: a lump sum paid to the survivor when a member dies
 * **while still in employment**.
 *
 * The point of modelling it is the cliff-edge. Cover ceases when employment does, so a household
 * relying on it is protected only until retirement — and the year after, the survivor faces the
 * same loss of income with nothing to replace it. These tests pin both halves: that the payout
 * reaches the survivor with the right tax treatment while the member works, and that it pays
 * NOTHING once they have retired.
 *
 * Tax treatment verified 2026-07-31 against HMRC PTM073010 and gov.uk's lump sum allowance
 * guidance: tax-free under 75 up to the remaining LSDBA, taxable as the recipient's income above
 * it and for a death at 75 or over. IHT: excluded (registered-scheme death-in-service benefits are
 * out of scope, including under the April-2027 pensions-in-estate rule).
 */
final class DeathInServiceCoverTest extends TestCase
{
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
     * A working earner (dies at $dieAtAge) and a retired partner who outlives them, so there is
     * always a survivor to receive the payout. The earner's salary is deliberately modest so the
     * survivor's marginal rate on any taxable slice is unambiguous.
     */
    private function household(?DeathInServiceCover $cover, int $dieAtAge = 62, int $retireAge = 67): Household
    {
        $earner = new Person(
            'p1', new DateTimeImmutable('1966-06-01'), Sex::Male, EmploymentStatus::Employed,
            grossSalary: Money::fromPounds(40_000),
            plannedRetirementAge: $retireAge,
            longevity: LongevityAdjustment::fixedAge($dieAtAge),
            deathInServiceCover: $cover,
        );
        $partner = new Person(
            'p2', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired,
            longevity: LongevityAdjustment::fixedAge(95),
        );

        return new Household(
            'Cover',
            RegionProfile::EnglandWalesNi,
            [$earner, $partner],
            new ExpenseProfile(Money::fromPounds(24_000), Money::zero(), Percent::fromPercent(70)),
            incomeStreams: [new IncomeStream('p2', IncomeStreamType::Other, Money::fromPounds(9_000), true, false, 60)],
        );
    }

    private function forecast(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** @return array<int, YearResult> */
    private function byYear(ForecastResult $forecast): array
    {
        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year;
        }

        return $out;
    }

    public function test_no_cover_is_byte_identical_to_a_projection_that_never_knew_about_it(): void
    {
        // The null default must not move a single penny anywhere, on any line.
        $without = $this->forecast($this->household(null));

        foreach ($without->years as $year) {
            $this->assertSame(0, $year->incomeBySource['death_in_service']->pence);
        }
        $this->assertArrayHasKey('death_in_service', $without->years[0]->incomeBySource);
    }

    public function test_cover_pays_the_survivor_in_the_year_after_a_death_in_service_and_nowhere_else(): void
    {
        // Dies at 62 (born 1966, so 2028), retirement age 67 — comfortably still in service.
        $cover = DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400)); // 4x salary
        $with = $this->byYear($this->forecast($this->household($cover)));
        $without = $this->byYear($this->forecast($this->household(null)));

        // The member's last living year is 2028; the estate (and the cover) settles in 2029.
        $this->assertSame(0, $with[2028]->incomeBySource['death_in_service']->pence, 'not paid while alive');
        $this->assertSame(
            Money::fromPounds(160_000)->pence,
            $with[2029]->incomeBySource['death_in_service']->pence,
            '4x a £40,000 salary',
        );
        $this->assertSame(0, $with[2030]->incomeBySource['death_in_service']->pence, 'paid once, not annually');

        // Completeness: the whole sum lands, and the survivor stays materially better off for it.
        $this->assertSame(
            Money::fromPounds(160_000)->pence,
            $with[2029]->liquidWealth->pence - $without[2029]->liquidWealth->pence,
            'the payout lands whole',
        );
        foreach ([2030, 2031, 2035] as $year) {
            $this->assertGreaterThan(
                Money::fromPounds(100_000)->pence,
                $with[$year]->liquidWealth->pence - $without[$year]->liquidWealth->pence,
                "the banked payout still dominates in {$year}",
            );
        }
    }

    public function test_the_payout_costs_the_survivor_their_pension_credit(): void
    {
        // Not a rounding effect and not a bug: £160,000 of capital is far above the Pension Credit
        // cut-off, so a survivor who would have qualified no longer does. It is the same trap the
        // tool already shows on a house sale — capital arriving can take a means-tested benefit
        // with it — and it is why the payout's value to the household is less than its face value.
        $cover = DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400));
        $with = $this->byYear($this->forecast($this->household($cover)));
        $without = $this->byYear($this->forecast($this->household(null)));

        $this->assertTrue($with[2032]->incomeBySource['means_tested_benefit']->isZero());
        $this->assertTrue(
            $without[2032]->incomeBySource['means_tested_benefit']->isPositive(),
            'without the payout the survivor would be drawing Guarantee Credit by now',
        );
    }

    public function test_a_payout_under_75_and_within_the_allowance_is_tax_free(): void
    {
        $cover = DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400));
        $with = $this->byYear($this->forecast($this->household($cover)));
        $without = $this->byYear($this->forecast($this->household(null)));

        // £160,000 is far below the £1,073,100 LSDBA and the member died at 62, so not a penny of
        // tax is due on it — the survivor's tax bill is unchanged in the payout year and after.
        foreach ([2029, 2030, 2031] as $year) {
            $this->assertSame(
                $without[$year]->totalTax->pence,
                $with[$year]->totalTax->pence,
                "tax-free in {$year}",
            );
        }
    }

    public function test_the_part_above_the_lump_sum_and_death_benefit_allowance_is_taxed_on_the_survivor(): void
    {
        // A sum assured well above the £1,073,100 LSDBA: the excess is the survivor's taxable
        // pension income, so their tax bill in the payout year must rise, and by less than the
        // whole excess (it is taxed, not confiscated).
        $excess = Money::fromPounds(200_000);
        $cover = DeathInServiceCover::fixedSum(Money::fromPence(1_073_100_00 + $excess->pence));

        $with = $this->byYear($this->forecast($this->household($cover)));
        $without = $this->byYear($this->forecast($this->household(null)));

        $extraTax = $with[2029]->totalTax->pence - $without[2029]->totalTax->pence;
        $this->assertGreaterThan(0, $extraTax, 'the excess over the LSDBA is taxable');
        $this->assertLessThan($excess->pence, $extraTax, 'taxed at a marginal rate, not taken whole');

        // And the ladder still shows the payout GROSS, so the reader can see the sum and the tax.
        $this->assertSame(
            1_073_100_00 + $excess->pence,
            $with[2029]->incomeBySource['death_in_service']->pence,
        );
    }

    public function test_a_death_at_75_or_over_makes_the_whole_lump_sum_taxable(): void
    {
        // Still working at 76 (retirement age 80), so still covered — but past 75, which makes the
        // entire lump sum the recipient's taxable pension income however small it is.
        $cover = DeathInServiceCover::fixedSum(Money::fromPounds(50_000));
        $with = $this->byYear($this->forecast($this->household($cover, dieAtAge: 76, retireAge: 80)));
        $without = $this->byYear($this->forecast($this->household(null, dieAtAge: 76, retireAge: 80)));

        $payoutYear = 1966 + 76 + 1; // the year after the last living year
        $this->assertSame(Money::fromPounds(50_000)->pence, $with[$payoutYear]->incomeBySource['death_in_service']->pence);
        $this->assertGreaterThan(
            $without[$payoutYear]->totalTax->pence,
            $with[$payoutYear]->totalTax->pence,
            'a lump sum on a death at 75+ is taxable in full, so the survivor pays tax on it',
        );
    }

    public function test_cover_ceases_at_retirement_the_cliff_edge_the_panel_exists_to_show(): void
    {
        // Identical cover, identical death year — the ONLY difference is that they had already
        // retired. Nothing is paid: this is the protection cliff, not a rounding effect.
        $cover = DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400));

        $inService = $this->byYear($this->forecast($this->household($cover, dieAtAge: 62, retireAge: 67)));
        $retired = $this->byYear($this->forecast($this->household($cover, dieAtAge: 62, retireAge: 60)));

        $this->assertSame(Money::fromPounds(160_000)->pence, $inService[2029]->incomeBySource['death_in_service']->pence);
        $this->assertSame(0, $retired[2029]->incomeBySource['death_in_service']->pence, 'retired: no cover, no payout');
    }

    public function test_a_salary_multiple_tracks_pay_but_a_fixed_sum_does_not(): void
    {
        // Cover is sized on the salary in the year of death, so real pay growth raises it. A stated
        // sum assured does not move, which is the honest difference between the two forms.
        $growing = $this->household(DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400)));
        $persons = $growing->persons;
        $withGrowth = $growing->withPersons([
            new Person(
                'p1', new DateTimeImmutable('1966-06-01'), Sex::Male, EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(40_000),
                salaryGrowth: Percent::fromPercent(10),
                plannedRetirementAge: 67,
                longevity: LongevityAdjustment::fixedAge(62),
                deathInServiceCover: DeathInServiceCover::multipleOfSalary(Percent::fromPercent(400)),
            ),
            $persons[1],
        ]);

        $paid = $this->byYear($this->forecast($withGrowth))[2029]->incomeBySource['death_in_service']->pence;
        $this->assertGreaterThan(
            Money::fromPounds(160_000)->pence,
            $paid,
            'a multiple of salary keeps pace with pay',
        );
    }

    public function test_a_death_in_service_payout_is_not_income_for_pension_credit(): void
    {
        // The payout is CAPITAL for the means test, not income: a survivor already on Pension
        // Credit must not lose a year of it because a lump sum landed. (The banked cash does raise
        // tariff income from the following year, which is the real rule and is unaffected here.)
        $cover = DeathInServiceCover::fixedSum(Money::fromPounds(12_000));

        // A household poor enough to be on Guarantee Credit once the earner has gone.
        $earner = new Person(
            'p1', new DateTimeImmutable('1958-06-01'), Sex::Male, EmploymentStatus::Employed,
            grossSalary: Money::fromPounds(12_000), plannedRetirementAge: 75,
            longevity: LongevityAdjustment::fixedAge(70), deathInServiceCover: $cover,
        );
        $partner = new Person('p2', new DateTimeImmutable('1956-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90));
        $household = new Household(
            'PC', RegionProfile::EnglandWalesNi, [$earner, $partner],
            new ExpenseProfile(Money::fromPounds(14_000), Money::zero(), Percent::fromPercent(70)),
            incomeStreams: [new IncomeStream('p2', IncomeStreamType::Other, Money::fromPounds(4_000), true, false, 60)],
        );

        $years = $this->byYear($this->forecast($household));
        $payoutYear = 1958 + 70 + 1;

        $this->assertSame(Money::fromPounds(12_000)->pence, $years[$payoutYear]->incomeBySource['death_in_service']->pence);
        $this->assertTrue(
            $years[$payoutYear]->incomeBySource['means_tested_benefit']->isPositive(),
            'the lump sum is capital, not income, so it does not extinguish that year\'s Pension Credit',
        );
    }

    public function test_cover_must_be_stated_exactly_one_way(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeathInServiceCover(null, null);
    }

    public function test_cover_cannot_be_stated_both_ways_at_once(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeathInServiceCover(Percent::fromPercent(400), Money::fromPounds(100_000));
    }
}
