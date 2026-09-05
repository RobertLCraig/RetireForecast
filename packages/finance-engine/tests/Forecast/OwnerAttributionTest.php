<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathDraws;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Whose money is it? (board card 0040.)
 *
 * Three places used to hand liquid wealth to whoever was DECLARED FIRST: the year-0 sale
 * proceeds in {@see HousingComparison::variantInputs()}, the forced-sale proceeds in
 * {@see PathProjector}, and every year's banked surplus. The care means test is deliberately
 * INDIVIDUAL (England assesses the resident, not the household), so the second-declared person
 * went into care with an empty balance sheet and was funded by the local authority years before
 * they would be in life. The model's answer therefore depended on typing order.
 *
 * The care charge is the instrument in four of these five tests because it is the only
 * per-person figure a ForecastResult exposes: a resident with capital of their own is a
 * self-funder charged the gross fee, and a resident with nothing is charged only their income
 * above the Personal Expenses Allowance (or nothing at all).
 *
 * WHAT THESE DO NOT PROVE. Where the assessment reads capital as the CARE YEAR OPENED, they
 * assert on the first care year and not the whole spell. The fee is funded by a drawdown that
 * still empties the first-declared person's accounts before the second's, so a later care year
 * moves with typing order for a reason this card did not reach. That is board card 0101.
 *
 * Fixture arithmetic reuses {@see CareMeansTestedChargeTest}: zero growth and zero inflation, so
 * nominal == real; the care fee is that file's FEE_REAL a year.
 */
final class OwnerAttributionTest extends TestCase
{
    private function config(): TaxYearConfig
    {
        return TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
    }

    /** Flat draws (zero returns, zero inflation) with a fixed care fee from a given age to death. */
    private function draws(array $deathAges, array $careFromAge = []): PathDraws
    {
        return new class($deathAges, $careFromAge) implements PathDraws
        {
            public function __construct(private readonly array $deathAges, private readonly array $careFromAge) {}

            public function investmentRealReturn(int $yearIndex): float
            {
                return 0.0;
            }

            public function cashRealReturn(int $yearIndex): float
            {
                return 0.0;
            }

            public function investmentIncomeYield(): float
            {
                return 0.0;
            }

            public function investmentChargeRate(): float
            {
                return 0.0;
            }

            public function inflation(int $yearIndex): float
            {
                return 0.0;
            }

            public function propertyGrowthReal(int $yearIndex, ?float $meanReal = null): float
            {
                return $meanReal ?? 0.0;
            }

            public function salaryGrowthReal(int $yearIndex): float
            {
                return 0.0;
            }

            public function deathAge(string $personId): int
            {
                return $this->deathAges[$personId];
            }

            public function careAnnualCost(string $personId, int $age): int
            {
                $from = $this->careFromAge[$personId] ?? null;

                return $from !== null && $age >= $from ? CareMeansTestedChargeTest::FEE_REAL : 0;
            }

            public function careCostRealGrowth(): float
            {
                return 0.0;
            }
        };
    }

