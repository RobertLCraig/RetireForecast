<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\SpendPath;
use RetireForecast\FinanceEngine\Money\Money;

final class SpendPathTest extends TestCase
{
    public function test_a_flat_path_returns_the_same_amount_at_every_age(): void
    {
        $path = SpendPath::flat(Money::fromPounds(30_000));

        $this->assertTrue($path->isFlat());
        $this->assertTrue($path->amountAt(50)->equals(Money::fromPounds(30_000)));
        $this->assertTrue($path->amountAt(66)->equals(Money::fromPounds(30_000)));
        $this->assertTrue($path->amountAt(100)->equals(Money::fromPounds(30_000)));
        $this->assertTrue($path->startAmount()->equals(Money::fromPounds(30_000)));
    }

    public function test_a_two_band_step_holds_each_amount_until_the_next_band(): void
    {
        // Mark's plan: £60k to 75, then £40k.
        $path = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(60_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(40_000)],
        ]);

        $this->assertFalse($path->isFlat());
        $this->assertTrue($path->amountAt(65)->equals(Money::fromPounds(60_000)));
        $this->assertTrue($path->amountAt(74)->equals(Money::fromPounds(60_000)));
        $this->assertTrue($path->amountAt(75)->equals(Money::fromPounds(40_000)));
        $this->assertTrue($path->amountAt(99)->equals(Money::fromPounds(40_000)));
    }

    public function test_below_the_first_band_the_earliest_amount_holds(): void
    {
        $path = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(60_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(40_000)],
        ]);

        // Spend does not vanish before the first breakpoint — it is clamped to the go-go figure.
        $this->assertTrue($path->amountAt(40)->equals(Money::fromPounds(60_000)));
        $this->assertTrue($path->amountAt(64)->equals(Money::fromPounds(60_000)));
    }

    public function test_bands_are_sorted_so_input_order_does_not_matter(): void
    {
        $ordered = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(60_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(40_000)],
            ['fromAge' => 85, 'amount' => Money::fromPounds(50_000)],
        ]);
        $shuffled = SpendPath::fromBands([
            ['fromAge' => 85, 'amount' => Money::fromPounds(50_000)],
            ['fromAge' => 65, 'amount' => Money::fromPounds(60_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(40_000)],
        ]);

        $this->assertTrue($ordered->equals($shuffled));
        // The late uptick (health costs) is reachable — the smile, not just a decline.
        $this->assertTrue($shuffled->amountAt(90)->equals(Money::fromPounds(50_000)));
    }

    public function test_an_empty_band_list_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpendPath::fromBands([]);
    }

    public function test_a_duplicate_band_age_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(60_000)],
            ['fromAge' => 65, 'amount' => Money::fromPounds(40_000)],
        ]);
    }

    public function test_plus_sums_two_paths_band_for_band_across_the_union_of_breakpoints(): void
    {
        // An essential line (flat) plus a discretionary line that steps down: the aggregate
        // must reconcile to the sum at *every* age, keeping each path's own breakpoints.
        $essential = SpendPath::flat(Money::fromPounds(20_000));
        $discretionary = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(15_000)],
            ['fromAge' => 80, 'amount' => Money::fromPounds(5_000)],
        ]);

        $total = $essential->plus($discretionary);

        $this->assertTrue($total->amountAt(60)->equals(Money::fromPounds(35_000)));
        $this->assertTrue($total->amountAt(65)->equals(Money::fromPounds(35_000)));
        $this->assertTrue($total->amountAt(79)->equals(Money::fromPounds(35_000)));
        $this->assertTrue($total->amountAt(80)->equals(Money::fromPounds(25_000)));
        $this->assertTrue($total->amountAt(95)->equals(Money::fromPounds(25_000)));
    }

    public function test_plus_reconciles_at_every_breakpoint_of_both_operands(): void
    {
        $a = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(10_000)],
            ['fromAge' => 70, 'amount' => Money::fromPounds(8_000)],
        ]);
        $b = SpendPath::fromBands([
            ['fromAge' => 68, 'amount' => Money::fromPounds(3_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(1_000)],
        ]);

        $total = $a->plus($b);

        // The invariant: total(age) == a(age) + b(age), at every age including both sets of steps.
        foreach ([60, 65, 68, 70, 72, 75, 90] as $age) {
            $expected = $a->amountAt($age)->plus($b->amountAt($age));
            $this->assertTrue(
                $total->amountAt($age)->equals($expected),
                "reconciliation failed at age {$age}: {$total->amountAt($age)} != {$expected}",
            );
        }
    }

    public function test_plus_flat_adds_a_contingent_cost_to_every_band(): void
    {
        $path = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(20_000)],
            ['fromAge' => 75, 'amount' => Money::fromPounds(15_000)],
        ]);

        $withMortgage = $path->plusFlat(Money::fromPounds(6_000));

        $this->assertTrue($withMortgage->amountAt(65)->equals(Money::fromPounds(26_000)));
        $this->assertTrue($withMortgage->amountAt(75)->equals(Money::fromPounds(21_000)));
    }

    public function test_minus_flat_removes_a_cost_from_every_band_never_below_zero(): void
    {
        $path = SpendPath::fromBands([
            ['fromAge' => 65, 'amount' => Money::fromPounds(20_000)],
            ['fromAge' => 90, 'amount' => Money::fromPounds(4_000)],
        ]);

        $withoutHousing = $path->minusFlat(Money::fromPounds(6_000));

        $this->assertTrue($withoutHousing->amountAt(65)->equals(Money::fromPounds(14_000)));
        // The later band would go negative — it is floored at zero, like the flat withers.
        $this->assertTrue($withoutHousing->amountAt(90)->equals(Money::zero()));
    }
}
