<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Money;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Percent;

final class PercentTest extends TestCase
{
    public function testFromPercentConvertsToBasisPoints(): void
    {
        $this->assertSame(2_000, Percent::fromPercent(20)->basisPoints);
        $this->assertSame(875, Percent::fromPercent(8.75)->basisPoints);
        $this->assertSame(3_935, Percent::fromPercent(39.35)->basisPoints);
        $this->assertSame(480, Percent::fromPercent(4.8)->basisPoints);
    }

    public function testFromBasisPoints(): void
    {
        $this->assertSame(4_500, Percent::fromBasisPoints(4_500)->basisPoints);
    }

    public function testZero(): void
    {
        $this->assertSame(0, Percent::zero()->basisPoints);
    }

    public function testDisplayHelpers(): void
    {
        $this->assertSame(0.0875, Percent::fromPercent(8.75)->asFraction());
        $this->assertSame(8.75, Percent::fromPercent(8.75)->asPercent());
    }
}
