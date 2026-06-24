<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Benefits;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Benefits\CapitalAssessment;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class CapitalAssessmentTest extends TestCase
{
    private function assessment(string $taxYear = '2025-26'): CapitalAssessment
    {
        return new CapitalAssessment(TaxYearRegistry::for($taxYear));
    }

    public function test_capital_within_the_disregard_creates_no_tariff_income(): void
    {
        $result = $this->assessment()->assess(Money::fromPounds(10_000));

        $this->assertSame(0, $result->tariffIncomeWeekly->pence);
        $this->assertTrue($result->housingSupportEligible);
    }

    /**
     * Worked example C: the couple sell their home and free up £180,000. The home was
     * exempt; the cash is assessable capital. Tariff income = (£180,000 - £10,000) /
     * £500 = £340 a week, and being over £16,000 ends Housing Benefit / Council Tax
     * Support.
     */
    public function test_worked_example_c_downsizing_capital_cliff(): void
    {
        $result = $this->assessment()->assess(Money::fromPounds(180_000));

        $this->assertSame(34_000, $result->tariffIncomeWeekly->pence);   // £340/week
        $this->assertSame(1_768_000, $result->tariffIncomeAnnual->pence); // £340 x 52
        $this->assertFalse($result->housingSupportEligible);

        $codes = array_map(fn ($w) => $w->code, $result->warnings);
        $this->assertContains(WarningCode::CAPITAL_CLIFF_HB_CTS, $codes);
    }

    public function test_part_step_rounds_up_and_stays_eligible_below_the_limit(): void
    {
        // £15,900: (£15,900 - £10,000) / £500 = 11.8, rounded up to 12 → £12 a week,
        // and still under the £16,000 limit so housing support continues.
        $result = $this->assessment()->assess(Money::fromPounds(15_900));

        $this->assertSame(1_200, $result->tariffIncomeWeekly->pence);
        $this->assertTrue($result->housingSupportEligible);
        $this->assertSame([], $result->warnings);
    }

    public function test_pension_credit_tariff_has_no_upper_limit_even_when_housing_support_is_lost(): void
    {
        // Pension Credit still applies a tariff above £16,000, even though housing
        // support has stopped.
        $result = $this->assessment()->assess(Money::fromPounds(50_000));

        $this->assertSame(8_000, $result->tariffIncomeWeekly->pence); // (£50k-£10k)/£500 = 80 → £80/wk
        $this->assertFalse($result->housingSupportEligible);
    }
}
