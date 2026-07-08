<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Care;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Care\CareMeansTest;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class CareMeansTestTest extends TestCase
{
    private function means(string $taxYear = '2025-26'): CareMeansTest
    {
        return new CareMeansTest(TaxYearRegistry::for($taxYear));
    }

    public function test_self_funder_above_the_upper_limit(): void
    {
        $result = $this->means()->assess(Money::fromPounds(30_000));

        $this->assertTrue($result->selfFunder);
        $this->assertSame(0, $result->tariffIncomeWeekly->pence);
    }

    public function test_capital_below_the_lower_limit_is_ignored(): void
    {
        $result = $this->means()->assess(Money::fromPounds(10_000));

        $this->assertFalse($result->selfFunder);
        $this->assertSame(0, $result->tariffIncomeWeekly->pence);
    }

    public function test_tariff_income_between_the_limits(): void
    {
        // £20,000: (£20,000 - £14,250) / £250 = 23 → £23 a week.
        $result = $this->means()->assess(Money::fromPounds(20_000));

        $this->assertFalse($result->selfFunder);
        $this->assertSame(2_300, $result->tariffIncomeWeekly->pence);
    }

    public function test_at_the_upper_limit_is_not_yet_a_self_funder(): void
    {
        // £23,250 exactly: not above the limit, so still means-tested, with the full
        // tariff (£23,250 - £14,250) / £250 = 36 → £36 a week.
        $result = $this->means()->assess(Money::fromPounds(23_250));

        $this->assertFalse($result->selfFunder);
        $this->assertSame(3_600, $result->tariffIncomeWeekly->pence);
    }

    // ── annualCharge: the household-borne cost of a care year ──────────────────────
    // All on 2026-27 (PEA £31.80/wk → £1,653.60 a year), fee £80,000, income £12,000
    // unless a case says otherwise. Income contribution = £12,000 − £1,653.60 = £10,346.40.

    private function charge(int $capitalPounds, int $incomePounds, float $peaUprating = 1.0): int
    {
        return $this->means('2026-27')->annualCharge(
            grossAnnualFee: Money::fromPounds(80_000),
            capital: Money::fromPounds($capitalPounds),
            assessableAnnualIncome: Money::fromPounds($incomePounds),
            peaUprating: $peaUprating,
        )->pence;
    }

    public function test_a_comfortable_self_funder_pays_the_full_fee(): void
    {
        $this->assertSame(Money::fromPounds(80_000)->pence, $this->charge(500_000, 12_000));
    }

    public function test_below_the_lower_limit_only_income_is_assessed(): void
    {
        // No capital side at all: the charge is income minus the PEA.
        $this->assertSame(1_034_640, $this->charge(10_000, 12_000));
    }

    public function test_between_the_limits_the_tariff_is_added_to_the_income_contribution(): void
    {
        // £20,000 capital → £23/wk tariff → £1,196 a year on top of £10,346.40.
        $this->assertSame(1_034_640 + 119_600, $this->charge(20_000, 12_000));
    }

    public function test_the_crossing_year_pays_capital_down_to_the_limit_then_contributes_from_income(): void
    {
        // £30,000 capital: £6,750 above the limit is spent down, plus the income
        // contribution — far below the £80,000 self-funder fee.
        $this->assertSame(675_000 + 1_034_640, $this->charge(30_000, 12_000));
    }

    public function test_the_charge_never_exceeds_the_fee(): void
    {
        // A large income contribution is capped at the actual cost of the care.
        $this->assertSame(Money::fromPounds(80_000)->pence, $this->charge(10_000, 100_000));
    }

    public function test_nothing_assessable_means_the_local_authority_pays_everything(): void
    {
        // Income below the PEA and capital below the lower limit: charge £0, never negative.
        $this->assertSame(0, $this->charge(5_000, 1_000));
    }

    public function test_the_pea_is_uprated_with_inflation_unlike_the_frozen_limits(): void
    {
        // At a price level of 2.0 the protected PEA doubles (£3,307.20), so the income
        // contribution falls by exactly the extra £1,653.60 of protection.
        $this->assertSame(1_034_640 - 165_360, $this->charge(10_000, 12_000, peaUprating: 2.0));
    }
}
