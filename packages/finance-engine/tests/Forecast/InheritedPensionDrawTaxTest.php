<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0079. How a beneficiary is taxed on what they draw from an inherited pension turns
 * on ONE fact: how old the member was when they died. Under 75 the draw is tax-free income; at 75
 * or over it is taxed as the beneficiary's own income. The projector folded the deceased's pots
 * into one inherited pot without recording the age at death, so it charged full income tax either
 * way — tens of thousands of pounds of tax that does not exist on a plan whose first death is early.
 *
 * The two ad-hoc draw closures in fundShortfall are exercised apart (TaxEfficient runs the plain
 * one, FillBands the UFPLS one), because a rule written into only one of them makes the tax depend
 * on the draw order.
 */
final class InheritedPensionDrawTaxTest extends TestCase
{
    /**
     * A couple where P1 holds the whole pension and dies in 2027 at $deceasedAgeAtDeath, and P2
     * survives to 95 on a State Pension too small to meet the spend — so every year after the death
     * has to be funded out of the pot P2 inherited, which is the draw whose tax is in question.
     *
     * P1's date of birth is derived from the age they die at, so the DEATH YEAR is 2027 whichever
     * age is asked for: the two runs then differ in nothing but the fact this card is about. P1 has
     * no income of their own, so their date of birth reaches nothing else.
     */
    private function forecast(int $deceasedAgeAtDeath, DrawdownStrategy $strategy, int $heirBirthYear = 1955): array
    {
        $deceasedBirthYear = 2026 - $deceasedAgeAtDeath;

        $household = new Household(
            'Inherited draw',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable("{$deceasedBirthYear}-01-01"), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge($deceasedAgeAtDeath)),
                new Person('p2', new DateTimeImmutable("{$heirBirthYear}-01-01"), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95)),
            ],
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
                new DcPension('p1', Money::fromPounds(500_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
            ],
        );

        $years = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                drawdownStrategy: $strategy,
            ))->years;

        $byYear = [];
        foreach ($years as $year) {
            $byYear[$year->calendarYear] = $year;
        }

        return $byYear;
    }

    /** The first full year the survivor is funding the household out of the inherited pot. */
    private function drawYear(int $deceasedAgeAtDeath, DrawdownStrategy $strategy, int $heirBirthYear = 1955): YearResult
    {
        $years = $this->forecast($deceasedAgeAtDeath, $strategy, $heirBirthYear);
        $this->assertArrayHasKey(2028, $years);
        $this->assertSame(1, $years[2028]->aliveCount, 'the fixture needs P1 gone and P2 drawing by 2028');

        return $years[2028];
    }

    /** @return list<array{0: DrawdownStrategy}> */
    public static function strategies(): array
    {
        // The two ad-hoc draw closures: TaxEfficient runs $drawPension, FillBands $drawPensionUfpls.
        return [[DrawdownStrategy::TaxEfficient], [DrawdownStrategy::FillBands]];
    }

    /**
     * Criterion #1. The age the treatment turns on is the DECEASED's, recorded on the pot at the
     * moment it is inherited — not the heir's own age, which is the only age still available by the
     * time the pot is drawn. Both halves are run with the two ages on opposite sides of 75, so a
     * model reading the wrong one gets both answers backwards.
     */
    public function test_an_inherited_pot_carries_the_age_at_which_its_owner_died(): void
    {
        // Died at 72; the heir is 81 in the draw year. Tax-free, because the OWNER died under 75.
        $ownerYoungHeirOld = $this->drawYear(72, DrawdownStrategy::TaxEfficient, heirBirthYear: 1947);
        $this->assertSame(0, $ownerYoungHeirOld->incomeBySource['pension_drawdown']->pence ?? 0);

        // Died at 76; the heir is 66 in the draw year. Taxed, because the OWNER died at 75 or over.
        $ownerOldHeirYoung = $this->drawYear(76, DrawdownStrategy::TaxEfficient, heirBirthYear: 1962);
        $this->assertTrue(($ownerOldHeirYoung->incomeBySource['pension_drawdown'] ?? Money::zero())->isPositive());
    }

    /**
     * Criterion #2. Death under 75: the whole draw is tax-free income. Nothing lands on the taxable
     * pension line, and the year's tax bill is strictly smaller than the same household's whose
     * member died four years later.
     */
    #[DataProvider('strategies')]
    public function test_a_draw_from_a_pot_inherited_from_someone_who_died_under_75_is_tax_free(DrawdownStrategy $strategy): void
    {
        $under = $this->drawYear(72, $strategy);
        $over = $this->drawYear(76, $strategy);

        $drawn = $under->incomeBySource['pension_lump_sum'] ?? Money::zero();
        $this->assertTrue($drawn->isPositive(), 'the survivor has to draw the inherited pot to live, so there is a draw to tax');
        $this->assertSame(
            0,
            ($under->incomeBySource['pension_drawdown'] ?? Money::zero())->pence,
            'a draw from a pot inherited from someone who died under 75 is tax-free income: none of it is taxable',
        );
        $this->assertLessThan(
            $over->totalTax->pence,
            $under->totalTax->pence,
            'the tax-free treatment must actually cost the year less tax than the taxed one',
        );
    }

    /**
     * Criterion #3. Death at 75 or over: unchanged. The draw is the heir's taxable income, and it
     * still carries no tax-free quarter (board card 0007).
     */
    #[DataProvider('strategies')]
    public function test_a_draw_from_a_pot_inherited_from_someone_who_died_at_75_or_over_is_taxed(DrawdownStrategy $strategy): void
    {
        $over = $this->drawYear(76, $strategy);

        $this->assertTrue(
            ($over->incomeBySource['pension_drawdown'] ?? Money::zero())->isPositive(),
            'a pot inherited from someone who died at 75 or over is taxed as the heir\'s income',
        );
        $this->assertSame(
            0,
            ($over->incomeBySource['pension_lump_sum'] ?? Money::zero())->pence,
            'and it carries no tax-free quarter',
        );
        $this->assertTrue($over->totalTax->isPositive());
    }

    /**
     * Criterion #4, the engine half: the projector states which treatment it applied and why, on
     * the year the pot is inherited, so the presenter quotes the engine's own sentence rather than
     * restating a rule that could then drift from the one the projection ran.
     */
    public function test_the_inherited_pension_tax_treatment_is_disclosed(): void
    {
        foreach ([72 => 'tax-free', 76 => 'taxed'] as $age => $_) {
            $settled = $this->forecast($age, DrawdownStrategy::TaxEfficient)[2027];
            $message = null;
            foreach ($settled->warnings as $warning) {
                if ($warning->code === WarningCode::INHERITED_PENSION_TAX_TREATMENT) {
                    $message = $warning->message;
                }
            }

            $this->assertNotNull($message, "the year a pot is inherited from a death at {$age} must say how it will be taxed");
            $this->assertStringContainsString((string) $age, $message, 'the age at death is the fact the treatment turns on, so it is named');
        }
    }
}
