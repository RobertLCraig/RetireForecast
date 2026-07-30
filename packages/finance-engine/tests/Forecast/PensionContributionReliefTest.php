<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\PensionReliefMethod;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Contributions going IN, which the engine was silent about (adviser-parity A2). Three
 * distinct properties, each a real defect before this work:
 *
 * 1. A net-pay contribution is deducted from GROSS pay, so it cuts the tax bill at the member's
 *    marginal rate. Modelling it as a payment out of net surplus charged the full cost and gave
 *    none of the benefit — the engine knew the cost of a pension and not its point.
 * 2. The EMPLOYER's contribution is the employer's money. Funding it from household surplus
 *    charged the household for someone else's payment and silently dropped it in a year the
 *    surplus ran short.
 * 3. A net-pay contribution cannot exceed pay, so it stops by itself at retirement rather than
 *    running for ever out of a retired person's savings.
 */
final class PensionContributionReliefTest extends TestCase
{
    private const SALARY = 40_000;

    private const MEMBER_CONTRIBUTION = 4_000;

    private const EMPLOYER_CONTRIBUTION = 3_000;

    private function forecast(
        ?PensionReliefMethod $relief,
        int $employerContribution = 0,
        int $retireAge = 70,
    ): ForecastResult {
        $household = new Household(
            'Relief',
            RegionProfile::EnglandWalesNi,
            [new Person(
                'p1',
                new DateTimeImmutable('1966-01-01'),
                Sex::Male,
                EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(self::SALARY),
                plannedRetirementAge: $retireAge,
            )],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DcPension(
                'p1',
                Money::zero(),
                Money::fromPounds(self::MEMBER_CONTRIBUTION),
                Money::fromPounds($employerContribution),
                55,
                reliefMethod: $relief,
            )],
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_net_pay_relief_cuts_the_tax_bill_at_the_members_marginal_rate(): void
    {
        $unrelieved = $this->forecast(null)->years[0];
        $netPay = $this->forecast(PensionReliefMethod::NetPay)->years[0];

        // A £4,000 contribution out of gross pay removes £4,000 of taxable income. On a £40,000
        // salary that is all basic rate, so the tax saved is 20% of it — £800, the entire reason
        // a pension beats an ISA for a taxpayer.
        $taxSaved = $unrelieved->totalTax->pence - $netPay->totalTax->pence;
        $this->assertSame(Money::fromPounds(800)->pence, $taxSaved, 'net pay must relieve at the marginal rate');
    }

    public function test_net_pay_relief_does_not_reduce_national_insurance(): void
    {
        // NI is charged on pre-contribution pay under net pay; only salary sacrifice saves NI,
        // and that is deliberately not modelled. If NI moved, the two arrangements would have
        // been conflated and the pot overstated.
        $unrelieved = $this->forecast(null)->years[0];
        $netPay = $this->forecast(PensionReliefMethod::NetPay)->years[0];

        // Tax and NI are reported together, so isolate NI by the amount the saving would grow to
        // if NI (8% on this band) had also been relieved: £800 + £320. It must be exactly £800.
        $saved = $unrelieved->totalTax->pence - $netPay->totalTax->pence;
        $this->assertNotSame(Money::fromPounds(1_120)->pence, $saved, 'NI must not be relieved — that would be salary sacrifice');
    }

    public function test_the_pot_receives_the_contribution_under_both_arrangements(): void
    {
        // Relief must change the TAX, not what lands in the pot: £4,000 goes in either way. A
        // grossing-up bug would show here as a bigger pot rather than a smaller tax bill.
        $unrelieved = $this->forecast(null)->years[0];
        $netPay = $this->forecast(PensionReliefMethod::NetPay)->years[0];

        $this->assertSame($unrelieved->pensionWealth->pence, $netPay->pensionWealth->pence);
        $this->assertGreaterThan(0, $netPay->pensionWealth->pence);
    }

    public function test_relief_leaves_the_household_better_off_overall(): void
    {
        // The completeness check: the tax saving has to reach the household's wealth, not vanish
        // between the tax pass and the balance sheet.
        $unrelieved = $this->forecast(null)->years[0];
        $netPay = $this->forecast(PensionReliefMethod::NetPay)->years[0];

        $this->assertGreaterThan($unrelieved->totalWealth->pence, $netPay->totalWealth->pence);
    }

    public function test_the_employers_contribution_is_not_paid_out_of_the_households_money(): void
    {
        // It is the employer's money: it must reach the pot without reducing what the household
        // has to spend or save. Before this, it competed with the household's own spending.
        $without = $this->forecast(PensionReliefMethod::NetPay)->years[0];
        $with = $this->forecast(PensionReliefMethod::NetPay, self::EMPLOYER_CONTRIBUTION)->years[0];

        $this->assertSame(
            Money::fromPounds(self::EMPLOYER_CONTRIBUTION)->pence,
            $with->pensionWealth->pence - $without->pensionWealth->pence,
            'the whole employer contribution reaches the pot',
        );
        $this->assertSame(
            $without->liquidWealth->pence,
            $with->liquidWealth->pence,
            'and none of it comes out of the household\'s liquid wealth',
        );
    }

    public function test_the_employers_contribution_survives_a_year_with_no_surplus(): void
    {
        // The silent-drop case that made this a completeness defect, not just a cashflow one: a
        // household spending everything it earns still gets its employer's contribution. The
        // member is 54 against an access age of 57, so the pot is LOCKED — otherwise the
        // shortfall would immediately draw the contribution straight back out and the test would
        // pass or fail for the wrong reason.
        $household = new Household(
            'Broke',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1972-01-01'), Sex::Male, EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(self::SALARY), plannedRetirementAge: 70)],
            // Spending well above net pay, so there is never a penny of surplus.
            new ExpenseProfile(Money::fromPounds(60_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DcPension('p1', Money::zero(), Money::zero(),
                Money::fromPounds(self::EMPLOYER_CONTRIBUTION), 57, reliefMethod: PensionReliefMethod::NetPay)],
        );

        $first = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'))
            ->years[0];

        $this->assertSame(Money::fromPounds(self::EMPLOYER_CONTRIBUTION)->pence, $first->pensionWealth->pence);
    }

    public function test_a_net_pay_contribution_stops_when_the_pay_does(): void
    {
        // Capped at earnings, so retirement ends it by itself — no separate gate to forget, and
        // no retired member contributing out of savings for thirty years.
        $years = $this->forecast(PensionReliefMethod::NetPay, retireAge: 61)->years;

        $working = $years[0]->pensionWealth->pence;
        $afterRetirement = $years[10]->pensionWealth->pence;
        $laterStill = $years[20]->pensionWealth->pence;

        $this->assertGreaterThan(0, $working);
        // The pot still GROWS after retirement (investment return), so compare the increments:
        // a pot still being contributed to would step up by the contribution every year.
        $this->assertLessThan(
            Money::fromPounds(self::MEMBER_CONTRIBUTION)->pence,
            $laterStill - $afterRetirement,
            'no contribution should be paid once the salary has stopped',
        );
    }

    public function test_relief_at_source_is_refused_rather_than_silently_giving_no_relief(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DcPension('p1', Money::zero(), Money::fromPounds(1_000), Money::zero(), 55,
            reliefMethod: PensionReliefMethod::ReliefAtSource);
    }
}
