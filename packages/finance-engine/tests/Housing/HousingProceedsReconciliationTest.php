<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
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

    private function household(?Money $mortgage = null): Household
    {
        return new Household(
            'Reconcile',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                outstandingMortgage: $mortgage,
            ),
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
        // Default moving costs are £2,000; SDLT on a £200k home (England, 2025/26 bands) is £1,500.
        $this->assertSame(Money::fromPounds(2_000)->pence, $outcome->movingCosts->pence);
        $this->assertSame(Money::fromPounds(1_500)->pence, $outcome->stampDuty->pence);
    }

    public function test_buy_surplus_floors_at_zero_when_the_cheaper_home_costs_more_than_the_proceeds(): void
    {
        // Buying dearer than the net proceeds leaves nothing to invest, never a negative surplus.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(500_000));
        $outcome = $this->comparison()->buyOutcome($this->household(), $action);

        $this->assertSame(0, $outcome->surplus->pence);
        $this->assertFalse($outcome->coversPurchase());
    }
}
