<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\HousingPurchase;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Reconciliation invariant for the housing boundary: a home sale must decompose into
 * net proceeds plus the costs netted off it, with no pence created or lost. This is
 * the forecast-boundary half of the data-layer integrity rule — net sale proceeds ==
 * sale − mortgage − costs − CGT — pinned so a future change (e.g. a real CGT charge or
 * the SDLT surcharge) can't silently break the identity.
 */
final class HousingProceedsReconciliationTest extends TestCase
{
    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /**
     * @param  list<Account>  $accounts
     */
    private function household(?Money $mortgage = null, array $accounts = []): Household
    {
        return new Household(
            'Reconcile',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            accounts: $accounts,
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                outstandingMortgage: $mortgage,
            ),
        );
    }

    /** The full funding identity: every pound of the purchase traces to a documented source. */
    private function assertFundingReconciles(HousingPurchase $outcome): void
    {
        $this->assertSame(
            $outcome->netProceeds->pence
                + $outcome->fundedFromSavings->pence
                + $outcome->mortgage->pence
                + $outcome->unfundedGap->pence,
            $outcome->buyPrice->pence
                + $outcome->stampDuty->pence
                + $outcome->movingCosts->pence
                + $outcome->surplus->pence,
        );
    }

    public function test_sale_price_reconciles_to_net_proceeds_plus_its_deductions(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));
        $proceeds = $this->comparison()->saleProceeds($this->household(Money::fromPounds(50_000)), $action);

        // The sale price is exactly the cash kept plus every cost deducted — no pence created or lost.
        $this->assertSame(
            $proceeds->salePrice->pence,
            $proceeds->netProceeds->pence
                + $proceeds->outstandingMortgage->pence
                + $proceeds->sellingCosts->pence
                + $proceeds->capitalGainsTax->pence,
        );
        $this->assertTrue($proceeds->clearsCosts());
    }

    public function test_main_home_cgt_is_zero_under_prr(): void
    {
        $proceeds = $this->comparison()->saleProceeds($this->household(), new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertSame(0, $proceeds->capitalGainsTax->pence);
    }

    public function test_selling_costs_apply_the_default_two_percent(): void
    {
        $proceeds = $this->comparison()->saleProceeds($this->household(), new HousingAction(salePrice: Money::fromPounds(400_000)));

        // 2% of £400,000 = £8,000; net = 400,000 − 0 − 8,000 − 0.
        $this->assertSame(Money::fromPounds(8_000)->pence, $proceeds->sellingCosts->pence);
        $this->assertSame(Money::fromPounds(392_000)->pence, $proceeds->netProceeds->pence);
    }

    public function test_a_single_percent_selling_cost_component_is_honoured(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(3)),
        ]);
        $proceeds = $this->comparison()->saleProceeds($this->household(), $action);

        $this->assertSame(Money::fromPounds(12_000)->pence, $proceeds->sellingCosts->pence);
    }

    public function test_mixed_percent_and_flat_fee_components_sum_to_the_total_selling_cost(): void
    {
        // The real-world mix: an agent on a % of the sale, plus flat conveyancing and EPC fees.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(1.25)),  // £5,000
            new SellingCostComponent('Legal / conveyancing', Money::fromPounds(1_500)),
            new SellingCostComponent('EPC & removals', Money::fromPounds(800)),
        ]);
        $proceeds = $this->comparison()->saleProceeds($this->household(), $action);

        $this->assertSame(Money::fromPounds(7_300)->pence, $proceeds->sellingCosts->pence);
    }

    public function test_the_selling_cost_breakdown_reconciles_to_the_total(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(1.25)),
            new SellingCostComponent('Legal / conveyancing', Money::fromPounds(1_500)),
            new SellingCostComponent('EPC & removals', Money::fromPounds(800)),
        ]);
        $proceeds = $this->comparison()->saleProceeds($this->household(), $action);

        // Each line is labelled and resolved to £; the breakdown sums to the total exactly.
        $this->assertSame(['Estate agent', 'Legal / conveyancing', 'EPC & removals'], array_column($proceeds->sellingCostBreakdown, 'label'));
        $summed = array_sum(array_map(static fn (array $line): int => $line['amount']->pence, $proceeds->sellingCostBreakdown));
        $this->assertSame($proceeds->sellingCosts->pence, $summed);
    }

    public function test_a_let_property_is_charged_partial_prr_cgt_and_still_reconciles(): void
    {
        // Bought £150k, sold £400k, lived in 120 of 240 months then let. Default 2% selling cost
        // = £8,000, so gain = 400,000 − 150,000 − 8,000 = £242,000. Relief = (120+9)/240 × gain =
        // £130,075; chargeable £111,925; less £3,000 = £108,925 @ 24% = £26,142.
        $household = new Household(
            'Let',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                cgtHistory: new CgtHistory(
                    purchasePrice: Money::fromPounds(150_000),
                    improvementCosts: Money::zero(),
                    ownershipMonths: 240,
                    mainResidenceMonths: 120,
                    higherRateOnSale: true,
                    owners: 1,
                ),
            ),
        );

        $proceeds = $this->comparison()->saleProceeds($household, new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertSame(Money::fromPounds(26_142)->pence, $proceeds->capitalGainsTax->pence);
        $this->assertNotNull($proceeds->capitalGainsDetail);
        $this->assertSame(Money::fromPounds(111_925)->pence, $proceeds->capitalGainsDetail->chargeableGain->pence);
        // The boundary identity still holds with a real CGT charge: sale = net + mortgage + costs + CGT.
        $this->assertSame(
            $proceeds->salePrice->pence,
            $proceeds->netProceeds->pence + $proceeds->outstandingMortgage->pence + $proceeds->sellingCosts->pence + $proceeds->capitalGainsTax->pence,
        );
    }

    public function test_negative_equity_floors_net_proceeds_at_zero(): void
    {
        // Mortgage exceeds the sale price: there is nothing to keep, and never a negative.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));
        $proceeds = $this->comparison()->saleProceeds($this->household(Money::fromPounds(450_000)), $action);

        $this->assertSame(0, $proceeds->netProceeds->pence);
        $this->assertFalse($proceeds->clearsCosts());
    }

    public function test_buy_outcome_reconciles_net_proceeds_to_purchase_plus_surplus(): void
    {
        // Sell £400k (no mortgage) → net £392k after 2% costs; buy a £200k home.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000));
        $outcome = $this->comparison()->buyOutcome($this->household(), $action);

        // The net proceeds are exactly the purchase, its costs and the invested surplus — no
        // pence created or lost. This is the buy-side half of the housing-boundary identity.
        $this->assertSame(
            $outcome->netProceeds->pence,
            $outcome->buyPrice->pence
                + $outcome->stampDuty->pence
                + $outcome->movingCosts->pence
                + $outcome->surplus->pence,
        );
        $this->assertTrue($outcome->coversPurchase());
        $this->assertTrue($outcome->isFullyFunded());
        $this->assertFundingReconciles($outcome);
        // Default moving costs are £2,000; SDLT on a £200k home (England, 2025/26 bands) is £1,500.
        $this->assertSame(Money::fromPounds(2_000)->pence, $outcome->movingCosts->pence);
        $this->assertSame(Money::fromPounds(1_500)->pence, $outcome->stampDuty->pence);
    }

    public function test_a_buy_with_no_savings_and_no_mortgage_reports_the_whole_gap_unfunded(): void
    {
        // Buying dearer than the net proceeds with nothing to fund the gap: the shortfall is
        // reported as an unfunded gap, never absorbed (the home is not handed over for free).
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000));
        $outcome = $this->comparison()->buyOutcome($this->household(), $action);

        $this->assertSame(0, $outcome->surplus->pence);
        $this->assertSame(0, $outcome->mortgage->pence, 'a cash-only buy borrows nothing');
        $this->assertSame(0, $outcome->fundedFromSavings->pence, 'no savings to draw');
        // Net £392k vs £500k + £15k SDLT + £2k moving = £517k total cost → £125k unfunded.
        $this->assertSame(Money::fromPounds(125_000)->pence, $outcome->unfundedGap->pence);
        $this->assertFalse($outcome->coversPurchase());
        $this->assertFalse($outcome->isFullyFunded());
        $this->assertFundingReconciles($outcome);
    }

    public function test_a_buy_mortgage_funds_the_shortfall_and_reconciles(): void
    {
        // Buying dearer than the proceeds, but a 6% buy mortgage is available: the shortfall is
        // borrowed instead of flooring the surplus and pretending the home was free.
        $action = new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(500_000),
            buyMortgageRate: Percent::fromPercent(6),
        );
        $outcome = $this->comparison()->buyOutcome($this->household(), $action);

        $this->assertTrue($outcome->mortgage->isPositive(), 'the shortfall is borrowed');
        $this->assertSame(0, $outcome->surplus->pence, 'all the cash goes into the purchase');
        $this->assertSame(0, $outcome->fundedFromSavings->pence, 'no savings to draw');
        $this->assertTrue($outcome->isFullyFunded());
        $this->assertFundingReconciles($outcome);
    }

    public function test_savings_fund_the_gap_before_the_mortgage(): void
    {
        // Net £392k vs a £517k total cost → a £125k gap. £60k of cash savings is drawn first;
        // the 6% RIO borrows only the £65k remainder — own money before interest-bearing debt.
        $action = new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(500_000),
            buyMortgageRate: Percent::fromPercent(6),
        );
        $household = $this->household(accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(60_000))]);
        $outcome = $this->comparison()->buyOutcome($household, $action);

        $this->assertSame(Money::fromPounds(60_000)->pence, $outcome->fundedFromSavings->pence);
        $this->assertSame(Money::fromPounds(65_000)->pence, $outcome->mortgage->pence);
        $this->assertSame(0, $outcome->unfundedGap->pence);
        $this->assertTrue($outcome->isFullyFunded());
        $this->assertFundingReconciles($outcome);

        // The variant household's savings are actually reduced, its mortgage interest charged
        // on the borrowed remainder only — the reported figures and the projected money agree.
        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $buy = $variants['buy_outright']['household'];
        $this->assertSame(0, $buy->accounts[0]->balance->pence, 'the cash was spent on the home');
        $this->assertSame(
            Money::fromPounds(65_000)->applyRate(Percent::fromPercent(6))->pence,
            $buy->expenseProfile->mortgageCosts()->pence,
        );
    }

    public function test_savings_draw_order_is_cash_then_gia_then_isa_across_persons_and_pensions_are_untouched(): void
    {
        // Deliberately shuffled account order; two owners. The draw is tier-major (cash+Premium
        // Bonds → GIA → ISA), persons in declaration order within a tier. Pensions are never touched.
        $household = new Household(
            'Waterfall',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1960-04-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            pensions: [new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55)],
            accounts: [
                new Account('p1', AccountType::Isa, Money::fromPounds(20_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(10_000)),
                new Account('p1', AccountType::Cash, Money::fromPounds(5_000)),
                new Account('p1', AccountType::Gia, Money::fromPounds(8_000)),
            ],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
        );
        // Net £392k; buy £510k + £15.5k SDLT + £2k moving = £527.5k → gap £135.5k, far above the
        // £43k of liquid savings: everything liquid is drawn (in order), the rest is unfunded.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(510_000));
        $outcome = $this->comparison()->buyOutcome($household, $action);

        $this->assertSame(Money::fromPounds(43_000)->pence, $outcome->fundedFromSavings->pence);
        $this->assertFundingReconciles($outcome);

        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $buy = $variants['buy_outright']['household'];
        foreach ($buy->accounts as $account) {
            $this->assertSame(0, $account->balance->pence, "{$account->ownerId} {$account->type->value} should be drained");
        }
        $this->assertSame(Money::fromPounds(100_000)->pence, $buy->pensions[0]->currentValue->pence, 'pensions are never drawn');
    }

    public function test_a_partial_savings_draw_stops_at_the_gap_in_tier_order(): void
    {
        // Gap smaller than the savings: cash (both persons) drains before the GIA is touched,
        // and the ISA is untouched. Net £392k; buy £430k + £11.5k SDLT + £2k moving = £443.5k
        // → gap £51,500.
        $household = new Household(
            'PartialDraw',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1960-04-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            accounts: [
                new Account('p1', AccountType::Isa, Money::fromPounds(20_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(10_000)),
                new Account('p1', AccountType::Cash, Money::fromPounds(5_000)),
                new Account('p1', AccountType::Gia, Money::fromPounds(50_000)),
            ],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
        );
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(430_000));
        $outcome = $this->comparison()->buyOutcome($household, $action);

        $this->assertSame(Money::fromPounds(51_500)->pence, $outcome->fundedFromSavings->pence);
        $this->assertSame(0, $outcome->unfundedGap->pence);
        $this->assertFundingReconciles($outcome);

        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $accounts = $variants['buy_outright']['household']->accounts;
        $this->assertSame(Money::fromPounds(20_000)->pence, $accounts[0]->balance->pence, 'the ISA is the last tier, untouched');
        $this->assertSame(0, $accounts[1]->balance->pence, 'p2 cash drained');
        $this->assertSame(0, $accounts[2]->balance->pence, 'p1 cash drained');
        $this->assertSame(Money::fromPounds(50_000 - 36_500)->pence, $accounts[3]->balance->pence, 'GIA drawn for the £36.5k remainder only');
    }

    public function test_premium_bonds_are_drawn_in_the_cash_tier_before_the_gia(): void
    {
        // Premium Bonds pair with cash in the projection's own bucket mapping, so the year-0
        // draw must treat them the same or the pre- and in-projection orders diverge.
        $household = $this->household(accounts: [
            new Account('p1', AccountType::Gia, Money::fromPounds(10_000)),
            new Account('p1', AccountType::PremiumBonds, Money::fromPounds(10_000)),
        ]);
        // Net £392k; buy £395k + £9.75k SDLT + £2k moving = £406.75k → gap £14,750.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(395_000));
        $outcome = $this->comparison()->buyOutcome($household, $action);

        $this->assertSame(Money::fromPounds(14_750)->pence, $outcome->fundedFromSavings->pence);

        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $accounts = $variants['buy_outright']['household']->accounts;
        $this->assertSame(0, $accounts[1]->balance->pence, 'Premium Bonds drained first (cash tier)');
        $this->assertSame(Money::fromPounds(10_000 - 4_750)->pence, $accounts[0]->balance->pence, 'GIA drawn only for the remainder');
    }

    public function test_a_gia_draw_realises_the_pro_rata_gain_and_reduces_the_carried_unrealised_gain(): void
    {
        // GIA £100k with a £40k unrealised gain; a £50k draw realises £20k of gain (pro-rata)
        // and leaves the account carrying £50k balance / £20k gain — the cost basis
        // (balance − unrealisedGain) stays exact so a later disposal is never taxed twice.
        $household = $this->household(accounts: [
            new Account('p1', AccountType::Gia, Money::fromPounds(100_000), unrealisedGain: Money::fromPounds(40_000)),
        ]);
        // Net £392k; buy £430k + £11.5k SDLT + £2k moving = £443.5k → a £51,500 gap, fully
        // covered by the GIA. The expected gain slice is derived from the drawn figure itself
        // so the assertion tracks the engine's own SDLT.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(430_000));
        $outcome = $this->comparison()->buyOutcome($household, $action);
        $gap = $outcome->fundedFromSavings->pence;

        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $gia = $variants['buy_outright']['household']->accounts[0];
        $expectedGainSlice = (int) round(40_000_00 * $gap / 100_000_00);
        $this->assertSame(100_000_00 - $gap, $gia->balance->pence);
        $this->assertSame(40_000_00 - $expectedGainSlice, $gia->unrealisedGain->pence);
    }

    public function test_a_non_reconciling_purchase_cannot_be_constructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HousingPurchase(
            netProceeds: Money::fromPounds(100_000),
            buyPrice: Money::fromPounds(200_000),
            stampDuty: Money::zero(),
            movingCosts: Money::zero(),
            surplus: Money::zero(),
            mortgage: Money::zero(),
            fundedFromSavings: Money::zero(),
            unfundedGap: Money::zero(), // £100k of the purchase traces to no source
        );
    }

    public function test_the_buy_variant_preserves_the_relationship_status(): void
    {
        // A cohabiting couple's IHT treatment must survive the housing transform (it used to
        // silently revert to the married default).
        $household = new Household(
            'Cohabiting',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1960-04-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
            relationshipStatus: RelationshipStatus::Cohabiting,
        );
        $variants = $this->comparison()->variantInputs(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000)),
        );

        $this->assertSame(RelationshipStatus::Cohabiting, $variants['buy_outright']['household']->relationshipStatus);
        $this->assertSame(RelationshipStatus::Cohabiting, $variants['rent']['household']->relationshipStatus);
    }

    public function test_a_mortgaged_buy_variant_carries_the_loan_and_its_interest_only_payment(): void
    {
        $action = new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(500_000),
            buyMortgageRate: Percent::fromPercent(6),
        );
        $outcome = $this->comparison()->buyOutcome($this->household(), $action);
        $variants = $this->comparison()->variantInputs(
            $this->household(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            $action,
        );
        $buy = $variants['buy_outright']['household'];

        // The new home carries the borrowed balance, and the interest-only payment (mortgage × rate)
        // is charged as its mortgage cost — so the projection pays the RIO interest for life.
        $this->assertNotNull($buy->primaryResidence);
        $this->assertSame($outcome->mortgage->pence, $buy->primaryResidence->outstandingMortgage->pence);
        $this->assertSame(
            $outcome->mortgage->applyRate(Percent::fromPercent(6))->pence,
            $buy->expenseProfile->mortgageCosts()->pence,
        );
    }
}
