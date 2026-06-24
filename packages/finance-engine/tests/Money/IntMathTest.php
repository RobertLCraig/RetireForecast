<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\RoundingMode;

final class IntMathTest extends TestCase
{
    public function test_exact_division_needs_no_rounding(): void
    {
        $this->assertSame(50, IntMath::divRound(100, 2, RoundingMode::HalfUp));
        $this->assertSame(-50, IntMath::divRound(-100, 2, RoundingMode::HalfUp));
    }

    public function test_half_up_rounds_ties_away_from_zero(): void
    {
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::HalfUp));   // 3.5 -> 4
        $this->assertSame(-4, IntMath::divRound(-7, 2, RoundingMode::HalfUp)); // -3.5 -> -4
        $this->assertSame(1, IntMath::divRound(2, 3, RoundingMode::HalfUp));   // 0.66 -> 1
        $this->assertSame(0, IntMath::divRound(1, 3, RoundingMode::HalfUp));   // 0.33 -> 0
    }

    public function test_half_even_rounds_ties_to_even(): void
    {
        $this->assertSame(2, IntMath::divRound(5, 2, RoundingMode::HalfEven)); // 2.5 -> 2
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::HalfEven)); // 3.5 -> 4
        $this->assertSame(1, IntMath::divRound(2, 3, RoundingMode::HalfEven)); // 0.66 -> 1
    }

    public function test_floor_rounds_towards_negative_infinity(): void
    {
        $this->assertSame(0, IntMath::divRound(1, 2, RoundingMode::Floor));
        $this->assertSame(-1, IntMath::divRound(-1, 2, RoundingMode::Floor));
        $this->assertSame(3, IntMath::divRound(7, 2, RoundingMode::Floor));
    }

    public function test_ceil_rounds_towards_positive_infinity(): void
    {
        $this->assertSame(1, IntMath::divRound(1, 2, RoundingMode::Ceil));
        $this->assertSame(0, IntMath::divRound(-1, 2, RoundingMode::Ceil));
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::Ceil));
    }

    public function test_negative_denominator_is_normalised(): void
    {
        $this->assertSame(4, IntMath::divRound(-7, -2, RoundingMode::HalfUp));
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntMath::divRound(1, 0);
    }
}
