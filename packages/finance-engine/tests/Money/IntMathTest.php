<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\RoundingMode;

final class IntMathTest extends TestCase
{
    public function testExactDivisionNeedsNoRounding(): void
    {
        $this->assertSame(50, IntMath::divRound(100, 2, RoundingMode::HalfUp));
        $this->assertSame(-50, IntMath::divRound(-100, 2, RoundingMode::HalfUp));
    }

    public function testHalfUpRoundsTiesAwayFromZero(): void
    {
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::HalfUp));   // 3.5 -> 4
        $this->assertSame(-4, IntMath::divRound(-7, 2, RoundingMode::HalfUp)); // -3.5 -> -4
        $this->assertSame(1, IntMath::divRound(2, 3, RoundingMode::HalfUp));   // 0.66 -> 1
        $this->assertSame(0, IntMath::divRound(1, 3, RoundingMode::HalfUp));   // 0.33 -> 0
    }

    public function testHalfEvenRoundsTiesToEven(): void
    {
        $this->assertSame(2, IntMath::divRound(5, 2, RoundingMode::HalfEven)); // 2.5 -> 2
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::HalfEven)); // 3.5 -> 4
        $this->assertSame(1, IntMath::divRound(2, 3, RoundingMode::HalfEven)); // 0.66 -> 1
    }

    public function testFloorRoundsTowardsNegativeInfinity(): void
    {
        $this->assertSame(0, IntMath::divRound(1, 2, RoundingMode::Floor));
        $this->assertSame(-1, IntMath::divRound(-1, 2, RoundingMode::Floor));
        $this->assertSame(3, IntMath::divRound(7, 2, RoundingMode::Floor));
    }

    public function testCeilRoundsTowardsPositiveInfinity(): void
    {
        $this->assertSame(1, IntMath::divRound(1, 2, RoundingMode::Ceil));
        $this->assertSame(0, IntMath::divRound(-1, 2, RoundingMode::Ceil));
        $this->assertSame(4, IntMath::divRound(7, 2, RoundingMode::Ceil));
    }

    public function testNegativeDenominatorIsNormalised(): void
    {
        $this->assertSame(4, IntMath::divRound(-7, -2, RoundingMode::HalfUp));
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntMath::divRound(1, 0);
    }
}
