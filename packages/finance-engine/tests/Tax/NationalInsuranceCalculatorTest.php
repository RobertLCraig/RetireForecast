<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Tax\NationalInsuranceCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class NationalInsuranceCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): NationalInsuranceCalculator
    {
        return new NationalInsuranceCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_main_rate_only(): void
    {
        // £50,000: (£50,000 - £12,570) = £37,430 @ 8% = £2,994.40. None above the UEL.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(50_000));

        $this->assertSame(299_440, $result->total->pence);
    }

    public function test_main_and_upper_rate(): void
    {
        // £60,000: £37,700 @ 8% (£3,016.00) + £9,730 @ 2% (£194.60) = £3,210.60.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(60_000));

        $this->assertSame(321_060, $result->total->pence);
    }

    public function test_no_ni_below_primary_threshold(): void
    {
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(10_000));

        $this->assertSame(0, $result->total->pence);
    }

    public function test_no_ni_once_state_pension_age_reached(): void
    {
        // The same £60,000 of earnings, but the earner is over State Pension age:
        // NI ends at SPA, so nothing is due even though there are earnings.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(60_000), hasReachedStatePensionAge: true);

        $this->assertSame(0, $result->total->pence);
        $this->assertSame([], $result->bands);
    }

    /**
     * £62,570 splits cleanly into a £37,700 main band (PT £12,570 → UEL £50,270) and £12,300 above
     * the UEL, so each category's rate lands on a round figure. Category letters group by employee
     * rate per gov.uk: A/F/H/M/N/V standard 8%, B/E/I reduced 1.85%, D/J/L/Z deferred 2%, C/K/S/X nil.
     */
    public function test_category_selects_the_employee_main_band_rate(): void
    {
        $earn = Money::fromPounds(62_570);
        $ni = fn (?string $c): int => $this->calculator()->onEmploymentEarnings($earn, category: $c)->total->pence;

        // Standard (A): £37,700 @ 8% (£3,016) + £12,300 @ 2% (£246) = £3,262.00.
        $this->assertSame(326_200, $ni('A'));
        $this->assertSame(326_200, $ni(null), 'null category defaults to the standard rate');
        $this->assertSame(326_200, $ni('V'), 'other standard-rate letters match A');

        // Reduced (B/E/I): £37,700 @ 1.85% (£697.45) + £12,300 @ 2% (£246) = £943.45.
        $this->assertSame(94_345, $ni('B'));
        $this->assertSame(94_345, $ni('I'));

        // Deferred (D/J/L/Z): £37,700 @ 2% (£754) + £12,300 @ 2% (£246) = £1,000.00.
        $this->assertSame(100_000, $ni('J'));
        $this->assertSame(100_000, $ni('Z'));
    }

    public function test_no_liability_categories_pay_no_ni(): void
    {
        $earn = Money::fromPounds(62_570);

        foreach (['C', 'K', 'S', 'X'] as $category) {
            $result = $this->calculator()->onEmploymentEarnings($earn, category: $category);
            $this->assertSame(0, $result->total->pence, "category {$category} carries no employee NI");
            $this->assertSame([], $result->bands);
        }
    }

    public function test_category_is_case_and_whitespace_insensitive(): void
    {
        $earn = Money::fromPounds(62_570);

        $this->assertSame(94_345, $this->calculator()->onEmploymentEarnings($earn, category: ' b ')->total->pence);
    }
}
