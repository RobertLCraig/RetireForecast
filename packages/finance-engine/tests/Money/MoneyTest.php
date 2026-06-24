<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Money\RoundingMode;

final class MoneyTest extends TestCase
{
    public function test_construction_helpers(): void
    {
        $this->assertSame(1_257_000, Money::fromPounds(12_570)->pence);
        $this->assertSame(12_345, Money::fromPence(12_345)->pence);
        $this->assertSame(550, Money::of(5, 50)->pence);
        $this->assertSame(-550, Money::of(-5, 50)->pence);
        $this->assertSame(0, Money::zero()->pence);
    }

    public function test_of_rejects_out_of_range_pence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(5, 100);
    }

    public function test_adding_and_subtracting(): void
    {
        $a = Money::fromPounds(100);
        $b = Money::of(0, 50);

        $this->assertSame(10_050, $a->plus($b)->pence);
        $this->assertSame(9_950, $a->minus($b)->pence);
        $this->assertSame(30_000, $a->times(3)->pence);
    }

    public function test_apply_rate_rounds_to_pence(): void
    {
        // £1.01 at 50% = 50.5p -> 51p half-up, 50p floored.
        $amount = Money::fromPence(101);
        $this->assertSame(51, $amount->applyRate(Percent::fromPercent(50))->pence);
        $this->assertSame(50, $amount->applyRate(Percent::fromPercent(50), RoundingMode::Floor)->pence);

        // £45,000 at 40% = £18,000 exactly.
        $this->assertSame(1_800_000, Money::fromPounds(45_000)->applyRate(Percent::fromPercent(40))->pence);
    }

    public function test_min_zero_clamps_negatives(): void
    {
        $this->assertSame(0, Money::fromPounds(-5)->minZero()->pence);
        $this->assertSame(500, Money::fromPounds(5)->minZero()->pence);
    }

    public function test_comparison_and_min_max(): void
    {
        $a = Money::fromPounds(10);
        $b = Money::fromPounds(20);

        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThanOrEqual($a));
        $this->assertTrue(Money::fromPounds(10)->equals($a));
        $this->assertSame($a->pence, Money::min($a, $b)->pence);
        $this->assertSame($b->pence, Money::max($a, $b)->pence);
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromPounds(1, 'GBP')->plus(Money::fromPounds(1, 'EUR'));
    }

    public function test_formatting(): void
    {
        $this->assertSame('£1,234.56', Money::fromPence(123_456)->format());
        $this->assertSame('-£5.00', Money::fromPounds(-5)->format());
        $this->assertSame('1234.56', Money::fromPence(123_456)->toDecimal());
    }
}
