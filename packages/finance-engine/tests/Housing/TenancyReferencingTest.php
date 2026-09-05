<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\Tenancy;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0031. A rent plan used to be judged only on whether the money lasts, never on
 * whether a landlord would grant the tenancy. Standard referencing is an INCOME test — gross
 * annual income of at least 30 times the monthly rent — and it does not look at capital, so a
 * retired household with a large pot and a small pension fails it however well the projection
 * reads. Starting the tenancy also costs money on day one that nothing charged.
 */
final class TenancyReferencingTest extends TestCase
{
    /** Zero growth + zero inflation, so nominal == real and every figure is the entered value. */
    private function flat(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * A retired single owner with a £400,000 home and a taxable income of $incomePounds. The
     * survivor factor is 100% because a one-person household is charged survivor-level spend
     * from year 0, and the income is TAXABLE on purpose: a tax-free stream is disregarded by
     * the Pension Credit means test, which would quietly top the household's income back up.
     */
    private function household(int $incomePounds): Household
    {
        return new Household(
            'Renters',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(12_000), Money::fromPounds(2_000), Percent::fromPercent(100)),
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds($incomePounds), true, false, 60)],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
            ),
        );
    }

    /** @return array{0: Household, 1: ForecastSettings} the sell-and-rent variant as the engine builds it */
    private function rentPlan(int $incomePounds, int $annualRentPounds): array
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $variants = (new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->variantInputs(
                $this->household($incomePounds),
                $settings,
                $this->flat(),
                new HousingAction(salePrice: Money::fromPounds(400_000), annualRent: Money::fromPounds($annualRentPounds)),
            );

        return [$variants['rent']['household'], $variants['rent']['settings']];
    }

    private function forecast(Household $household, ForecastSettings $settings): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), $settings);
    }

    /** @return list<Warning> */
    private function warningsOfCode(ForecastResult $forecast, string $code): array
    {
        $found = [];
        foreach ($forecast->years as $year) {
            foreach ($year->warnings as $warning) {
                if ($warning->code === $code) {
                    $found[] = $warning;
                }
            }
        }

        return $found;
    }

    /**
     * AC #1. £14,000 income against £18,000 rent: a standard reference asks for 30 times the
     * £1,500 monthly rent, £45,000, so every year of this tenancy fails it — and the household
     * is sitting on £392,000 of proceeds, which the test does not look at.
     */
    public function test_a_rent_plan_whose_income_fails_referencing_is_flagged_in_every_such_year(): void
    {
        [$household, $settings] = $this->rentPlan(incomePounds: 14_000, annualRentPounds: 18_000);
        $forecast = $this->forecast($household, $settings);

        $flagged = $this->warningsOfCode($forecast, WarningCode::RENT_REFERENCING_FAILED);
        $this->assertCount(count($forecast->years), $flagged, 'every year of the tenancy fails the reference');

        // The flag states the bar, and states it as the engine's own constant.
        $required = Tenancy::referencingIncomeRequired(Money::fromPounds(18_000));
        $this->assertSame(Money::fromPounds(45_000)->pence, $required->pence);
        $this->assertStringContainsString($required->format(), $flagged[0]->message);
        $this->assertStringContainsString((string) Tenancy::REFERENCING_INCOME_MULTIPLE, $flagged[0]->message);
    }

    /**
     * AC #1, the other side. Capital is irrelevant to referencing but income is not: the same
     * plan with an income above the bar is granted, so the flag must be silent. Without this,
     * a flag that always fires says nothing.
     */
    public function test_a_rent_plan_whose_income_clears_referencing_is_not_flagged(): void
    {
        [$household, $settings] = $this->rentPlan(incomePounds: 46_000, annualRentPounds: 18_000);

        $this->assertSame([], $this->warningsOfCode($this->forecast($household, $settings), WarningCode::RENT_REFERENCING_FAILED));
    }

    /** A plan that pays no rent can never fail a reference, so it must never carry the flag. */
    public function test_a_plan_that_pays_no_rent_is_never_flagged(): void
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $forecast = $this->forecast($this->household(14_000), $settings);

        $this->assertSame([], $this->warningsOfCode($forecast, WarningCode::RENT_REFERENCING_FAILED));
    }

    /**
     * AC #2. A flag that only says "no" leaves the reader nowhere. The two normal ways round a
     * failed reference are a homeowner guarantor at the higher multiple and rent paid in
     * advance, and the second locks up capital the plan is relying on being invested — so the
     * pounds it would tie up are named, not left as "six to twelve months".
     */
    public function test_the_flag_states_both_alternatives_and_the_capital_rent_in_advance_ties_up(): void
    {
        [$household, $settings] = $this->rentPlan(incomePounds: 14_000, annualRentPounds: 18_000);
        $message = $this->warningsOfCode($this->forecast($household, $settings), WarningCode::RENT_REFERENCING_FAILED)[0]->message;

        $rent = Money::fromPounds(18_000);
        $this->assertStringContainsString('guarantor', $message);
        $this->assertStringContainsString(Tenancy::guarantorIncomeRequired($rent)->format(), $message, 'the guarantor bar in pounds');
        $this->assertStringContainsString('advance', $message);
        $this->assertStringContainsString(Tenancy::rentInAdvance($rent, Tenancy::ADVANCE_MONTHS_MIN)->format(), $message);
        $this->assertStringContainsString(Tenancy::rentInAdvance($rent, Tenancy::ADVANCE_MONTHS_MAX)->format(), $message);
    }

    /**
     * AC #3. Starting a tenancy costs money before the keys change hands. The deposit is capped
     * by the Tenant Fees Act at five weeks' rent (six above £50,000 a year) and is charged as a
     * year-0 one-off, keyed to the first person's base-year age like every other one-off.
     */
    public function test_a_rent_plan_charges_the_tenancy_deposit_as_a_year_zero_one_off(): void
    {
        [$household] = $this->rentPlan(incomePounds: 14_000, annualRentPounds: 18_000);

        $oneOffs = $household->expenseProfile->oneOffCosts;
        $this->assertCount(1, $oneOffs);
        $this->assertSame(Tenancy::UP_FRONT_LABEL, $oneOffs[0]['label']);
        $this->assertSame(68, $oneOffs[0]['atAge'], "keyed to the first person's base-year age (born 1958, base 2026)");
        $this->assertSame(
            Tenancy::deposit(Money::fromPounds(18_000))->pence,
            $oneOffs[0]['amount']->pence,
            'five weeks of £18,000 a year',
        );
    }

    /**
     * AC #3, the disclosure. The deposit is a figure the engine supplied for itself, so the
     * reader must be told it — with the full day-one cash it is part of, and with why the first
     * month's rent is not charged again on top (a year of a monthly tenancy is twelve payments,
     * and the year's rent already charges twelve).
     */
    public function test_the_up_front_tenancy_cost_is_stated_once_with_the_day_one_cash(): void
    {
        [$household, $settings] = $this->rentPlan(incomePounds: 14_000, annualRentPounds: 18_000);
        $forecast = $this->forecast($household, $settings);

        $stated = $this->warningsOfCode($forecast, WarningCode::TENANCY_UP_FRONT_COST);
        $this->assertCount(1, $stated, 'the tenancy starts once, so it is stated once');

        $rent = Money::fromPounds(18_000);
        $this->assertStringContainsString(Tenancy::deposit($rent)->format(), $stated[0]->message);
        $this->assertStringContainsString(Tenancy::monthlyRent($rent)->format(), $stated[0]->message);
        $this->assertStringContainsString(Tenancy::upFrontCash($rent)->format(), $stated[0]->message);
        $this->assertStringContainsString((string) Tenancy::DEPOSIT_WEEKS, $stated[0]->message);
    }

    /**
     * The deposit is charged ON TOP of the year's rent, and only the deposit is: a household
     * paying monthly in advance makes twelve payments in its first year, which the rent line
     * already charges, so a thirteenth would be money nobody pays.
     */
    public function test_year_zero_is_charged_the_deposit_on_top_of_twelve_months_rent_and_no_more(): void
    {
        [$household, $settings] = $this->rentPlan(incomePounds: 14_000, annualRentPounds: 18_000);
        $forecast = $this->forecast($household, $settings);

        // Ordinary spend + a full year's rent, and the deposit on top in year 0 alone.
        $ordinary = Money::fromPounds(14_000)->plus(Money::fromPounds(18_000));
        $this->assertSame($ordinary->pence, $forecast->years[1]->spendTarget->pence, 'a later year is rent and spend only');
        $this->assertSame(
            $ordinary->plus(Tenancy::deposit(Money::fromPounds(18_000)))->pence,
            $forecast->years[0]->spendTarget->pence,
        );
    }

    /** A plan that pays no rent starts no tenancy, so it is charged and told nothing. */
    public function test_a_plan_that_pays_no_rent_is_charged_no_deposit(): void
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $household = $this->household(14_000);

        $this->assertSame([], $household->expenseProfile->oneOffCosts);
        $this->assertSame([], $this->warningsOfCode($this->forecast($household, $settings), WarningCode::TENANCY_UP_FRONT_COST));
    }

    /** The Tenant Fees Act cap steps to six weeks once the annual rent reaches £50,000. */
    public function test_the_deposit_cap_steps_to_six_weeks_above_the_statutory_rent_threshold(): void
    {
        $under = Money::fromPence(Tenancy::HIGH_RENT_THRESHOLD_PENCE - 100);
        $over = Money::fromPence(Tenancy::HIGH_RENT_THRESHOLD_PENCE);

        $this->assertSame($under->times(Tenancy::DEPOSIT_WEEKS)->dividedBy(52)->pence, Tenancy::deposit($under)->pence);
        $this->assertSame($over->times(Tenancy::DEPOSIT_WEEKS_HIGH_RENT)->dividedBy(52)->pence, Tenancy::deposit($over)->pence);
    }
}
