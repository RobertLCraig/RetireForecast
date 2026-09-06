<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
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
 * Pension Credit is CONTINGENT, not automatic, and the two disclosures that follow from that
 * (board card 0046):
 *
 *  - a household sitting just above the guarantee is the case a caseworker most wants a nil
 *    claim from, so the year carries a near-miss flag even though the award is £0;
 *  - assessable capital crossing the £16,000 limit ends Housing Benefit and Council Tax
 *    Support, which the docs promised to flag and nothing ever collected — EXCEPT for a
 *    household on Guarantee Credit, which is fully passported with no upper capital limit.
 */
final class PensionCreditContingencyTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** Flat assumptions (no inflation, no growth), as the sibling projector tests use. */
    private function flatAssumptions(): AssumptionSet
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

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * @param  list<Account>  $accounts
     * @param  list<CapitalReceipt>  $receipts
     */
    private function couple(int $weeklyStatePensionPounds, array $accounts = [], int $essential = 15_000, array $receipts = []): Household
    {
        // Both born 1958: aged 68 in 2026, so both are over State Pension age from year 0.
        return new Household(
            'Test', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds($essential), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of($weeklyStatePensionPounds, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of($weeklyStatePensionPounds, 0)),
            ],
            $accounts,
            capitalReceipts: $receipts,
        );
    }

    /** @return array<int, YearResult> keyed by calendar year */
    private function byYear(Household $household): array
    {
        $years = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        return $years;
    }

    /** @return list<string> */
    private function codes(YearResult $year): array
    {
        return array_map(static fn ($w): string => $w->code, $year->warnings);
    }

    public function test_the_capital_cliff_is_warned_on_the_year_capital_crosses_the_limit(): void
    {
        // A couple whose State Pension (£241.30 each) is above the guarantee, so no Guarantee
        // Credit is in payment and the passport carve-out does not apply. They start with £5,000
        // — under the £16,000 limit — and a £50,000 gift lands in 2028. The gift banks at the end
        // of its own year, so 2029 is the first year assessed on capital above the limit.
        $years = $this->byYear($this->couple(
            241,
            [new Account('p1', AccountType::Cash, Money::fromPounds(5_000))],
            essential: 12_000,
            receipts: [new CapitalReceipt('p1', 'Family gift', Money::fromPounds(50_000), 2028)],
        ));

        $this->assertNotContains(WarningCode::CAPITAL_CLIFF_HB_CTS, $this->codes($years[2026]),
            'below the limit, nothing to warn about');
        $this->assertContains(WarningCode::CAPITAL_CLIFF_HB_CTS, $this->codes($years[2029]),
            'the year assessable capital is above the limit carries the cliff warning');
    }

    public function test_a_household_on_guarantee_credit_is_not_warned_that_capital_ends_its_help(): void
    {
        // £20,000 of capital is above the £16,000 limit, so the rule as coded warns. It is wrong
        // at this edge: Guarantee Credit passports Housing Benefit and Council Tax Support with no
        // upper capital limit. £120/wk each leaves the couple £103.25/wk of Guarantee Credit even
        // after the £20/wk tariff on that capital, so the award is genuinely in payment.
        $cash = [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))];

        $onCredit = $this->byYear($this->couple(120, $cash))[2026];
        $this->assertTrue($onCredit->incomeBySource['means_tested_benefit']->isPositive(),
            'the household really is on Guarantee Credit in this year');
        $this->assertNotContains(WarningCode::CAPITAL_CLIFF_HB_CTS, $this->codes($onCredit));

        // The same capital, but income above the guarantee: no award, so the cliff is real.
        $notOnCredit = $this->byYear($this->couple(241, $cash))[2026];
        $this->assertSame(0, $notOnCredit->incomeBySource['means_tested_benefit']->pence);
        $this->assertContains(WarningCode::CAPITAL_CLIFF_HB_CTS, $this->codes($notOnCredit));
    }

    /** A single pensioner with the given weekly State Pension and no capital. */
    private function solo(int $weeklyStatePensionPounds): Household
    {
        return new Household(
            'Solo', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            [new StatePensionEntitlement('p1', weeklyForecast: Money::of($weeklyStatePensionPounds, 0))],
        );
    }

    public function test_a_household_just_above_the_pension_credit_line_is_flagged_as_a_near_miss(): void
    {
        // £245/wk against the £238.00 single guarantee: no award, but within the near-miss margin —
        // the case where the engine's own simplifications (Guarantee Credit only, no disregards)
        // are most likely to be the whole of the difference, so a nil claim belongs on file.
        $justAbove = $this->byYear($this->solo(245))[2026];

        $this->assertSame(0, $justAbove->incomeBySource['means_tested_benefit']->pence);
        $this->assertContains(WarningCode::PENSION_CREDIT_NEAR_MISS, $this->codes($justAbove));

        // £400/wk is not near the line at all — no award and no flag, so the prompt stays quiet.
        $wellAbove = $this->byYear($this->solo(400))[2026];
        $this->assertSame(0, $wellAbove->incomeBySource['means_tested_benefit']->pence);
        $this->assertNotContains(WarningCode::PENSION_CREDIT_NEAR_MISS, $this->codes($wellAbove));
    }

    public function test_a_household_already_being_paid_pension_credit_is_not_flagged_as_a_near_miss(): void
    {
        // The near-miss flag is for a household that gets NOTHING. One that is awarded the credit
        // already has it in incomeBySource, so flagging it too would double-report the same fact.
        $onCredit = $this->byYear($this->couple(120))[2026];

        $this->assertTrue($onCredit->incomeBySource['means_tested_benefit']->isPositive());
        $this->assertNotContains(WarningCode::PENSION_CREDIT_NEAR_MISS, $this->codes($onCredit));
    }

    public function test_the_default_library_assumptions_reach_the_same_conclusion(): void
    {
        // Guard against the flat-economy fixture hiding a rule that only holds at zero inflation:
        // the near-miss verdict is a ratio of income to the guarantee, and both are uprated by the
        // same triple-lock factor, so it must survive the real assumption set unchanged.
        $forecast = $this->forecaster()->forecast($this->solo(245), AssumptionSetLibrary::default(), $this->settings());

        $this->assertContains(WarningCode::PENSION_CREDIT_NEAR_MISS, $this->codes($forecast->years[0]));
    }
}
