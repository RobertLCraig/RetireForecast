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

    public function testMainRateOnly(): void
    {
        // £50,000: (£50,000 - £12,570) = £37,430 @ 8% = £2,994.40. None above the UEL.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(50_000));

        $this->assertSame(299_440, $result->total->pence);
    }

    public function testMainAndUpperRate(): void
    {
        // £60,000: £37,700 @ 8% (£3,016.00) + £9,730 @ 2% (£194.60) = £3,210.60.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(60_000));

        $this->assertSame(321_060, $result->total->pence);
    }

    public function testNoNiBelowPrimaryThreshold(): void
    {
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(10_000));

        $this->assertSame(0, $result->total->pence);
    }

    public function testNoNiOnceStatePensionAgeReached(): void
    {
        // The same £60,000 of earnings, but the earner is over State Pension age:
        // NI ends at SPA, so nothing is due even though there are earnings.
        $result = $this->calculator()->onEmploymentEarnings(Money::fromPounds(60_000), hasReachedStatePensionAge: true);

        $this->assertSame(0, $result->total->pence);
        $this->assertSame([], $result->bands);
    }
}
