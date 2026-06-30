<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Property;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Property\CgtPrivateResidenceCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class CgtPrivateResidenceCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): CgtPrivateResidenceCalculator
    {
        return new CgtPrivateResidenceCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_main_home_throughout_is_fully_relieved(): void
    {
        // Lived in as the only home for the whole 20 years: no CGT.
        $result = $this->calculator()->compute(
            gain: Money::fromPounds(250_000),
            totalOwnershipMonths: 240,
            mainResidenceMonths: 240,
            higherRate: true,
        );

        $this->assertSame(0, $result->tax->pence);
        $this->assertSame(25_000_000, $result->privateResidenceReliefGain->pence);
        $this->assertSame(0, $result->chargeableGain->pence);
    }

    public function test_let_period_is_partly_chargeable(): void
    {
        // £100,000 gain, owned 240 months, main residence for 180, let for the rest.
        // Relief = (180 + 9) / 240 = £78,750; chargeable £21,250; less £3,000 AEA
        // = £18,250 @ 24% = £4,380.
        $result = $this->calculator()->compute(
            gain: Money::fromPounds(100_000),
            totalOwnershipMonths: 240,
            mainResidenceMonths: 180,
            higherRate: true,
        );

        $this->assertSame(7_875_000, $result->privateResidenceReliefGain->pence);
        $this->assertSame(2_125_000, $result->chargeableGain->pence);
        $this->assertSame(300_000, $result->annualExemptAmountUsed->pence);
        $this->assertSame(1_825_000, $result->taxableGain->pence);
        $this->assertSame(438_000, $result->tax->pence);
    }

    public function test_joint_owners_each_get_their_own_allowance(): void
    {
        // Same gain as the let-period case, but owned jointly: the £21,250 chargeable splits
        // £10,625 each, less £3,000 allowance EACH = £7,625 each @ 24% = £1,830 each = £3,660.
        // Two allowances (£6,000) vs one, so less tax than the £4,380 single-owner figure.
        $result = $this->calculator()->compute(
            gain: Money::fromPounds(100_000),
            totalOwnershipMonths: 240,
            mainResidenceMonths: 180,
            higherRate: true,
            owners: 2,
        );

        $this->assertSame(2_125_000, $result->chargeableGain->pence);   // unchanged total chargeable
        $this->assertSame(600_000, $result->annualExemptAmountUsed->pence); // £3,000 × 2
        $this->assertSame(1_525_000, $result->taxableGain->pence);      // £7,625 × 2
        $this->assertSame(366_000, $result->tax->pence);               // £1,830 × 2
    }

    public function test_property_never_a_main_residence_gets_no_relief(): void
    {
        // A pure investment property: no PRR, no final-period exemption.
        $result = $this->calculator()->compute(
            gain: Money::fromPounds(50_000),
            totalOwnershipMonths: 120,
            mainResidenceMonths: 0,
            higherRate: false,
        );

        $this->assertSame(0, $result->privateResidenceReliefGain->pence);
        // £50,000 - £3,000 AEA = £47,000 @ 18% = £8,460.
        $this->assertSame(846_000, $result->tax->pence);
    }
}
