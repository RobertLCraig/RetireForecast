<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Iht\EstateValuer;
use RetireForecast\FinanceEngine\Money\Money;

class EstateValuerTest extends TestCase
{
    public function test_the_estate_reconciles_to_the_sum_of_its_parts(): void
    {
        $valuation = EstateValuer::value(
            liquid: Money::fromPence(150_000_00),
            pensionValue: Money::fromPence(400_000_00),
            homeValue: Money::fromPence(500_000_00),
            mortgage: Money::fromPence(80_000_00),
        );

        // Home equity = value − mortgage.
        $this->assertSame(420_000_00, $valuation->homeEquity->pence);
        // Estate excluding pensions = liquid + home equity, penny-exact.
        $this->assertSame(150_000_00 + 420_000_00, $valuation->estateExcludingPensions->pence);
        // The pension leg stays separate (it only enters the estate from April 2027).
        $this->assertSame(400_000_00, $valuation->pensionValue->pence);
        // The whole is exactly the sum of its parts.
        $this->assertSame(
            $valuation->liquid->pence + $valuation->homeEquity->pence,
            $valuation->estateExcludingPensions->pence,
        );
    }

    public function test_negative_home_equity_is_floored_at_zero(): void
    {
        // A mortgage larger than the value is not a negative estate.
        $valuation = EstateValuer::value(
            liquid: Money::fromPence(20_000_00),
            pensionValue: Money::zero(),
            homeValue: Money::fromPence(100_000_00),
            mortgage: Money::fromPence(140_000_00),
        );

        $this->assertSame(0, $valuation->homeEquity->pence);
        $this->assertSame(20_000_00, $valuation->estateExcludingPensions->pence);
    }

    public function test_a_share_of_the_home_is_valued_by_passing_the_shared_legs(): void
    {
        // The caller applies the split (e.g. a cohabiting first death gets half the home);
        // the valuer just nets whatever share it is handed.
        $whole = EstateValuer::value(Money::zero(), Money::zero(), Money::fromPence(600_000_00), Money::fromPence(100_000_00));
        $half = EstateValuer::value(Money::zero(), Money::zero(), Money::fromPence(300_000_00), Money::fromPence(50_000_00));

        $this->assertSame(500_000_00, $whole->homeEquity->pence);
        $this->assertSame(250_000_00, $half->homeEquity->pence);
    }
}