    private function project(Household $household, array $deathAges, array $careFromAge = []): ForecastResult
    {
        return (new PathProjector($this->config()))->project(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            $this->draws($deathAges, $careFromAge),
        );
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

    /** Two people of identical age and status, so nothing but the DECLARATION ORDER differs. */
    private function person(string $id): Person
    {
        return new Person($id, new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired);
    }

    private function income(string $ownerId, int $pounds): IncomeStream
    {
        return new IncomeStream($ownerId, IncomeStreamType::Other, Money::fromPounds($pounds), taxable: true, inflationLinked: false, startAge: 60);
    }

    /** Every GIA balance in a household, keyed by who owns it. */
    private function giaByOwner(Household $household): array
    {
        $out = [];
        foreach ($household->accounts as $account) {
            if ($account->type === AccountType::Gia) {
                $out[$account->ownerId] = ($out[$account->ownerId] ?? 0) + $account->balance->pence;
            }
        }
        ksort($out);

        return $out;
    }

    // ---------------------------------------------------------------- criterion 1

    public function test_the_sale_proceeds_of_a_jointly_owned_home_are_split_between_the_owners(): void
    {
        // A couple sell the home they own together and rent. The proceeds belong to BOTH of them,
        // so both must be credited; before card 0040 the whole lot was a single GIA account in the
        // first-declared person's name.
        $household = new Household(
            'JointSale', RegionProfile::EnglandWalesNi,
            [$this->person('p1'), $this->person('p2')],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(100)),
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
            ),
        );
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));

        $comparison = new HousingComparison($this->config(), new CohortLifeTable);
        $net = $comparison->saleProceeds($household, $action)->netProceeds->pence;
        $this->assertSame(Money::fromPounds(384_000)->pence, $net, 'the fixture nets 4% selling costs off a £400k sale');

        $rented = $comparison->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            $this->flat(),
            $action,
        )['rent']['household'];

        $this->assertSame(
            ['p1' => intdiv($net, 2), 'p2' => intdiv($net, 2)],
            $this->giaByOwner($rented),
            'both owners are credited their share of the sale',
        );
    }

    public function test_a_forced_sale_credits_each_owner_their_share_of_the_proceeds(): void
    {
        // The same split, on the other sale path: a mid-projection forced sale. The resident goes
        // into care sixteen years after it, and her own half of the freed equity is what decides
        // whether she is a self-funder. She is declared SECOND, which used to leave her with
        // nothing of her own and the local authority paying most of the bill.
        $household = new Household(
            'ForcedSaleShare', RegionProfile::EnglandWalesNi,
            [$this->person('p1'), $this->person('p2')],
            // Spend equals the couple's combined net income, so nothing is banked or drawn and the
            // only capital either of them holds is their share of the sale.
            new ExpenseProfile(Money::fromPounds(2 * 26_514), Money::zero(), Percent::fromPercent(100)),
            incomeStreams: [$this->income('p1', 30_000), $this->income('p2', 30_000)],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );

        $years = $this->byYear($this->project(
            $household,
            deathAges: ['p1' => 95, 'p2' => 90],
            careFromAge: ['p2' => 88],
        ));

        // Half of £384,000 is far above the £23,250 upper capital limit, so she pays the gross fee.
        $this->assertSame(
            CareMeansTestedChargeTest::FEE_REAL,
            $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence,
            'the second-declared owner holds her half of the proceeds, so she self-funds',
        );
    }

    // ---------------------------------------------------------------- criterion 2

    public function test_a_surplus_banks_to_the_person_whose_income_produced_it(): void
    {
        // All the income is p1's; p2 has none. Twenty years of surplus therefore belong to p1, and
        // p2 goes into care with nothing of her own, which in England means the local authority
        // funds the whole spell. p2 is declared FIRST, so before card 0040 every penny p1 earned
        // was banked in p2's name and she was charged as a wealthy self-funder.
        $household = new Household(
            'EarnerSurplus', RegionProfile::EnglandWalesNi,
            [$this->person('p2'), $this->person('p1')],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(100)),
            incomeStreams: [$this->income('p1', 60_000)],
        );

        $result = $this->project($household, deathAges: ['p1' => 95, 'p2' => 90], careFromAge: ['p2' => 88]);

        $this->assertNull(
            $result->careCostRealValue,
            'a resident who generated none of the surplus banks none of it, so she is fully funded',
        );
    }

    public function test_an_unattributable_surplus_splits_evenly_between_the_household(): void
    {
        // Nobody generates this surplus: the household's only money is Pension Credit, which is a
        // HOUSEHOLD award. With no way to attribute it, it splits evenly, so the two members hold
        // the same capital and the care assessment cannot depend on which of them needs the care.
        //
        // The FIRST care year is the honest instrument. It is assessed on capital as the year
        // opened, which is what this criterion is about. Later care years are not comparable,
        // because the fee is funded by a drawdown that still empties the first-declared person's
        // accounts first (board card 0101), and that moves the resident's own balance.
        $firstCareYear = function (string $residentId, string $otherId): int {
            $household = new Household(
                'CreditSurplus', RegionProfile::EnglandWalesNi,
                [$this->person('p1'), $this->person('p2')],
                new ExpenseProfile(Money::zero(), Money::zero(), Percent::fromPercent(100)),
            );

            $years = $this->byYear($this->project(
                $household,
                deathAges: [$residentId => 90, $otherId => 95],
                careFromAge: [$residentId => 88],
            ));

            return $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence;
        };

        $first = $firstCareYear('p1', 'p2');
        $second = $firstCareYear('p2', 'p1');

        $this->assertSame(
            CareMeansTestedChargeTest::FEE_REAL,
            $first,
            'the banked credit gives each member capital of their own, so either of them self-funds',
        );
        $this->assertSame($first, $second, 'an unattributable surplus is shared, so either resident is assessed alike');
    }

    // ---------------------------------------------------------------- criterion 3

    public function test_swapping_the_order_the_household_was_entered_in_leaves_the_first_care_year_unchanged(): void
    {
        // A household that exercises both sale paths and the surplus at once: a jointly owned home
        // sold mid-projection, an annual surplus banked from two equal incomes, and one member in
        // care at the end. Typing the two people the other way round no longer changes what the
        // resident owns, so it no longer changes what she is assessed on.
        //
        // This is the FIRST care year only, and the criterion it comes from is NOT fully met. The
        // whole spell still moves with typing order on a plan that funds the fee by drawing down,
        // because the funding waterfall empties the first-declared person's accounts first. That is
        // a fourth site of the same fault and it is board card 0101, not this one: it decides whose
        // assets pay for shared spending, which is a modelling change in its own right.
        $charge = function (bool $swapped): int {
            $p1 = $this->person('p1');
            $p2 = $this->person('p2');
            $household = new Household(
                'Order', RegionProfile::EnglandWalesNi,
                $swapped ? [$p2, $p1] : [$p1, $p2],
                new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(100)),
                incomeStreams: [$this->income('p1', 30_000), $this->income('p2', 30_000)],
                primaryResidence: new Property(
                    currentValue: Money::fromPounds(400_000),
                    ownership: OwnershipType::Outright,
                    mortgageRedemptionYear: 2030,
                    mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
                ),
            );

            $years = $this->byYear($this->project(
                $household,
                deathAges: ['p1' => 95, 'p2' => 90],
                careFromAge: ['p2' => 88],
            ));

            return $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence;
        };

        $this->assertGreaterThan(0, $charge(false), 'the fixture bears a real care cost');
        $this->assertSame($charge(false), $charge(true), 'the care assessment does not depend on typing order');
    }

    /** Zero growth and zero inflation, so a sale price is the entered value. */
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
}
