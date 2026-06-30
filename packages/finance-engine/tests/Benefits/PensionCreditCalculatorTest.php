<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Benefits;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Benefits\PensionCreditCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Pension Credit Guarantee Credit = max(0, appropriate minimum guarantee − assessable
 * income), where assessable income includes the deemed tariff income from capital. Figures
 * are the 2026/27 Standard Minimum Guarantee (single £238.00, couple £363.25), the severe
 * disability addition (£86.05) and the £10,000 capital disregard (£1/week per £500 above).
 */
final class PensionCreditCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2026-27'): PensionCreditCalculator
    {
        return new PensionCreditCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_single_pensioner_below_the_guarantee_is_topped_up_to_it(): void
    {
        // Income £200/wk, single guarantee £238.00 → £38.00 a week.
        $result = $this->calculator()->assess(
            isCouple: false,
            assessableIncomeWeekly: Money::fromPounds(200),
            assessableCapital: Money::zero(),
        );

        $this->assertSame(3_800, $result->guaranteeCreditWeekly->pence);
        $this->assertSame(3_800 * 52, $result->guaranteeCreditAnnual(52)->pence);
    }

    public function test_a_widow_on_her_own_state_pension_is_lifted_to_the_single_guarantee(): void
    {
        // Her State Pension £190.00/wk, single guarantee £238.00 → £49.59 a week.
        $result = $this->calculator()->assess(
            isCouple: false,
            assessableIncomeWeekly: Money::of(188, 41),
            assessableCapital: Money::zero(),
        );

        $this->assertSame(4_959, $result->guaranteeCreditWeekly->pence); // £238.00 − £190.00
    }

    public function test_a_couple_whose_income_exceeds_the_couple_guarantee_get_nothing(): void
    {
        // Two State Pensions £406/wk above the £363.25 couple guarantee → £0.
        $result = $this->calculator()->assess(
            isCouple: true,
            assessableIncomeWeekly: Money::fromPounds(406),
            assessableCapital: Money::zero(),
        );

        $this->assertSame(0, $result->guaranteeCreditWeekly->pence);
    }

    public function test_the_severe_disability_addition_lifts_the_guarantee(): void
    {
        // Couple guarantee £363.25 + £86.05 SDP = £449.30; income £406 → £43.30 a week.
        $result = $this->calculator()->assess(
            isCouple: true,
            assessableIncomeWeekly: Money::fromPounds(406),
            assessableCapital: Money::zero(),
            severeDisability: true,
        );

        $this->assertSame(4_330, $result->guaranteeCreditWeekly->pence);
    }

    public function test_capital_tariff_income_erodes_the_award_the_downsizing_trap(): void
    {
        // Single, income £200/wk (a £38 award with no capital). £30,000 of capital deems
        // (£30,000 − £10,000) / £500 = £40 a week of tariff income, which wipes the award.
        $result = $this->calculator()->assess(
            isCouple: false,
            assessableIncomeWeekly: Money::fromPounds(200),
            assessableCapital: Money::fromPounds(30_000),
        );

        $this->assertSame(4_000, $result->tariffIncomeWeekly->pence); // £40/wk
        $this->assertSame(0, $result->guaranteeCreditWeekly->pence);  // £238 − (£200 + £40)
    }
}
