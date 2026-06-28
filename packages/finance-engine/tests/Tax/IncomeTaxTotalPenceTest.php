<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Pins the perf invariant: the lean integer {@see IncomeTaxCalculator::totalPence}
 * (run millions of times in the Monte Carlo hot path) equals the Money-decorated
 * {@see IncomeTaxCalculator::compute}'s total to the penny, across every band
 * crossing, allowance taper and PSA tier. The two share one band core; this test
 * makes any future drift between them a visible failure, not a silent one.
 */
final class IncomeTaxTotalPenceTest extends TestCase
{
    /**
     * Values chosen to land on and around every boundary that matters: the
     * personal allowance, the basic/higher/additional thresholds, the PA taper
     * window (£100k-£125,140), the savings starting-rate band + PSA tiers, and the
     * dividend allowance. Odd-pence amounts exercise the per-slice rounding.
     *
     * @return list<int> pence
     */
    private function nonSavingsGrid(): array
    {
        return array_map(fn (int $p): int => $p, [
            0, 500_00, 1_257_000, 1_257_001, 2_000_000, 3_743_000,
            5_027_000, 5_027_001, 8_000_000, 10_000_000, 12_514_000,
            12_514_100, 15_000_000, 20_000_000, 26_000_000, 4_999_949,
        ]);
    }

    /** @return list<int> pence */
    private function savingsGrid(): array
    {
        return [0, 50_000, 100_000, 500_000, 600_000, 1_000_000, 2_000_001];
    }

    /** @return list<int> pence */
    private function dividendsGrid(): array
    {
        return [0, 50_000, 100_000, 450_100, 2_000_000];
    }

    public function test_total_pence_equals_compute_total_across_the_band_grid(): void
    {
        $checked = 0;
        foreach (['2025-26', '2026-27'] as $taxYear) {
            $calculator = new IncomeTaxCalculator(TaxYearRegistry::for($taxYear));

            foreach ($this->nonSavingsGrid() as $ns) {
                foreach ($this->savingsGrid() as $savings) {
                    foreach ($this->dividendsGrid() as $dividends) {
                        $income = new TaxableIncome(
                            Money::fromPence($ns),
                            Money::fromPence($savings),
                            Money::fromPence($dividends),
                        );

                        $this->assertSame(
                            $calculator->compute($income)->total->pence,
                            $calculator->totalPence($income),
                            "totalPence drifted from compute() at {$taxYear}: ns={$ns} savings={$savings} dividends={$dividends}",
                        );
                        $checked++;
                    }
                }
            }
        }

        // Guard against a vacuous grid silently shrinking to nothing.
        $this->assertSame(16 * 7 * 5 * 2, $checked);
    }

    public function test_total_pence_matches_the_known_composite_example(): void
    {
        // The higher-rate mix from IncomeTaxCompositeTest: £50,000 + £2,000 + £3,000.
        $income = new TaxableIncome(
            nonSavings: Money::fromPounds(50_000),
            savings: Money::fromPounds(2_000),
            dividends: Money::fromPounds(3_000),
        );

        $this->assertSame(892_975, (new IncomeTaxCalculator(TaxYearRegistry::for('2025-26')))->totalPence($income));
    }
}
