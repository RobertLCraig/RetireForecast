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
 * The other half of the ISA rules. {@see IsaSubscriptionCapTest} proves the allowance is
 * ENFORCED; this proves it is USED.
 *
 * Until this existed the engine capped what could be paid into an ISA but never moved anything
 * into one, so a household holding money in a taxable General Investment Account was modelled as
 * leaving it there for ever, paying dividend tax on it year after year. That is not what a real
 * household does: they move up to their allowance across each year ("bed and ISA"), and the
 * plans it understated most are exactly the ones this tool exists to compare: sell the home and
 * invest the proceeds, which land in a GIA.
 *
 * The properties that matter:
 *  1. money really moves, and only up to the statutory allowance;
 *  2. the allowance is SHARED with anything paid in, so it cannot be spent twice;
 *  3. it is a disposal, so it stays inside the CGT annual exempt amount and never conjures a tax
 *     bill the projection would then have to fund;
 *  4. it makes the household better off, which is the whole point of closing the gap;
 *  5. it is switchable, so a household that would not do it is not modelled as doing it.
 */
final class BedAndIsaTest extends TestCase
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
            // A real dividend yield is what makes a GIA behave differently from an ISA. At zero
            // yield the two wrappers are indistinguishable and every assertion here would pass
            // with the transfer deleted.
            investmentIncomeYield: Percent::fromPercent(4),
        );
    }

    /**
     * A retired household living on a modest income beside a large unwrapped investment pot,
     * the shape a sale-and-invest plan produces.
     *
     * @param  list<Account>  $accounts
     */
    private function household(array $accounts, int $incomePounds): Household
    {
        return new Household(
            'Unwrapped',
            RegionProfile::EnglandWalesNi,
            [new Person(
                'p1', new DateTimeImmutable('1955-06-01'), Sex::Female, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(80),
            )],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(100)),
            accounts: $accounts,
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds($incomePounds), true, false, 0)],
        );
    }

    /**
     * @param  list<Account>  $accounts
     */
    private function forecast(array $accounts, bool $useIsaAllowance = true, int $incomePounds = 20_000): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $this->household($accounts, $incomePounds),
                $this->flat(),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', useIsaAllowance: $useIsaAllowance),
            );
    }

    private function allowance(): Money
    {
        return TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)->isa->overallAllowance;
    }

    public function test_the_years_unused_allowance_is_spent_on_money_already_held_in_a_taxable_account(): void
    {
        // £200,000 sitting in a GIA at cost. One allowance moves each year, no more and no less,
        // until the GIA is empty, read from the registry so the statutory figure owns it.
        $years = $this->forecast([
            new Account('p1', AccountType::Gia, Money::fromPounds(200_000), unrealisedGain: Money::zero()),
        ])->years;

        $this->assertSame($this->allowance()->pence, $years[0]->isaSheltered()->pence);
        $this->assertSame($this->allowance()->pence, $years[1]->isaSheltered()->pence);
    }

    public function test_nothing_moves_when_the_allowance_was_already_spent_on_a_subscription(): void
    {
        // The allowance is one allowance, shared between money paid in and money moved in. A
        // household with income enough to subscribe the full £20,000 has nothing left to shelter
        // their GIA with this year, so the transfer must not hand them a second allowance.
        $years = $this->forecast([
            new Account('p1', AccountType::Gia, Money::fromPounds(200_000), unrealisedGain: Money::zero()),
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: $this->allowance()),
        ], incomePounds: 60_000)->years;

        $this->assertSame(0, $years[0]->isaSheltered()->pence);
    }

    public function test_nothing_moves_for_a_household_with_no_taxable_account(): void
    {
        // Already sheltered: there is nothing to move, and the step must not invent a transfer.
        $years = $this->forecast([
            new Account('p1', AccountType::Isa, Money::fromPounds(200_000)),
        ])->years;

        $this->assertSame(0, $years[0]->isaSheltered()->pence);
    }

    public function test_the_transfer_stays_inside_the_capital_gains_exempt_amount(): void
    {
        // A holding standing at a large gain cannot be moved wholesale: selling it is a disposal.
        // Half the £200,000 is gain, so moving £X realises £X/2, and the exempt amount (£3,000)
        // caps the move at £6,000, not the full £20,000 allowance.
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
        $years = $this->forecast([
            new Account('p1', AccountType::Gia, Money::fromPounds(200_000), unrealisedGain: Money::fromPounds(100_000)),
        ])->years;

        $this->assertSame(
            $config->cgt->annualExemptAmount->pence * 2,
            $years[0]->isaSheltered()->pence,
            'the move is sized so the gain it realises fits the exempt amount exactly',
        );
        $this->assertLessThan($this->allowance()->pence, $years[0]->isaSheltered()->pence);
    }

    public function test_sheltering_makes_the_household_better_off_and_is_switchable(): void
    {
        // The point of the whole exercise. Same household, same money, same spending: the only
        // difference is whether the allowance is used. Turning it off must reproduce the old,
        // understated plan, which is what makes this a real guard rather than a tautology.
        $accounts = [new Account('p1', AccountType::Gia, Money::fromPounds(200_000), unrealisedGain: Money::zero())];
        $used = $this->forecast($accounts);
        $unused = $this->forecast($accounts, useIsaAllowance: false);

        $this->assertSame(0, $unused->years[0]->isaSheltered()->pence, 'off means off');
        $this->assertGreaterThan(
            $unused->terminalUsableWealth->pence,
            $used->terminalUsableWealth->pence,
            'sheltering the dividends from tax leaves the household with more at the end',
        );

        // And the gain is tax, not conjured capital: the extra wealth is the dividend tax saved.
        $taxUsed = array_sum(array_map(static fn ($y): int => $y->totalTax->pence, $used->years));
        $taxUnused = array_sum(array_map(static fn ($y): int => $y->totalTax->pence, $unused->years));
        $this->assertLessThan($taxUnused, $taxUsed);
    }
}
