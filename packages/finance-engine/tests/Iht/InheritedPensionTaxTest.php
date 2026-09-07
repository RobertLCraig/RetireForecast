<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0057. An unused pension pot inherited from a member who died at or after 75 is taxed
 * TWICE from April 2027: Inheritance Tax on the estate, and then the beneficiary's own income tax
 * on every pound they draw out of what is left. The calculator only ever showed the first of them,
 * which makes preserving a pot look roughly twice as attractive as it is — on the exact comparison
 * the Inheritance Tax toggle exists to run.
 *
 * Figures throughout: nil-rate band £325,000, IHT 40%, beneficiary marginal rate 40% by default.
 */
final class InheritedPensionTaxTest extends TestCase
{
    private function calculator(): InheritanceTaxCalculator
    {
        return new InheritanceTaxCalculator(TaxYearRegistry::for('2025-26'));
    }

    public function test_a_pot_inherited_on_a_death_at_or_after_75_is_taxed_as_the_beneficiarys_income(): void
    {
        // A £1,000,000 estate: £600,000 of it other assets, £400,000 an unused pension pot.
        //   Inheritance Tax: £1,000,000 less the £325,000 band = £675,000 at 40% = £270,000.
        //   The pot bears its rateable share of that: 400/1000 x £270,000 = £108,000.
        //   The beneficiary draws what is left, £292,000, and pays income tax on it at 40% = £116,800.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: true,
        );

        $this->assertSame(27_000_000, $result->tax->pence, 'the Inheritance Tax is unchanged');
        $this->assertSame(11_680_000, $result->beneficiaryIncomeTax->pence);
        $this->assertSame(40_000_000, $result->unusedPensionPassing->pence);
        $this->assertContains(
            WarningCode::IHT_INHERITED_PENSION_INCOME_TAX,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_a_pot_inherited_on_a_death_before_75_carries_no_beneficiary_income_tax(): void
    {
        // Death before 75 pays the death benefits out free of income tax, so the second charge is
        // simply not there and the tool must not invent it.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: false,
        );

        $this->assertSame(0, $result->beneficiaryIncomeTax->pence);
        $this->assertNotContains(
            WarningCode::IHT_INHERITED_PENSION_INCOME_TAX,
            array_map(static fn ($w) => $w->code, $result->warnings),
        );
    }

    public function test_the_beneficiarys_tax_rate_can_be_edited(): void
    {
        // The same pot left to a basic-rate beneficiary: (£400,000 - £108,000) x 20% = £58,400.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: true,
            beneficiaryMarginalRate: Percent::fromPercent(20),
        );

        $this->assertSame(5_840_000, $result->beneficiaryIncomeTax->pence);
        $this->assertSame(20.0, $result->beneficiaryMarginalRate?->asPercent());
    }

    public function test_the_default_beneficiary_rate_is_the_adverse_one_the_constant_owns(): void
    {
        // The rate nobody entered must be the engine's own disclosed constant, not a figure this
        // test restates: moving the constant has to move the answer.
        $default = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: true,
        );
        $stated = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: true,
            beneficiaryMarginalRate: Percent::fromBasisPoints(
                InheritanceTaxCalculator::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS,
            ),
        );

        $this->assertSame($stated->beneficiaryIncomeTax->pence, $default->beneficiaryIncomeTax->pence);
    }

    public function test_a_pot_outside_the_estate_is_still_taxed_on_the_beneficiary(): void
    {
        // Before April 2027 the pot is not in the estate for Inheritance Tax, but the beneficiary's
        // income tax on drawing it has applied since 2015. It bears no Inheritance Tax, so the
        // whole £400,000 is charged at 40% = £160,000.
        $result = $this->calculator()->compute(
            estateExcludingPensions: Money::fromPounds(600_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: false,
            homePassingToDescendants: Money::zero(),
            deceasedDiedAtOrAfter75: true,
        );

        $this->assertSame(16_000_000, $result->beneficiaryIncomeTax->pence);
    }

    public function test_a_pension_nominated_away_from_the_spouse_is_not_spouse_exempt(): void
    {
        // £500,000 of other assets plus a £400,000 pot, a will, a surviving spouse. The pot is
        // nominated to a child, so it passes under the scheme's discretion and NOT to the spouse:
        // £400,000 chargeable, less the £325,000 band = £75,000 at 40% = £30,000.
        $result = $this->calculator()->computeFirstDeath(
            estateExcludingPensions: Money::fromPounds(500_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            spouseSurvives: true,
            deceasedLeftAWill: true,
            issueTakeUnderIntestacy: true,
            survivorIsUkLongTermResident: true,
            pensionNominatedToSpouse: Money::zero(),
        );

        $this->assertSame(3_000_000, $result->tax->pence);
        $this->assertSame(32_500_000, $result->nilRateBandUsed->pence, 'the whole band is spent');
    }

    public function test_a_pension_nominated_to_the_spouse_keeps_the_exemption(): void
    {
        $result = $this->calculator()->computeFirstDeath(
            estateExcludingPensions: Money::fromPounds(500_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            spouseSurvives: true,
            deceasedLeftAWill: true,
            issueTakeUnderIntestacy: true,
            survivorIsUkLongTermResident: true,
            pensionNominatedToSpouse: Money::fromPounds(400_000),
        );

        $this->assertSame(0, $result->tax->pence);
        $this->assertSame(0, $result->nilRateBandUsed->pence, 'nothing chargeable, so the whole band transfers');
    }

    public function test_the_first_death_also_shows_the_beneficiarys_income_tax(): void
    {
        // The same £400,000 pot nominated to a child, on a death at 80: £30,000 of Inheritance Tax
        // falls wholly on it (it is the only chargeable asset), so the beneficiary draws £370,000
        // and pays 40% of it = £148,000.
        $result = $this->calculator()->computeFirstDeath(
            estateExcludingPensions: Money::fromPounds(500_000),
            unusedPensionValue: Money::fromPounds(400_000),
            includePensionsInEstate: true,
            spouseSurvives: true,
            deceasedLeftAWill: true,
            issueTakeUnderIntestacy: true,
            survivorIsUkLongTermResident: true,
            pensionNominatedToSpouse: Money::zero(),
            deceasedDiedAtOrAfter75: true,
        );

        $this->assertSame(14_800_000, $result->beneficiaryIncomeTax->pence);
    }
}
