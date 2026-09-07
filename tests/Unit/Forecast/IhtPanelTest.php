<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Iht\IhtOutcome;
use RetireForecast\FinanceEngine\Iht\IhtResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

class IhtPanelTest extends TestCase
{
    private function household(RelationshipStatus $status, int $people = 2): Household
    {
        $persons = [new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired)];
        if ($people === 2) {
            $persons[] = new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired);
        }

        return new Household('H', RegionProfile::EnglandWalesNi, $persons, new ExpenseProfile(Money::zero(), Money::zero(), Percent::fromPercent(70)), relationshipStatus: $status);
    }

    private function ihtResult(int $estate, int $nrb, int $rnrb, int $taxable, int $tax, bool $pensions, array $warnings = [], int $pot = 0, int $beneficiaryTax = 0): IhtResult
    {
        return new IhtResult(
            Money::fromPounds($estate),
            Money::fromPounds($nrb),
            Money::fromPounds($rnrb),
            Money::fromPounds($taxable),
            Percent::fromPercent(40),
            Money::fromPounds($tax),
            $pensions,
            $warnings,
            Money::zero(),
            Money::fromPounds($pot),
            Money::fromPounds($beneficiaryTax),
            $beneficiaryTax > 0 ? Percent::fromPercent(40) : null,
        );
    }

    public function test_null_when_iht_is_not_modelled(): void
    {
        $this->assertNull(ResultPresenter::ihtPanel(null, $this->household(RelationshipStatus::MarriedOrCivilPartnership)));
    }

    public function test_a_married_couple_panel_flags_the_spouse_exempt_first_death(): void
    {
        $first = $this->ihtResult(400_000, 0, 0, 0, 0, true, [new Warning(WarningCode::IHT_SPOUSE_EXEMPTION, 'exempt')]);
        $second = $this->ihtResult(1_400_000, 650_000, 350_000, 400_000, 160_000, true);
        $outcome = new IhtOutcome($first, $second, Money::fromPounds(160_000), Money::zero());

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::MarriedOrCivilPartnership));

        $this->assertSame('married', $panel['relationship']);
        $this->assertTrue($panel['couple']);
        $this->assertSame(160_000, $panel['total']);
        $this->assertTrue($panel['anyTaxDue']);
        $this->assertTrue($panel['pensionsIncluded']);
        $this->assertSame(1_400_000, $panel['secondDeath']['estate']);
        $this->assertSame(350_000, $panel['secondDeath']['rnrb']);
        $this->assertSame(160_000, $panel['secondDeath']['tax']);
        // The first death is present, £0, and flagged as spouse-exempt (not merely under the threshold).
        $this->assertNotNull($panel['firstDeath']);
        $this->assertSame(0, $panel['firstDeath']['tax']);
        $this->assertTrue($panel['firstDeath']['spouseExempt']);
    }

    public function test_a_cohabiting_first_death_is_chargeable_not_exempt(): void
    {
        $first = $this->ihtResult(500_000, 325_000, 0, 175_000, 70_000, true);
        $second = $this->ihtResult(700_000, 325_000, 175_000, 200_000, 80_000, true);
        $outcome = new IhtOutcome($first, $second, Money::fromPounds(150_000), Money::zero());

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::Cohabiting));

        $this->assertSame('cohabiting', $panel['relationship']);
        $this->assertSame(70_000, $panel['firstDeath']['tax']);
        $this->assertFalse($panel['firstDeath']['spouseExempt']);
        $this->assertSame(150_000, $panel['total']);
    }

    public function test_a_single_person_has_no_first_death_and_reads_as_single(): void
    {
        $second = $this->ihtResult(600_000, 325_000, 0, 275_000, 110_000, true);
        $outcome = new IhtOutcome(null, $second, Money::fromPounds(110_000), Money::zero());

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::MarriedOrCivilPartnership, people: 1));

        $this->assertSame('single', $panel['relationship']);
        $this->assertFalse($panel['couple']);
        $this->assertNull($panel['firstDeath']);
    }

    public function test_an_estate_within_the_allowances_reads_as_no_tax_due(): void
    {
        $second = $this->ihtResult(400_000, 650_000, 350_000, 0, 0, true);
        $outcome = new IhtOutcome(null, $second, Money::zero(), Money::zero());

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::MarriedOrCivilPartnership));

        $this->assertFalse($panel['anyTaxDue']);
        $this->assertSame(0, $panel['total']);
    }

    /**
     * Board card 0057. An unused pot left on a death at or after 75 is taxed twice: Inheritance Tax
     * on the estate, then the beneficiary's own income tax on what they draw. The panel showed only
     * the first, which makes preserving a pot look roughly twice as attractive as it is.
     */
    public function test_the_beneficiarys_income_tax_is_shown_alongside_the_inheritance_tax(): void
    {
        $second = $this->ihtResult(1_000_000, 325_000, 0, 675_000, 270_000, true, pot: 400_000, beneficiaryTax: 116_800);
        $outcome = new IhtOutcome(null, $second, Money::fromPounds(270_000), Money::fromPounds(116_800));

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::MarriedOrCivilPartnership, people: 1));

        $this->assertSame(116_800, $panel['beneficiaryIncomeTax']);
        $this->assertSame(400_000, $panel['inheritedPension']);
        $this->assertSame('40%', $panel['beneficiaryRatePct']);
        // The two charges stay apart: the Inheritance Tax headline is not quietly inflated.
        $this->assertSame(270_000, $panel['total']);
        // And the combined bite on the pot is stated, because that is the figure the reader is
        // weighing against spending it.
        $this->assertSame(386_800, $panel['pensionTaxedTwiceTotal']);
    }

    public function test_no_beneficiary_charge_is_shown_when_there_is_none(): void
    {
        // No noise: a death before 75, or a plan with no pot left, must not carry the panel.
        $second = $this->ihtResult(600_000, 325_000, 0, 275_000, 110_000, true);
        $outcome = new IhtOutcome(null, $second, Money::fromPounds(110_000), Money::zero());

        $panel = ResultPresenter::ihtPanel($outcome, $this->household(RelationshipStatus::MarriedOrCivilPartnership, people: 1));

        $this->assertNull($panel['beneficiaryIncomeTax']);
    }
}
