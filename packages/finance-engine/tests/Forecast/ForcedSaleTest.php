<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A forced sale (MortgageMaturityAction::ForcedSale) sells the home in place, in the redemption
 * year, mid-projection: the equity is freed into investable wealth, the mortgage and property
 * costs stop, and the household rents from then on. Before this the projector kept the home for
 * ever (an impossible "keep paying the ended mortgage" path). These tests pin: wealth is conserved
 * across the sale (liquid rises by exactly the reconciled net proceeds), property costs stop and
 * rent begins, the freed capital erodes Pension Credit, and a let former home bears CGT on the
 * grown gain while a home lived in throughout does not.
 */
final class ForcedSaleTest extends TestCase
{
    /** Zero growth + zero inflation, so nominal == real and the sale price is the entered value. */
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

    private function config(): TaxYearConfig
    {
        return TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
    }

    private function forecast(Household $household, ForecastSettings $settings): ForecastResult
    {
        return (new DeterministicForecaster($this->config(), new CohortLifeTable))
            ->forecast($household, $this->flat(), $settings);
    }

    /** @return array<int, YearResult> calendarYear => year */
    private function byYear(ForecastResult $forecast): array
    {
        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year;
        }

        return $out;
    }

    public function test_a_forced_sale_frees_the_equity_and_conserves_wealth(): void
    {
        // Funded entirely by a large cash buffer (no pension income, so the ordinary year-over-year
        // flow is a constant −spend with no State Pension triple-lock creep). Spend is unchanged
        // across the sale by construction: the £12k of costs that stop (£6k mortgage payment + £3k
        // service charge + £3k running costs) is exactly the £12k rent that starts. So the only
        // year that is not an ordinary −spend step is the sale year, which also gains the equity.
        $household = new Household(
            'ForcedSale', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1959-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            // £18k essential includes the £6k mortgage payment + £3k service charge markers.
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70),
                propertyCosts: Money::fromPounds(3_000),
                mortgageCosts: Money::fromPounds(6_000),
                // Explicitly flat, so the "spend is unchanged across the sale" construction above
                // holds. A BLANK rate now takes the engine's above-CPI default (card 0028), which
                // would grow the service charge year on year; that is PropertyCostsGrowthTest's
                // subject, not this file's.
                propertyCostsRealGrowth: Percent::zero(),
            ),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                runningCosts: Money::fromPounds(3_000),
                outstandingMortgage: Money::fromPounds(100_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );
        $years = $this->byYear($this->forecast($household, new ForecastSettings(
            baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(12_000),
        )));

        // The home is owned before the redemption year and gone from it on — never kept for ever.
        $this->assertSame(Money::fromPounds(400_000)->pence, $years[2029]->propertyWealth->pence);
        $this->assertSame(0, $years[2030]->propertyWealth->pence, 'the home is sold in the redemption year');
        $this->assertSame(0, $years[2031]->propertyWealth->pence);

        // Net proceeds = £400k − £100k mortgage − £16k (4% selling costs) − £0 CGT = £284k, the single
        // reconciled definition. Wealth is conserved: the sale-year liquid step exceeds an ordinary
        // (post-sale, still renting) step by exactly the net proceeds — no pence created or lost.
        $expectedNet = HousingProceeds::compute(
            Money::fromPounds(400_000), Money::fromPounds(100_000), null, null, null, $this->config(),
        )->netProceeds->pence;
        $this->assertSame(Money::fromPounds(284_000)->pence, $expectedNet);

        $saleStep = $years[2030]->liquidWealth->pence - $years[2029]->liquidWealth->pence;
        $ordinaryStep = $years[2032]->liquidWealth->pence - $years[2031]->liquidWealth->pence;
        $this->assertSame($expectedNet, $saleStep - $ordinaryStep, 'liquid rises by exactly the net proceeds at the sale');
    }

    public function test_property_costs_stop_and_rent_begins_at_a_forced_sale(): void
    {
        // With the rent set equal to the costs that stop, spend is continuous across the sale (the
        // rent exactly replaces the mortgage payment + service charge + running costs).
        $withRent = $this->byYear($this->forecast(
            $this->costsHousehold(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(12_000)),
        ));
        $this->assertSame(
            $withRent[2029]->spendTarget->pence,
            $withRent[2031]->spendTarget->pence,
            'rent that equals the stopped costs leaves total spend unchanged across the sale',
        );

        // With no rent entered, the £12k of housing costs simply stop at the sale and nothing
        // replaces them, so spend falls by exactly that £12k (property + mortgage + running).
        $noRent = $this->byYear($this->forecast(
            $this->costsHousehold(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
        ));
        $this->assertSame(
            Money::fromPounds(12_000)->pence,
            $noRent[2029]->spendTarget->pence - $noRent[2031]->spendTarget->pence,
            'the home is gone, so its costs stop; no rent is charged when none is entered',
        );
    }

    private function costsHousehold(): Household
    {
        return new Household(
            'Costs', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1959-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70),
                propertyCosts: Money::fromPounds(3_000),
                mortgageCosts: Money::fromPounds(6_000),
                // Explicitly flat: this file tests that housing costs STOP at the sale and rent
                // begins, which needs the £12k that stops to still be £12k in the sale year. A
                // blank rate now escalates it above CPI (card 0028), which PropertyCostsGrowthTest
                // covers.
                propertyCostsRealGrowth: Percent::zero(),
            ),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                runningCosts: Money::fromPounds(3_000),
                outstandingMortgage: Money::fromPounds(100_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );
    }

    public function test_a_forced_sale_erodes_pension_credit(): void
    {
        // A low-income couple over State Pension age with little capital receives Pension Credit —
        // until a forced sale frees six figures of equity into assessable capital, which pushes them
        // over the £16k cliff, so the guarantee credit stops.
        $household = new Household(
            'PC', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1953-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1953-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(90, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(90, 0)),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(8_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(100_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );
        $years = $this->byYear($this->forecast($household, new ForecastSettings(
            baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(12_000),
        )));

        $this->assertGreaterThan(0, $years[2029]->incomeBySource['means_tested_benefit']->pence, 'Pension Credit tops up a low income before the sale');
        $this->assertSame(0, $years[2031]->incomeBySource['means_tested_benefit']->pence, 'the freed equity is assessable capital and ends the award');
    }

    public function test_a_let_former_home_bears_cgt_on_a_forced_sale_but_a_lived_in_home_does_not(): void
    {
        // Same forced sale, once for a home lived in throughout (full PRR, £0 CGT) and once for a
        // home let for most of ownership (partial PRR, a chargeable gain). CGT is netted off the
        // freed proceeds, so the let home frees less into liquid wealth after the sale.
        $letHistory = new CgtHistory(
            purchasePrice: Money::fromPounds(100_000),
            improvementCosts: Money::zero(),
            ownershipMonths: 240,
            mainResidenceMonths: 60,
            higherRateOnSale: true,
            owners: 2,
        );

        // The shared decomposition: a lived-in home is £0, a let one is charged on the grown gain.
        $livedInProceeds = HousingProceeds::compute(Money::fromPounds(400_000), Money::zero(), null, null, null, $this->config());
        $letProceeds = HousingProceeds::compute(Money::fromPounds(400_000), Money::zero(), null, $letHistory, null, $this->config());
        $this->assertSame(0, $livedInProceeds->capitalGainsTax->pence, 'a home lived in throughout has full PRR');
        $this->assertGreaterThan(0, $letProceeds->capitalGainsTax->pence, 'a let former home has a chargeable gain');

        // And it reaches the forecast: the let home leaves less liquid wealth after the sale.
        $livedIn = $this->byYear($this->forecast($this->cgtHousehold(null), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(12_000))));
        $let = $this->byYear($this->forecast($this->cgtHousehold($letHistory), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(12_000))));
        $this->assertLessThan(
            $livedIn[2031]->liquidWealth->pence,
            $let[2031]->liquidWealth->pence,
            'CGT on the let home reduces the freed proceeds',
        );
    }

    private function cgtHousehold(?CgtHistory $history): Household
    {
        return new Household(
            'Cgt', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1959-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                everLet: $history !== null,
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
                cgtHistory: $history,
            ),
        );
    }

    /**
     * A forced sale must redeem the balance as it stands in the sale year, not the balance that
     * was typed in. The two are the same only for an interest-only loan, which is why this never
     * showed: a lifetime mortgage has ROLLED UP by then (more is owed, so less cash is freed) and
     * a repayment mortgage has AMORTISED down (less is owed, so more is freed). Both shapes are
     * supported and both are live.
     *
     * The expected figure is not restated here. A control run that never sells reports the balance
     * the projector itself holds in the sale year, and the shared {@see HousingProceeds} turns that
     * into net proceeds — so the test reads the engine's own two definitions rather than replaying
     * the roll-up or amortisation arithmetic beside them.
     */
    public function test_a_rolled_up_mortgage_is_redeemed_at_its_grown_balance(): void
    {
        $this->assertSaleRedeemsTheYearsBalance(rollUpRate: Percent::fromPercent(6));
    }

    public function test_an_amortising_mortgage_is_redeemed_at_its_reduced_balance(): void
    {
        $this->assertSaleRedeemsTheYearsBalance(repaymentTerms: new RepaymentMortgageTerms(
            termMonths: 300,
            firstPaymentYear: 2026,
            firstPaymentMonth: 1,
            ratePeriods: [new MortgageRatePeriod(Percent::fromPercent(5))],
        ));
    }

    private function assertSaleRedeemsTheYearsBalance(
        ?Percent $rollUpRate = null,
        ?RepaymentMortgageTerms $repaymentTerms = null,
    ): void {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');

        $sold = $this->byYear($this->forecast(
            $this->movingBalanceHousehold(MortgageMaturityAction::ForcedSale, $rollUpRate, $repaymentTerms),
            $settings,
        ));
        // The same household that never sells: its sale-year row reports the balance in force that
        // year, which is what the sale has to clear.
        $kept = $this->byYear($this->forecast(
            $this->movingBalanceHousehold(MortgageMaturityAction::Refinance, $rollUpRate, $repaymentTerms),
            $settings,
        ));

        $owedAtSale = $kept[2030]->mortgageBalance()->pence;
        $this->assertNotSame(
            Money::fromPounds(100_000)->pence,
            $owedAtSale,
            'the fixture must MOVE the balance, or this test cannot tell the two readings apart',
        );

        // The cash the sale actually freed: the sale-year liquid step less an ordinary post-sale
        // step. Spend is identical in 2030, 2031 and 2032 (the home and its payment are gone in all
        // three) and there is no income, so the difference is the net proceeds and nothing else.
        $freed = ($sold[2030]->liquidWealth->pence - $sold[2029]->liquidWealth->pence)
            - ($sold[2032]->liquidWealth->pence - $sold[2031]->liquidWealth->pence);

        $expected = HousingProceeds::compute(
            Money::fromPounds(400_000), Money::fromPence($owedAtSale), null, null, null, $this->config(),
        )->netProceeds->pence;

        $this->assertSame($expected, $freed, 'the sale redeemed a balance that is not the one owed that year');
    }

    private function movingBalanceHousehold(
        MortgageMaturityAction $action,
        ?Percent $rollUpRate,
        ?RepaymentMortgageTerms $repaymentTerms,
    ): Household {
        // Deliberately bare: no property costs, no mortgage expense line, no rent and no income, so
        // every year after the sale spends the same £18k and the only thing that moves the liquid
        // step is the sale itself.
        return new Household(
            'MovingBalance', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(100_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: $action,
                mortgageRollUpRate: $rollUpRate,
                repaymentTerms: $repaymentTerms,
            ),
        );
    }

    public function test_a_forced_sale_with_no_redemption_year_keeps_the_home(): void
    {
        // The forced sale fires on the redemption year; with none set there is no trigger, so the
        // home is retained (the event is additive and never fires unbidden).
        $household = new Household(
            'NoYear', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(200_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );
        $years = $this->byYear($this->forecast($household, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27')));

        $this->assertSame(Money::fromPounds(400_000)->pence, $years[2030]->propertyWealth->pence, 'no redemption year → no forced sale');
    }
}
