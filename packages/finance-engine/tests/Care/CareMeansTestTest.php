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
}
