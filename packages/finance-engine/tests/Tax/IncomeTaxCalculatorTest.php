<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use RuntimeException;

final class IncomeTaxCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): IncomeTaxCalculator
    {
        return new IncomeTaxCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_basic_rate_only(): void
    {
        // £20,000: (£20,000 - £12,570 PA) = £7,430 at 20% = £1,486.
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(20_000));

        $this->assertSame(148_600, $result->total->pence);
        $this->assertSame(1_257_000, $result->personalAllowance->pence);
    }

    public function test_higher_rate(): void
    {
        // £65,000: £37,700 @ 20% (£7,540) + £14,730 @ 40% (£5,892) = £13,432.
        // This is the non-emergency liability behind worked example A
        // (a £60k UFPLS, £15k tax-free + £45k taxable, on £20k other income).
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(65_000));

        $this->assertSame(1_343_200, $result->total->pence);
    }

    public function test_additional_rate_with_fully_tapered_allowance(): void
    {
        // £150,000: PA fully tapered to £0.
        // £37,700 @ 20% (£7,540) + £87,440 @ 40% (£34,976) + £24,860 @ 45% (£11,187) = £53,703.
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(150_000));

        $this->assertSame(0, $result->personalAllowance->pence);
        $this->assertSame(5_370_300, $result->total->pence);
    }

    public function test_partial_allowance_taper(): void
    {
        // £110,000: PA = £12,570 - (£10,000 / 2) = £7,570.
        // £37,700 @ 20% (£7,540) + £64,730 @ 40% (£25,892) = £33,432.
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(110_000));

        $this->assertSame(757_000, $result->personalAllowance->pence);
        $this->assertSame(3_343_200, $result->total->pence);
    }

    public function test_allowance_untouched_below_taper_threshold(): void
    {
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(50_000));

        $this->assertSame(1_257_000, $result->personalAllowance->pence);
    }

    public function test_no_tax_below_personal_allowance(): void
    {
        $result = $this->calculator()->onNonSavingsIncome(Money::fromPounds(10_000));

        $this->assertSame(0, $result->total->pence);
    }

    public function test_scotland_is_refused_until_bands_are_loaded(): void
    {
        $this->expectException(RuntimeException::class);
        TaxYearRegistry::for('2025-26', RegionProfile::Scotland);
    }

    public function test_unknown_tax_year_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxYearRegistry::for('2019-20');
    }
}
