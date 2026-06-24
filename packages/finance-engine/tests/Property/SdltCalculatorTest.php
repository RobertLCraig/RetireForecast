<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Property;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Property\SdltCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class SdltCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): SdltCalculator
    {
        return new SdltCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_no_sdlt_below_the_starting_threshold(): void
    {
        $result = $this->calculator()->compute(Money::fromPounds(120_000));

        $this->assertSame(0, $result->total->pence);
    }

    public function test_progressive_bands_on_a_typical_purchase(): void
    {
        // £300,000: £125k @ 0% + £125k @ 2% (£2,500) + £50k @ 5% (£2,500) = £5,000.
        $result = $this->calculator()->compute(Money::fromPounds(300_000));

        $this->assertSame(500_000, $result->total->pence);
        $this->assertSame(500_000, $result->baseTax->pence);
        $this->assertSame(0, $result->surcharge->pence);
    }

    public function test_additional_property_surcharge_is_added_and_reclaimable(): void
    {
        // Same £300,000 but a second property held momentarily: +5% of £300,000 = £15,000.
        $result = $this->calculator()->compute(Money::fromPounds(300_000), additionalProperty: true);

        $this->assertSame(2_000_000, $result->total->pence);     // £5,000 + £15,000
        $this->assertSame(1_500_000, $result->surcharge->pence); // £15,000
        $this->assertSame(1_500_000, $result->reclaimableSurcharge->pence);
        $this->assertTrue($result->additionalProperty);
    }

    public function test_higher_bands_on_an_expensive_purchase(): void
    {
        // £1,000,000: £125k@0 + £125k@2% (£2,500) + £675k@5% (£33,750) + £75k@10% (£7,500) = £43,750.
        $result = $this->calculator()->compute(Money::fromPounds(1_000_000));

        $this->assertSame(4_375_000, $result->total->pence);
    }
}
