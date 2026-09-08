<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\SpendingGuardrail;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The spending guardrail (board card 0063).
 *
 * Without it the household spends the same in real terms whatever happens, which overstates the
 * chance of running out and hides the cheapest mitigation there is. With it, a year whose usable
 * wealth falls below the trigger multiple of the essential spend still to be funded cuts its
 * discretionary spend — and a year that recovers puts it back, because the test is re-run every
 * year off that year's own wealth.
 *
 * Zero growth + zero inflation, one retired person, so every figure below is penny-exact and the
 * only thing moving spend is the rule under test.
 */
final class SpendingGuardrailTest extends TestCase
{
    private const ESSENTIAL = 18_000;

    private const DISCRETIONARY = 6_000;

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
     * @param  list<IncomeStream>  $incomeStreams
     * @return list<YearResult>
     */
    private function years(
        ?SpendingGuardrail $guardrail,
        int $cash = 100_000,
        int $discretionary = self::DISCRETIONARY,
        array $incomeStreams = [],
    ): array {
        $household = new Household(
            'Guardrail', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(self::ESSENTIAL),
                Money::fromPounds($discretionary),
                // 100%, so a single-person household's survivor factor never scales anything.
                Percent::fromPercent(100),
                spendingGuardrail: $guardrail,
            ),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds($cash))],
            incomeStreams: $incomeStreams,
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'))
            ->years;
    }

    public function test_no_guardrail_spends_the_same_in_real_terms_whatever_happens(): void
    {
        // The pre-card behaviour, and the control for every figure below: £100k of cash against a
        // £24,000 target it cannot possibly fund for life, and the target never moves.
        $years = $this->years(null);

        $this->assertSame(Money::fromPounds(24_000)->pence, $years[0]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(24_000)->pence, $years[1]->spendTarget->pence);
        $this->assertSame(0, $years[0]->guardrailReduction()->pence);
    }

    public function test_the_guardrail_cuts_discretionary_spend_while_the_plan_is_underfunded(): void
    {
        // £100,000 of usable wealth against £18,000 a year of essentials for the rest of a
        // 68-year-old's life is a funded ratio far below 1, so the guardrail bites from year 0 and
        // the default 10% comes off the £6,000 of discretionary spend — and off that alone: the
        // essential floor is never trimmed.
        $years = $this->years(new SpendingGuardrail);

        $cut = Money::fromPounds(self::DISCRETIONARY)
            ->applyRate(Percent::fromBasisPoints(SpendingGuardrail::DEFAULT_DISCRETIONARY_CUT_BPS));

        $this->assertGreaterThan(0, $cut->pence, 'the default cut must be positive, or the rule does nothing');
        $this->assertSame(Money::fromPounds(24_000)->pence - $cut->pence, $years[0]->spendTarget->pence);
        $this->assertSame($cut->pence, $years[0]->guardrailReduction()->pence, 'and the year says what it took off');
        $this->assertSame(Money::fromPounds(self::ESSENTIAL)->pence, $years[0]->essentialSpend->pence, 'the floor is untouched');
    }

    public function test_the_reader_can_set_the_trigger_and_the_cut(): void
    {
        // Both figures are editable, and both change the projection: a quarter off discretionary
        // spend instead of a tenth.
        $years = $this->years(new SpendingGuardrail(
            triggerFundedRatio: Percent::fromPercent(100),
            discretionaryCut: Percent::fromPercent(25),
        ));

        $this->assertSame(Money::fromPounds(24_000 - 1_500)->pence, $years[0]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(1_500)->pence, $years[0]->guardrailReduction()->pence);
    }

    public function test_a_trigger_the_plan_clears_never_bites(): void
    {
        // A funded plan is not asked to cut back. £2m of cash covers every remaining essential
        // year many times over, so the ratio is above the trigger in every year.
        $years = $this->years(new SpendingGuardrail, cash: 2_000_000);

        foreach ($years as $year) {
            $this->assertSame(0, $year->guardrailReduction()->pence, "year {$year->calendarYear} should not have cut back");
        }
        $this->assertSame(Money::fromPounds(24_000)->pence, $years[0]->spendTarget->pence);
    }

    public function test_the_cut_is_put_back_when_the_plan_recovers(): void
    {
        // The rule is state-dependent both ways. Income covers the spend, so wealth holds at
        // £200,000 while the essential spend still to be funded shrinks with every year that
        // passes: the ratio climbs through the trigger, and the household stops cutting back.
        $years = $this->years(
            new SpendingGuardrail,
            cash: 200_000,
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(24_000), false, false, 0)],
        );

        $last = $years[count($years) - 1];
        $this->assertGreaterThan(0, $years[0]->guardrailReduction()->pence, 'underfunded at the start, so it bites');
        $this->assertSame(0, $last->guardrailReduction()->pence, 'funded at the end, so it is put back');
        $this->assertSame(Money::fromPounds(24_000)->pence, $last->spendTarget->pence, 'the full target is spent again');
    }

    public function test_a_household_with_no_discretionary_spend_left_is_told_so(): void
    {
        // The finding the card exists to make sayable: the guardrail is the cheapest mitigation
        // there is, UNLESS there is nothing left to cut, and a household on its essential floor
        // gets no protection from it at all. Nothing is trimmed, and the year says why.
        $years = $this->years(new SpendingGuardrail, discretionary: 0);

        $codes = array_column(array_map(
            static fn ($w): array => ['code' => $w->code],
            $years[0]->warnings,
        ), 'code');

        $this->assertContains(WarningCode::GUARDRAIL_NO_FLEXIBILITY, $codes);
        $this->assertSame(0, $years[0]->guardrailReduction()->pence);
        $this->assertSame(Money::fromPounds(self::ESSENTIAL)->pence, $years[0]->spendTarget->pence, 'the floor is never cut');
    }

    public function test_a_funded_household_with_no_discretionary_spend_is_not_warned(): void
    {
        // No noise: the warning is about a guardrail that cannot help, so it belongs only to a
        // year the guardrail was actually asked to protect.
        $years = $this->years(new SpendingGuardrail, cash: 2_000_000, discretionary: 0);

        foreach ($years as $year) {
            foreach ($year->warnings as $warning) {
                $this->assertNotSame(WarningCode::GUARDRAIL_NO_FLEXIBILITY, $warning->code);
            }
        }
    }
}
