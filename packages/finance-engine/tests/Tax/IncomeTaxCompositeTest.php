<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Covers the full stacking of non-savings, savings and dividend income, including
 * the savings starting-rate band, the Personal Savings Allowance and the dividend
 * allowance, against known HMRC outcomes.
 */
final class IncomeTaxCompositeTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): IncomeTaxCalculator
    {
        return new IncomeTaxCalculator(TaxYearRegistry::for($taxYear));
    }

    public function testNonSavingsOnlyMatchesTheSimpleCalculator(): void
    {
        // £20,000 non-savings: (£20,000 - £12,570) @ 20% = £1,486 — same as onNonSavingsIncome.
        $result = $this->calculator()->compute(
            TaxableIncome::ofNonSavings(Money::fromPounds(20_000))
        );

        $this->assertSame(148_600, $result->total->pence);
        $this->assertSame(148_600, $result->nonSavingsTax->pence);
    }

    public function testSaverWithMaximumTaxFreeSavings(): void
    {
        // The classic "£18,570 of income, all tax-free for a saver":
        // £12,570 pension (covered by PA) + £6,000 interest, covered by the
        // £5,000 starting-rate band + £1,000 PSA. No tax.
        $result = $this->calculator()->compute(new TaxableIncome(
            nonSavings: Money::fromPounds(12_570),
            savings: Money::fromPounds(6_000),
            dividends: Money::zero(),
        ));

        $this->assertSame(0, $result->total->pence);
    }

    public function testSavingsBeyondStartingRateAndPsa(): void
    {
        // £12,570 pension (uses PA) + £10,000 interest.
        // £5,000 starting rate @ 0% + £1,000 PSA @ 0% + £4,000 @ 20% = £800.
        $result = $this->calculator()->compute(new TaxableIncome(
            nonSavings: Money::fromPounds(12_570),
            savings: Money::fromPounds(10_000),
            dividends: Money::zero(),
        ));

        $this->assertSame(80_000, $result->savingsTax->pence);
        $this->assertSame(80_000, $result->total->pence);
    }

    public function testBasicRateDividends(): void
    {
        // £12,570 pension (uses PA) + £5,000 dividends.
        // £500 dividend allowance @ 0% + £4,500 @ 8.75% ordinary = £393.75.
        $result = $this->calculator()->compute(new TaxableIncome(
            nonSavings: Money::fromPounds(12_570),
            savings: Money::zero(),
            dividends: Money::fromPounds(5_000),
        ));

        $this->assertSame(39_375, $result->dividendsTax->pence);
    }

    public function testDividendRatesRiseIn2026_27(): void
    {
        $income = new TaxableIncome(
            nonSavings: Money::fromPounds(12_570),
            savings: Money::zero(),
            dividends: Money::fromPounds(5_000),
        );

        // £4,500 taxable dividends: 8.75% in 2025/26 vs 10.75% in 2026/27.
        $this->assertSame(39_375, $this->calculator('2025-26')->compute($income)->dividendsTax->pence);
        $this->assertSame(48_375, $this->calculator('2026-27')->compute($income)->dividendsTax->pence);
    }

    public function testHigherRateMixOfAllThreeCategories(): void
    {
        // £50,000 salary + £2,000 interest + £3,000 dividends (a higher-rate taxpayer).
        $result = $this->calculator()->compute(new TaxableIncome(
            nonSavings: Money::fromPounds(50_000),
            savings: Money::fromPounds(2_000),
            dividends: Money::fromPounds(3_000),
        ));

        // Non-savings: £37,430 @ 20% = £7,486.
        $this->assertSame(748_600, $result->nonSavingsTax->pence);

        // Savings: PSA is £500 (higher-rate taxpayer); starting rate is gone.
        // £500 @ 0% + £1,500 @ 40% = £600.
        $this->assertSame(60_000, $result->savingsTax->pence);

        // Dividends: £500 allowance @ 0% + £2,500 @ 33.75% upper = £843.75.
        $this->assertSame(84_375, $result->dividendsTax->pence);

        $this->assertSame(892_975, $result->total->pence);
    }

    public function testAdditionalRateTaxpayerGetsNoPsa(): void
    {
        // £200,000 salary leaves the PA fully tapered and pushes savings into the
        // additional rate, where the PSA is £0.
        $result = $this->calculator()->compute(new TaxableIncome(
            nonSavings: Money::fromPounds(200_000),
            savings: Money::fromPounds(1_000),
            dividends: Money::zero(),
        ));

        // £1,000 savings, no PSA, all at the additional rate 45% = £450.
        $this->assertSame(45_000, $result->savingsTax->pence);
    }
}
