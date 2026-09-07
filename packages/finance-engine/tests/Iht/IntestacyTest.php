<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The first death of a couple, when there is no will and when the survivor is not a UK long-term
 * resident. Board card 0054: the model granted an unlimited spouse exemption on a will nobody was
 * asked about, to a survivor whose residence position nobody was asked about either.
 *
 * Figures throughout: nil-rate band £325,000, statutory legacy £322,000, IHT 40%.
 */
final class IntestacyTest extends TestCase
{
    private function calculator(): InheritanceTaxCalculator
    {
        return new InheritanceTaxCalculator(TaxYearRegistry::for('2025-26'));
    }

    private function firstDeath(
        int $estatePounds,
        bool $deceasedLeftAWill,
        bool $spouseSurvives = true,
        bool $issue = true,
        bool $survivorIsUkLongTermResident = true,
    ) {
        return $this->calculator()->computeFirstDeath(
            estateExcludingPensions: Money::fromPounds($estatePounds),
            unusedPensionValue: Money::zero(),
            includePensionsInEstate: false,
            spouseSurvives: $spouseSurvives,
            deceasedLeftAWill: $deceasedLeftAWill,
            issueTakeUnderIntestacy: $issue,
            survivorIsUkLongTermResident: $survivorIsUkLongTermResident,
        );
    }

    public function test_a_will_makes_the_whole_first_estate_spouse_exempt(): void
    {
        $result = $this->firstDeath(1_000_000, deceasedLeftAWill: true);

        $this->assertSame(0, $result->tax->pence);
        $this->assertSame(0, $result->taxableEstate->pence);
        // Nothing was chargeable, so nothing spent the band and the whole of it transfers.
        $this->assertSame(0, $result->nilRateBandUsed->pence);
        $this->assertContains(
            WarningCode::IHT_SPOUSE_EXEMPTION,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_no_will_splits_the_estate_under_the_intestacy_rules(): void
    {
        // £1,000,000 estate, no will, children alive.
        //   spouse: £322,000 statutory legacy + half the £678,000 residue (£339,000) = £661,000
        //   children: the other £339,000, which is a chargeable transfer
        //   £339,000 less the £325,000 nil-rate band = £14,000 taxable at 40% = £5,600
        $result = $this->firstDeath(1_000_000, deceasedLeftAWill: false);

        $this->assertSame(1_400_000, $result->taxableEstate->pence, '£14,000 above the nil-rate band');
        $this->assertSame(560_000, $result->tax->pence, '£5,600 of tax on the first death');
        $this->assertSame(32_500_000, $result->nilRateBandUsed->pence, 'the whole £325,000 band is spent');
        $this->assertContains(
            WarningCode::IHT_INTESTACY,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_an_intestate_estate_below_the_statutory_legacy_still_passes_wholly_to_the_spouse(): void
    {
        // £300,000 is under the £322,000 fixed net sum, so the spouse takes all of it and there is
        // nothing for the children to take: the exemption is total and no band is spent.
        $result = $this->firstDeath(300_000, deceasedLeftAWill: false);

        $this->assertSame(0, $result->tax->pence);
        $this->assertSame(0, $result->nilRateBandUsed->pence);
    }

    public function test_no_children_means_the_spouse_takes_the_whole_intestate_estate(): void
    {
        // Intestacy only splits an estate where there is issue to take a share. A plan that leaves
        // nothing to descendants has a surviving spouse who takes everything.
        $result = $this->firstDeath(1_000_000, deceasedLeftAWill: false, issue: false);

        $this->assertSame(0, $result->tax->pence);
        $this->assertNotContains(
            WarningCode::IHT_INTESTACY,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_a_cohabiting_first_death_gets_no_exemption_at_all(): void
    {
        // £1,000,000 chargeable, less the £325,000 band = £675,000 at 40% = £270,000.
        $result = $this->firstDeath(1_000_000, deceasedLeftAWill: true, spouseSurvives: false);

        $this->assertSame(27_000_000, $result->tax->pence);
    }

    public function test_a_survivor_who_is_not_a_uk_long_term_resident_gets_a_capped_exemption(): void
    {
        // The exemption stops at the £325,000 nil-rate band, so £675,000 of a £1,000,000 estate is
        // chargeable; the band then covers £325,000 of that, leaving £350,000 at 40% = £140,000.
        $result = $this->firstDeath(1_000_000, deceasedLeftAWill: true, survivorIsUkLongTermResident: false);

        $this->assertSame(14_000_000, $result->tax->pence);
        $this->assertContains(
            WarningCode::IHT_SPOUSE_EXEMPTION_CAPPED,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_only_the_unused_part_of_the_first_death_band_transfers(): void
    {
        // Second death, £900,000 estate with a £400,000 home to children. With a full double band
        // (2 x £325,000 + 2 x £175,000) nothing is taxable. Spend £325,000 of it at the first death
        // and the bands fall to £325,000 + £350,000 = £675,000, leaving £225,000 at 40% = £90,000.
        $spent = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(900_000),
            unusedPensionValue: Money::zero(),
            includePensionsInEstate: false,
            homePassingToDescendants: Money::fromPounds(400_000),
            nilRateBandMultiplier: 2,
            nilRateBandUsedAtFirstDeath: Money::fromPounds(325_000),
        );

        $this->assertSame(32_500_000, $spent->nilRateBandUsed->pence, 'one band left, not two');
        $this->assertSame(9_000_000, $spent->tax->pence);
    }
}
