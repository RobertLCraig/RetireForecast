<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Benefits\SupportForMortgageInterest;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Support for Mortgage Interest (SMI): the cheapest secured borrowing a pensioner can get, and
 * the first instrument a benefits caseworker reaches for when the problem is an unaffordable
 * secured debt in later life. A Pension Credit Guarantee Credit claimant gets it with no waiting
 * period, as a LOAN covering interest on eligible capital up to a cap at the DWP standard rate,
 * secured by a charge on the home and repaid on sale or death. For a pension-age claimant the
 * eligible housing costs also include service charges and ground rent, which is what matters on
 * a leasehold flat. Board card 0045.
 *
 * The household here is deliberately a single person with a 100% survivor factor, so the
 * survivor multiplier is 1.0 and every figure below is exact to the penny; the economy is flat
 * (zero inflation, zero house growth), so nominal == real and the balances are predictable.
 */
final class SupportForMortgageInterestTest extends TestCase
{
    /** A £300k home carrying a £150k interest-only mortgage: £9,000 a year of interest as a spend line. */
    private const HOME_VALUE = 300_000;

    private const MORTGAGE = 150_000;

    private const MORTGAGE_INTEREST = 9_000;

    /** Service charge + ground rent on the leasehold flat — the pension-age housing costs SMI also covers. */
    private const SERVICE_CHARGE = 2_400;

    /**
     * Essential spend INCLUDING the mortgage interest and the service charge (both are marked
     * subsets of it, not additions). Deliberately well above anything this household can earn, so
     * its small cash balance depletes at once and stays depleted: the State Pension and the
     * Guarantee Credit both ride the triple lock, so a smaller target would see the household
     * start banking a surplus late in life, and the estate test below would then no longer be
     * reading the home alone.
     */
    private const ESSENTIAL_SPEND = 30_000;

