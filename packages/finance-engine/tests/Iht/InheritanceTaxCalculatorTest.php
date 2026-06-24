<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class InheritanceTaxCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): InheritanceTaxCalculator
    {
        return new InheritanceTaxCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_couple_with_home_to_children_can_pass_a_million_tax_free(): void
    {
        // Second death of a couple: 2 x £325k NRB + 2 x £175k RNRB = £1,000,000 of
        // bands, against a £900,000 estate including a £400,000 home to descendants.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(900_000),
            unusedPensionValue: Money::zero(),
            includePensionsInEstate: false,
            homePassingToDescendants: Money::fromPounds(400_000),
            nilRateBandMultiplier: 2,
        );

        $this->assertSame(0, $result->tax->pence);
        $this->assertSame(35_000_000, $result->residenceNilRateBandUsed->pence); // £350,000
    }

    public function test_single_estate_above_the_bands_is_taxed_at_40_percent(): void
    {
        // £600,000 estate, £300,000 home to children. NRB £325k + RNRB £175k = £500k.
        // £100,000 taxable @ 40% = £40,000.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::zero(),
            includePensionsInEstate: false,
            homePassingToDescendants: Money::fromPounds(300_000),
            nilRateBandMultiplier: 1,
        );

        $this->assertSame(4_000_000, $result->tax->pence);
    }

    public function test_pensions_in_estate_toggle_changes_the_tax(): void
    {
        $args = [
            'estateExcludingPensions' => Money::fromPounds(500_000),
            'unusedPensionValue' => Money::fromPounds(200_000),
            'homePassingToDescendants' => Money::zero(),
            'nilRateBandMultiplier' => 1,
        ];

        $without = $this->calculator()->compute(...[...$args, 'includePensionsInEstate' => false]);
        $with = $this->calculator()->compute(...[...$args, 'includePensionsInEstate' => true]);

        // Without: (£500k - £325k) @ 40% = £70,000.
        $this->assertSame(7_000_000, $without->tax->pence);
        // With: (£700k - £325k) @ 40% = £150,000.
        $this->assertSame(15_000_000, $with->tax->pence);

        $codes = array_map(fn ($w) => $w->code, $with->warnings);
        $this->assertContains(WarningCode::IHT_PENSIONS_IN_ESTATE, $codes);
        $this->assertSame([], $without->warnings);
    }

    public function test_residence_band_tapers_away_on_large_estates(): void
    {
        // £2,300,000 estate: RNRB reduced by (£2.3m - £2m) / 2 = £150,000, leaving
        // £25,000 of the £175,000 residence band.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(2_300_000),
            unusedPensionValue: Money::zero(),
            includePensionsInEstate: false,
            homePassingToDescendants: Money::fromPounds(400_000),
            nilRateBandMultiplier: 1,
        );

        $this->assertSame(2_500_000, $result->residenceNilRateBandUsed->pence); // £25,000
    }
}
