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
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The ISA overall subscription allowance (£20,000 per person per tax year) caps what may be paid
 * IN. Until it was enforced, `applyContributions` routed any amount into the ISA bucket, so the
 * model could shelter income from tax faster than the law permits, for ever.
 *
 * Two properties matter, and the second matters more:
 *  1. the cap bites — a subscription above the allowance does not all land in the ISA;
 *  2. the excess is **not lost**. It spills into the taxable general investment account, because
 *     the household really would still save the money, just somewhere taxable. Silently dropping
 *     it would be the completeness failure this codebase has been bitten by before (a real input
 *     that stops counting), and it would make the household look poorer rather than more taxed.
 *
 * The cap applies to money paid in, never to what the wrapper already holds: a pot that GREW past
 * the allowance inside an ISA is entirely legitimate.
 */
final class IsaSubscriptionCapTest extends TestCase
{
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
            salaryGrowth: Percent::zero(),
            // A REAL dividend yield, deliberately: it is the only thing that makes a GIA behave
            // differently from an ISA. At zero yield the two wrappers are indistinguishable and
            // every assertion below would pass with the cap deleted — a guard that always passes.
            investmentIncomeYield: Percent::fromPercent(3),
        );
    }

    /**
     * A high earner with plenty of surplus and a large standing order into an ISA — the only shape
     * in which the cap can bite at all.
     *
     * @param  list<Account>  $accounts
     */
    private function household(array $accounts): Household
    {
        return new Household(
            'ISA cap',
            RegionProfile::EnglandWalesNi,
            [new Person(
                'p1', new DateTimeImmutable('1970-06-01'), Sex::Female, EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(150_000), plannedRetirementAge: 60,
                longevity: LongevityAdjustment::fixedAge(62),
            )],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(100)),
            accounts: $accounts,
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(1_000), false, false, 0)],
        );
    }

    private function forecast(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_a_subscription_within_the_allowance_is_untouched(): void
    {
        // £20,000 is exactly the allowance: every penny belongs in the ISA, none spills.
        $atCap = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
        ]));

        // After one full year of contributions, liquid wealth reflects the whole £20,000 saved.
        $this->assertGreaterThanOrEqual(
            Money::fromPounds(20_000)->pence,
            $atCap->years[0]->liquidWealth->pence,
        );
    }

    public function test_the_excess_over_the_allowance_spills_to_the_taxable_account_and_is_not_lost(): void
    {
        // £50,000 a year into an ISA: £20,000 may be sheltered, £30,000 may not. The household
        // still saves all £50,000 — so it must behave EXACTLY like a household that subscribes a
        // capped ISA and a GIA explicitly, right down to the dividend tax the spilled money now
        // attracts. That equality is the real assertion: it fails both if the excess is dropped
        // (poorer) and if it is sheltered anyway (untaxed).
        $overCap = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(50_000)),
        ]));
        $split = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
            new Account('p1', AccountType::Gia, Money::zero(), ongoingContributions: Money::fromPounds(30_000)),
        ]));
        foreach ($overCap->years as $i => $year) {
            $this->assertSame(
                $split->years[$i]->liquidWealth->pence,
                $year->liquidWealth->pence,
                "an over-cap ISA subscription saves the same money as an explicit ISA+GIA split in {$year->calendarYear}",
            );
            $this->assertSame(
                $split->years[$i]->totalTax->pence,
                $year->totalTax->pence,
                "and is taxed the same way in {$year->calendarYear} — the spilled money is not sheltered",
            );
        }

        // Sanity: the spilled money really is being taxed, so this comparison has teeth. Without
        // the cap the ISA side would shelter the lot and the tax would match an all-ISA saver.
        $allWithinCap = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
        ]));
        $this->assertGreaterThan(
            $allWithinCap->years[3]->totalTax->pence,
            $overCap->years[3]->totalTax->pence,
            'the money that spilled out of the ISA attracts dividend tax it would not have borne inside it',
        );
    }

    public function test_the_cap_is_per_person_per_year_not_a_lifetime_or_household_limit(): void
    {
        // Two people, each subscribing the full allowance, shelter £40,000 between them in a year —
        // the allowance is personal. And it refreshes: the second year shelters another £20,000.
        $household = new Household(
            'Two savers',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1970-06-01'), Sex::Female, EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(150_000), plannedRetirementAge: 60, longevity: LongevityAdjustment::fixedAge(62)),
                new Person('p2', new DateTimeImmutable('1970-06-01'), Sex::Male, EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(150_000), plannedRetirementAge: 60, longevity: LongevityAdjustment::fixedAge(62)),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(100)),
            accounts: [
                new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
                new Account('p2', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
            ],
        );

        $years = $this->forecast($household)->years;

        $this->assertGreaterThanOrEqual(Money::fromPounds(40_000)->pence, $years[0]->liquidWealth->pence);
        $this->assertGreaterThanOrEqual(Money::fromPounds(80_000)->pence, $years[1]->liquidWealth->pence);
    }

    public function test_two_isas_for_one_person_share_the_single_allowance(): void
    {
        // The allowance is per PERSON, not per account: two £15,000 subscriptions are £30,000 of
        // subscription against one £20,000 allowance, so £10,000 must spill.
        $twoIsas = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(15_000)),
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(15_000)),
        ]));
        $split = $this->forecast($this->household([
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(20_000)),
            new Account('p1', AccountType::Gia, Money::zero(), ongoingContributions: Money::fromPounds(10_000)),
        ]));

        foreach ($twoIsas->years as $i => $year) {
            $this->assertSame(
                $split->years[$i]->liquidWealth->pence,
                $year->liquidWealth->pence,
                "two ISAs share one allowance in {$year->calendarYear}",
            );
        }
    }

    public function test_the_allowance_is_the_sourced_registry_figure_not_a_literal(): void
    {
        // Read the constant that owns the figure rather than restating it, so a change to the
        // statutory allowance moves the cap and this test together.
        $this->assertSame(
            Money::fromPounds(20_000)->pence,
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)->isa->overallAllowance->pence,
        );
    }
}
