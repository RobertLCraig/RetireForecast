<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Money;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Percent;

final class PercentTest extends TestCase
{
    public function test_from_percent_converts_to_basis_points(): void
    {
        $this->assertSame(2_000, Percent::fromPercent(20)->basisPoints);
        $this->assertSame(875, Percent::fromPercent(8.75)->basisPoints);
        $this->assertSame(3_935, Percent::fromPercent(39.35)->basisPoints);
        $this->assertSame(480, Percent::fromPercent(4.8)->basisPoints);
    }

    public function test_from_basis_points(): void
    {
        $this->assertSame(4_500, Percent::fromBasisPoints(4_500)->basisPoints);
    }

    public function test_zero(): void
    {
        $this->assertSame(0, Percent::zero()->basisPoints);
    }

    public function test_display_helpers(): void
    {
        $this->assertSame(0.0875, Percent::fromPercent(8.75)->asFraction());
        $this->assertSame(8.75, Percent::fromPercent(8.75)->asPercent());
    }
}
