<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CouncilTaxBand;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Council tax, held as its own cost line rather than bundled into the home's running costs
 * beside maintenance and insurance. Board card 0047.
 *
 * Bundled, it was charged at the full couple's rate for the whole projection: no single-person
 * discount when one partner died, no Council Tax Reduction for a household on or near the
 * Pension Credit line, and no way to record the disabled band reduction — which is not
 * means-tested and is claimable now, not at some future point in the plan.
 *
 * Every household here lives in a flat economy (zero inflation, zero growth, zero investment
 * yield) with a 100% survivor spend factor, so nominal == real and every figure below is exact
 * to the penny. The State Pension still rises on the triple-lock floor, so the assertions that
 * have to be exact read YEAR 0, before any uprating has been applied.
 */
final class CouncilTaxTest extends TestCase
{
    /** The home's maintenance and insurance — what is LEFT in runningCosts once council tax moves out. */
    private const UPKEEP = 1_200;

    /** The household's annual council tax bill, as it arrives on the doormat. */
    private const BILL = 2_000;

    private const ESSENTIAL_SPEND = 20_000;

    private function flatAssumptions(): AssumptionSet
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
     * A household in a home it owns outright, with its council tax split out of the upkeep.
     *
     * @param  list<int>  $weeklyStatePensions  one per member; £400 a week is far above the
     *                                          guarantee (no Pension Credit, no reduction),
     *                                          £150 a week is far below it (Guarantee Credit).
     * @param  int  $secondDeathAge  the age the SECOND member dies, leaving a single-person household
     */
    private function household(
        array $weeklyStatePensions,
        ?int $councilTax = self::BILL,
        ?CouncilTaxBand $disabledBandReduction = null,
        int $upkeep = self::UPKEEP,
        int $secondDeathAge = 95,
        int $isaBalance = 0,
    ): Household {
        $persons = [];
        $pensions = [];
        foreach ($weeklyStatePensions as $i => $weekly) {
            $id = 'p'.($i + 1);
            $persons[] = new Person(
                $id,
                new DateTimeImmutable('1950-01-01'),
                $i === 0 ? Sex::Female : Sex::Male,
                EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge($i === 0 ? 95 : $secondDeathAge),
            );
            $pensions[] = new StatePensionEntitlement($id, weeklyForecast: Money::fromPounds($weekly));
        }

        return new Household(
            'Council tax',
            RegionProfile::EnglandWalesNi,
            $persons,
            new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(self::ESSENTIAL_SPEND),
                discretionaryAnnualSpend: Money::zero(),
                survivorSpendFactor: Percent::fromPercent(100),
            ),
            pensions: $pensions,
            accounts: $isaBalance > 0
                ? [new Account('p1', AccountType::Isa, Money::fromPounds($isaBalance))]
                : [],
            primaryResidence: new Property(
                Money::fromPounds(300_000),
                OwnershipType::Outright,
                runningCosts: Money::fromPounds($upkeep),
                annualCouncilTax: $councilTax === null ? null : Money::fromPounds($councilTax),
                disabledBandReduction: $disabledBandReduction,
            ),
        );
    }

    private function forecast(Household $h): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatAssumptions(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** The first year in which only one member is still alive. */
    private function firstSurvivorYear(ForecastResult $result): YearResult
    {
        foreach ($result->years as $year) {
            if ($year->aliveCount === 1) {
                return $year;
            }
        }

        $this->fail('no year of this forecast has a single surviving member');
    }

    public function test_council_tax_is_its_own_cost_line_beside_maintenance_and_insurance(): void
    {
        // A couple well above the Pension Credit line, so nothing reduces the bill and the whole
        // of it is charged: what is being read here is only WHERE the money is held.
        $split = $this->forecast($this->household([400, 400]));

        // Same money, one line: upkeep and council tax bundled into runningCosts, the old shape.
        $bundled = $this->forecast($this->household([400, 400], councilTax: null, upkeep: self::UPKEEP + self::BILL));

        $expected = (self::ESSENTIAL_SPEND + self::UPKEEP + self::BILL) * 100;
        $this->assertSame($expected, $split->years[0]->spendTarget->pence, 'the split bill costs the same as the bundled one');
        $this->assertSame($expected, $bundled->years[0]->spendTarget->pence);
        $this->assertSame($expected, $split->years[0]->essentialSpend->pence, 'council tax is an essential cost');

        // And it is REPORTED on its own, so a reader can see the figure the discounts act on
        // rather than inferring it from a running-costs total.
        $this->assertSame(self::BILL * 100, $split->years[0]->councilTax()->pence);
        $this->assertSame(0, $bundled->years[0]->councilTax()->pence, 'nothing is split out when none was entered');
    }

    public function test_the_single_person_discount_applies_once_one_member_remains(): void
    {
        // The second member dies at 80 (2030); the first lives to 95. Nothing else about the
        // household changes on that death — the survivor spend factor is 100% and the upkeep is
        // not survivor-scaled — so the whole of the movement below is the council tax discount.
        $result = $this->forecast($this->household([400, 400], secondDeathAge: 80));

        $couple = $result->years[0];
        $survivor = $this->firstSurvivorYear($result);
        $this->assertSame(2, $couple->aliveCount);

        // 25% off, the automatic single-person discount.
        $discounted = (int) round(self::BILL * 100 * 0.75);
        $this->assertSame(self::BILL * 100, $couple->councilTax()->pence);
        $this->assertSame($discounted, $survivor->councilTax()->pence);

        $this->assertSame(
            (self::ESSENTIAL_SPEND + self::UPKEEP) * 100 + $discounted,
            $survivor->spendTarget->pence,
            'the survivor is charged a single person\'s council tax, not a couple\'s',
        );
    }

    public function test_council_tax_reduction_is_awarded_on_the_pension_age_basis(): void
    {
        // (a) A single pensioner on Guarantee Credit: passported to the maximum reduction, so the
        // whole liability is met and no council tax reaches the spending target at all.
        $onCredit = $this->forecast($this->household([150]));
        $this->assertTrue($onCredit->years[0]->incomeBySource['means_tested_benefit']->isPositive(), 'the household is on Guarantee Credit');
        $this->assertSame(0, $onCredit->years[0]->councilTax()->pence);
        $this->assertSame((self::ESSENTIAL_SPEND + self::UPKEEP) * 100, $onCredit->years[0]->spendTarget->pence);

        // (b) A single pensioner ABOVE the guarantee: the reduction tapers away at 20p in the
        // pound of the excess income, so part of the bill is still met. £288 a week against the
        // 2026/27 single guarantee of £238.00 is £50 a week of excess income.
        $tapered = $this->forecast($this->household([288]));
        $this->assertFalse($tapered->years[0]->incomeBySource['means_tested_benefit']->isPositive(), 'no Guarantee Credit at this income');

        // The reduction is the liability less the taper, so what the household still pays IS the
        // taper: 20p in the pound of £50 a week of excess income, over 52 weeks.
        $liability = (int) round(self::BILL * 100 * 0.75); // a single occupant, so the discount comes first
        $taper = (int) round(50_00 * 0.20) * 52;
        $this->assertSame($taper, $tapered->years[0]->councilTax()->pence);
        $this->assertLessThan($liability, $tapered->years[0]->councilTax()->pence, 'part of the bill is still met');

        // (c) A well-off single pensioner: income far above the guarantee, no reduction at all.
        $none = $this->forecast($this->household([400]));
        $this->assertSame($liability, $none->years[0]->councilTax()->pence);
    }

    public function test_no_council_tax_reduction_above_the_capital_limit(): void
    {
        // The same low-income pensioner as (b) above, but holding £20,000 — over the £16,000
        // upper capital limit that ends Council Tax Reduction. Capital, not income, is what
        // stops it, and it is the downsizing trap: freed equity crosses this line.
        $poor = $this->forecast($this->household([288]));
        $rich = $this->forecast($this->household([288], isaBalance: 20_000));

        $liability = (int) round(self::BILL * 100 * 0.75);
        $this->assertLessThan($liability, $poor->years[0]->councilTax()->pence);
        $this->assertSame($liability, $rich->years[0]->councilTax()->pence, 'capital above the limit ends the reduction');
    }

    public function test_a_disabled_band_reduction_charges_the_band_below(): void
    {
        // Not means-tested: a couple far above the Pension Credit line still gets it. A band D
        // home is charged as band C — 8 ninths of band D instead of 9.
        $bandD = $this->forecast($this->household([400, 400], disabledBandReduction: CouncilTaxBand::D));
        $this->assertSame((int) round(self::BILL * 100 * 8 / 9), $bandD->years[0]->councilTax()->pence);

        // Band A has no band below it, so the reduction is one ninth of the band D amount: a
        // band A bill is 6 ninths of band D, and it is charged 5.
        $bandA = $this->forecast($this->household([400, 400], disabledBandReduction: CouncilTaxBand::A));
        $this->assertSame((int) round(self::BILL * 100 * 5 / 6), $bandA->years[0]->councilTax()->pence);

        // It stacks with the single-person discount, in the statutory order: the band reduction
        // lowers the liability, and the discount comes off what is left.
        $none = $this->forecast($this->household([400, 400]));
        $this->assertSame(self::BILL * 100, $none->years[0]->councilTax()->pence);
    }
}