    private function flatEconomy()
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    /**
     * A single pensioner well past State Pension age with almost no capital, so the Guarantee
     * Credit test turns purely on the weekly State Pension passed in: £150 a week is below the
     * standard minimum guarantee (award positive), £400 a week is above it (no award).
     */
    private function claimant(
        int $weeklyStatePension,
        int $mortgage = self::MORTGAGE,
        int $serviceCharge = self::SERVICE_CHARGE,
        int $mortgageInterest = self::MORTGAGE_INTEREST,
        ?int $redemptionYear = null,
    ): Household {
        return new Household(
            'SMI claimant',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90)),
            ],
            new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(self::ESSENTIAL_SPEND),
                discretionaryAnnualSpend: Money::zero(),
                survivorSpendFactor: Percent::fromPercent(100),
                propertyCosts: Money::fromPounds($serviceCharge),
                mortgageCosts: Money::fromPounds($mortgageInterest),
                propertyCostsRealGrowth: Percent::zero(),
            ),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds($weeklyStatePension)),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(2_000)),
            ],
            primaryResidence: new Property(
                Money::fromPounds(self::HOME_VALUE),
                OwnershipType::Outright,
                outstandingMortgage: Money::fromPounds($mortgage),
                mortgageRedemptionYear: $redemptionYear,
                mortgageMaturityAction: $redemptionYear === null
                    ? MortgageMaturityAction::Refinance
                    : MortgageMaturityAction::ForcedSale,
            ),
        );
    }

    private function forecast(Household $h, bool $modelIht = false): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatEconomy(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                modelIht: $modelIht,
                homeToDescendants: true,
            ));
    }

    /** The interest met on eligible capital at the DWP standard rate, in pence. */
    private function interestMet(int $capital): int
    {
        return Money::fromPounds($capital)->applyRate(SupportForMortgageInterest::standardRate())->pence;
    }

    public function test_guarantee_credit_meets_the_mortgage_interest_at_the_dwp_standard_rate(): void
    {
        $onCredit = $this->forecast($this->claimant(weeklyStatePension: 150));
        $notOnCredit = $this->forecast($this->claimant(weeklyStatePension: 400));

        // The gate: the low-income household is actually on Guarantee Credit and the other is not,
        // or this test would be measuring something else entirely.
        $this->assertTrue($onCredit->years[0]->incomeBySource['means_tested_benefit']->isPositive());
        $this->assertFalse($notOnCredit->years[0]->incomeBySource['means_tested_benefit']->isPositive());

        // Without the credit the household bears the whole £20,000: interest, service charge and all.
        $this->assertSame(self::ESSENTIAL_SPEND * 100, $notOnCredit->years[0]->spendTarget->pence);

        // With it, DWP meets the interest on the eligible capital — the £150,000 balance capped at
        // the £100,000 pension-age limit — at the standard rate, plus the whole service charge.
        $met = $this->interestMet(SupportForMortgageInterest::ELIGIBLE_CAPITAL_LIMIT_PENCE / 100)
            + self::SERVICE_CHARGE * 100;
        $this->assertSame(self::ESSENTIAL_SPEND * 100 - $met, $onCredit->years[0]->spendTarget->pence);
        $this->assertSame($met, $onCredit->years[0]->smiBalance()->pence);
    }

    public function test_the_interest_met_is_capped_at_the_eligible_capital_limit(): void
    {
        $capPounds = (int) (SupportForMortgageInterest::ELIGIBLE_CAPITAL_LIMIT_PENCE / 100);

        // A balance at the cap and one far above it are met identically: the excess capital is
        // simply not eligible, which is the whole point of the cap.
        $atCap = $this->forecast($this->claimant(weeklyStatePension: 150, mortgage: $capPounds));
        $overCap = $this->forecast($this->claimant(weeklyStatePension: 150, mortgage: $capPounds * 3));
        $this->assertSame($atCap->years[0]->smiBalance()->pence, $overCap->years[0]->smiBalance()->pence);

        // A balance below the cap is met on what is actually owed, which is strictly less.
        $underCap = $this->forecast($this->claimant(weeklyStatePension: 150, mortgage: 40_000));
        $this->assertSame(
            $this->interestMet(40_000) + self::SERVICE_CHARGE * 100,
            $underCap->years[0]->smiBalance()->pence,
        );
        $this->assertLessThan($atCap->years[0]->smiBalance()->pence, $underCap->years[0]->smiBalance()->pence);
    }

    public function test_nothing_is_met_when_no_interest_is_actually_charged(): void
    {
        // SMI meets an interest LIABILITY. A household charged no mortgage interest (the payment
        // line is zero — a rolled-up or already-serviced loan) is met nothing on the mortgage,
        // however large the balance: it cannot be handed money against a bill it does not pay.
        $result = $this->forecast($this->claimant(weeklyStatePension: 150, mortgageInterest: 0));

        $this->assertSame(self::SERVICE_CHARGE * 100, $result->years[0]->smiBalance()->pence);
    }

    public function test_service_charge_and_ground_rent_are_covered_for_a_pension_age_claimant(): void
    {
        // No mortgage at all: the only eligible housing cost is the leasehold service charge and
        // ground rent, which a pension-age claimant's SMI covers in full.
        $onCredit = $this->forecast($this->claimant(weeklyStatePension: 150, mortgage: 0, mortgageInterest: 0));
        $notOnCredit = $this->forecast($this->claimant(weeklyStatePension: 400, mortgage: 0, mortgageInterest: 0));

        $this->assertSame(
            $notOnCredit->years[0]->spendTarget->pence - self::SERVICE_CHARGE * 100,
            $onCredit->years[0]->spendTarget->pence,
        );
        $this->assertSame(self::SERVICE_CHARGE * 100, $onCredit->years[0]->smiBalance()->pence);
    }

    public function test_what_is_met_accrues_as_a_separate_charge_that_rolls_up(): void
    {
        $result = $this->forecast($this->claimant(weeklyStatePension: 150));

        $rate = SupportForMortgageInterest::standardRate()->asFraction();
        $metEachYear = $this->interestMet(SupportForMortgageInterest::ELIGIBLE_CAPITAL_LIMIT_PENCE / 100)
            + self::SERVICE_CHARGE * 100;

        $balances = array_map(static fn ($y) => $y->smiBalance()->pence, $result->years);
        $this->assertGreaterThan(5, count($balances));

        // Each year the standing charge rolls up at the DWP rate and this year's help is added on
        // top — a second, separate balance beside the mortgage, which never moves.
        for ($i = 0; $i < 5; $i++) {
            $expected = (int) round($balances[$i] * (1.0 + $rate)) + $metEachYear;
            $this->assertSame($expected, $balances[$i + 1], "year {$i}→".($i + 1).' SMI roll-up');
            $this->assertSame(self::MORTGAGE * 100, $result->years[$i]->mortgageBalance()->pence, 'the mortgage is untouched');
        }
    }

    public function test_the_charge_comes_off_the_home_equity_and_the_estate_at_death(): void
    {
        $onCredit = $this->forecast($this->claimant(weeklyStatePension: 150), modelIht: true);

        // It is a charge against the PROPERTY: home equity is the home less the mortgage less the
        // charge, so the reported wealth cannot flatter a household whose home is being spent.
        $last = $onCredit->years[count($onCredit->years) - 1];
        $this->assertTrue($last->smiBalance()->isPositive());
        $this->assertSame(
            $last->propertyWealth->minus($last->mortgageBalance())->minus($last->smiBalance())->minZero()->pence,
            $last->homeEquity()->pence,
        );

        // And it is repaid on death. This household outspends its income every year, so by the end
        // it holds nothing but the home and the estate IS the home equity — which makes the
        // deduction readable to the penny rather than tangled up with leftover cash. Zero
        // inflation, so the estate's nominal pounds are today's pounds.
        $this->assertSame(0, $last->liquidWealth->pence, 'nothing but the home is left');
        $this->assertSame(0, $last->pensionWealth->pence);
        $this->assertNotNull($onCredit->iht);

        $wholeEquity = (self::HOME_VALUE - self::MORTGAGE) * 100;
        $estate = $onCredit->iht->secondDeath->totalEstate->pence;
        $this->assertLessThan($wholeEquity, $estate, 'the charge is redeemed out of the estate');
        $this->assertLessThanOrEqual(
            $wholeEquity - $last->smiBalance()->pence,
            $estate,
            'at least the charge standing in the final year comes off',
        );
        $this->assertGreaterThan(0, $estate, 'the home is worth far more than the charge, so equity remains');
    }

    public function test_the_charge_is_redeemed_from_the_proceeds_of_a_sale(): void
    {
        // The mortgage is called in 2032 and cannot be refinanced, so the home is sold that year.
        // The SMI charge is secured on that home, so it is redeemed out of the proceeds and the
        // balance goes to zero — it does not follow the household into a rented flat.
        $result = $this->forecast($this->claimant(weeklyStatePension: 150, redemptionYear: 2032));

        $before = null;
        $after = null;
        foreach ($result->years as $year) {
            if ($year->calendarYear === 2031) {
                $before = $year;
            }
            if ($year->calendarYear === 2032) {
                $after = $year;
            }
        }
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertTrue($before->smiBalance()->isPositive(), 'the charge had built up before the sale');
        $this->assertSame(0, $after->smiBalance()->pence, 'the sale redeems the charge');
        $this->assertSame(0, $after->propertyWealth->pence);
    }
}
