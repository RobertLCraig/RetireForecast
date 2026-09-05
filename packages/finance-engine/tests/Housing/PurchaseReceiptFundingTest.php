<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
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
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\HousingPurchase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0034. A documented {@see CapitalReceipt} landing in the purchase year is money the
 * household actually has that year, so the year-0 purchase-funding waterfall spends it FIRST —
 * before any savings are drawn (which would realise a GIA gain and pay CGT nobody owes) and long
 * before anything is borrowed. Before this the waterfall read the household's accounts and nothing
 * else, so a plan took out a lifetime mortgage while the money to close the gap sat beside it.
 *
 * The mirror-image defect is guarded here too: the spent part of the receipt is CONSUMED, so the
 * projector no longer also credits it as that year's income. The same pound cannot both buy the
 * home and arrive in the bank.
 */
final class PurchaseReceiptFundingTest extends TestCase
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

    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A £400k outright home and £31k of taxable income. Sold at £400k, the 4% selling costs leave
     * £384,000 net; buying at £500k costs £500,000 + £15,000 SDLT + £2,000 moving = £517,000, so
     * the purchase gap under test is exactly £133,000.
     *
     * @param  list<CapitalReceipt>  $receipts
     * @param  list<Account>  $accounts
     */
    private function household(array $receipts = [], array $accounts = []): Household
    {
        return new Household(
            'ReceiptFunding',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(17_114), Money::fromPounds(2_000), Percent::fromPercent(100)),
            accounts: $accounts,
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(31_000), true, false, 60)],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
            capitalReceipts: $receipts,
        );
    }

    /** A £500k buy against the £400k sale, with a 6% RIO available to cover whatever is left. */
    private function action(): HousingAction
    {
        return new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(500_000),
            buyMortgageRate: Percent::fromPercent(6),
        );
    }

    /** The full funding identity, now carrying the receipt term. */
    private function assertFundingReconciles(HousingPurchase $outcome): void
    {
        $this->assertSame(
            $outcome->netProceeds->pence
                + $outcome->fundedFromReceipts->pence
                + $outcome->fundedFromSavings->pence
                + $outcome->mortgage->pence
                + $outcome->unfundedGap->pence,
            $outcome->buyPrice->pence
                + $outcome->stampDuty->pence
                + $outcome->movingCosts->pence
                + $outcome->surplus->pence,
        );
    }

    public function test_a_receipt_arriving_in_the_purchase_year_is_spent_before_anything_is_borrowed(): void
    {
        // The receipt is exactly the £133,000 gap, and the household also holds £60,000 of cash and
        // has a 6% RIO available. Neither is needed: the money arriving that year closes the gap on
        // its own, so the plan borrows nothing and keeps its savings.
        $household = $this->household(
            receipts: [new CapitalReceipt('p1', 'Inheritance', Money::fromPounds(133_000), 2026)],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(60_000))],
        );
        $outcome = $this->comparison()->buyOutcome($household, $this->action(), 2026);

        $this->assertSame(0, $outcome->mortgage->pence, 'nothing is borrowed beside money the plan already has');
        $this->assertSame(0, $outcome->fundedFromSavings->pence, 'the receipt is spent before the savings are drawn');
        $this->assertSame(Money::fromPounds(133_000)->pence, $outcome->fundedFromReceipts->pence);
        $this->assertSame(0, $outcome->unfundedGap->pence);
        $this->assertTrue($outcome->isFullyFunded());
        $this->assertFundingReconciles($outcome);

        // The projected household agrees with the reported figures: the home is owned outright,
        // it carries no mortgage payment for life, and the cash is still in the plan.
        $buy = $this->comparison()
            ->variantInputs($household, $this->settings(), $this->flat(), $this->action())['buy_outright']['household'];
        $this->assertNotNull($buy->primaryResidence);
        $this->assertSame(OwnershipType::Outright, $buy->primaryResidence->ownership);
        $this->assertNull($buy->primaryResidence->outstandingMortgage);
        $this->assertSame(0, $buy->expenseProfile->mortgageCosts()->pence, 'no interest is charged for the rest of the projection');
        $this->assertSame(Money::fromPounds(60_000)->pence, $buy->accounts[0]->balance->pence, 'the savings are untouched');
    }

    public function test_only_the_shortfall_left_after_the_receipt_and_the_savings_is_borrowed(): void
    {
        // A £100,000 receipt and £20,000 of cash against the £133,000 gap: the receipt goes first,
        // the savings next, and the RIO takes the £13,000 remainder and nothing more.
        $household = $this->household(
            receipts: [new CapitalReceipt('p1', 'Inheritance', Money::fromPounds(100_000), 2026)],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
        );
        $outcome = $this->comparison()->buyOutcome($household, $this->action(), 2026);

        $this->assertSame(Money::fromPounds(13_000)->pence, $outcome->mortgage->pence, 'only the shortfall is charged');
        $this->assertSame(Money::fromPounds(100_000)->pence, $outcome->fundedFromReceipts->pence);
        $this->assertSame(Money::fromPounds(20_000)->pence, $outcome->fundedFromSavings->pence);
        $this->assertSame(0, $outcome->unfundedGap->pence);
        $this->assertFundingReconciles($outcome);

        // And the loan the projection actually services is that shortfall, not the whole gap.
        $buy = $this->comparison()
            ->variantInputs($household, $this->settings(), $this->flat(), $this->action())['buy_outright']['household'];
        $this->assertSame(Money::fromPounds(13_000)->pence, $buy->primaryResidence->outstandingMortgage->pence);
        $this->assertSame(
            Money::fromPounds(13_000)->applyRate(Percent::fromPercent(6))->pence,
            $buy->expenseProfile->mortgageCosts()->pence,
        );
    }

    public function test_a_receipt_with_no_mortgage_available_leaves_only_the_shortfall_unfunded(): void
    {
        // The same shortfall rule with no borrowing configured: the £133,000 gap less a £120,000
        // receipt is a £13,000 unfunded gap, charged as a year-0 cost — never absorbed silently.
        $household = $this->household(receipts: [new CapitalReceipt('p1', 'Gift', Money::fromPounds(120_000), 2026)]);
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000));
        $outcome = $this->comparison()->buyOutcome($household, $action, 2026);

        $this->assertSame(Money::fromPounds(120_000)->pence, $outcome->fundedFromReceipts->pence);
        $this->assertSame(Money::fromPounds(13_000)->pence, $outcome->unfundedGap->pence);
        $this->assertSame(0, $outcome->mortgage->pence);
        $this->assertFundingReconciles($outcome);
    }

    public function test_a_receipt_dated_another_year_cannot_pay_for_a_purchase_today(): void
    {
        // Money that does not exist yet buys nothing: a 2030 receipt leaves the 2026 purchase to
        // the savings and the RIO exactly as before, and is still there in 2030.
        $receipt = new CapitalReceipt('p1', 'Inheritance', Money::fromPounds(133_000), 2030);
        $household = $this->household(
            receipts: [$receipt],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(60_000))],
        );
        $outcome = $this->comparison()->buyOutcome($household, $this->action(), 2026);

        $this->assertSame(0, $outcome->fundedFromReceipts->pence);
        $this->assertSame(Money::fromPounds(60_000)->pence, $outcome->fundedFromSavings->pence);
        $this->assertSame(Money::fromPounds(73_000)->pence, $outcome->mortgage->pence);
        $this->assertFundingReconciles($outcome);

        $buy = $this->comparison()
            ->variantInputs($household, $this->settings(), $this->flat(), $this->action())['buy_outright']['household'];
        $this->assertCount(1, $buy->capitalReceipts);
        $this->assertSame(Money::fromPounds(133_000)->pence, $buy->capitalReceipts[0]->amount->pence);
        $this->assertSame(2030, $buy->capitalReceipts[0]->calendarYear);
    }

    public function test_the_part_of_the_receipt_spent_on_the_home_is_not_also_banked_as_income(): void
    {
        // A £150,000 receipt against the £133,000 gap. £133,000 of it goes into the home, so the
        // forecast may credit only the £17,000 remainder that year — otherwise the same pound both
        // buys the house and arrives in the bank, which is the mirror image of the bug being fixed.
        $household = $this->household(receipts: [new CapitalReceipt('p1', 'Inheritance', Money::fromPounds(150_000), 2026)]);
        $variants = $this->comparison()->variantInputs($household, $this->settings(), $this->flat(), $this->action());

        $forecaster = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $buy = $forecaster->forecast($variants['buy_outright']['household'], $this->flat(), $this->settings());

        $this->assertSame(2026, $buy->years[0]->calendarYear);
        $this->assertSame(
            Money::fromPounds(17_000)->pence,
            $buy->years[0]->incomeBySource['capital_receipt']->pence,
            'only the unspent remainder of the receipt arrives as income',
        );

        // Staying put buys nothing, so its receipt is untouched — the consumption is the buy
        // variant's alone.
        $stayPut = $forecaster->forecast($variants['stay_put']['household'], $this->flat(), $this->settings());
        $this->assertSame(Money::fromPounds(150_000)->pence, $stayPut->years[0]->incomeBySource['capital_receipt']->pence);
    }
}
