<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
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
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The house-sale explainer on the results page (the show-your-working layer). It must read
 * the engine's single-source decomposition and surface it faithfully: the figures it shows
 * are exactly the ones the forecast acts on, so the headline "we'd get ~£X" traces to its
 * parts. The trust-critical properties: it reconciles (parts sum to the total), it shows the
 * selling-cost percentage beside the £ figure so an out-of-range rate is visible, and it
 * does not invent a sale where there is none.
 */
final class SaleExplainerTest extends TestCase
{
    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function household(?Money $mortgage = null): Household
    {
        return new Household(
            'Sale',
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

    /** @return array<string, mixed>|null */
    private function explainer(HousingAction $action, ?Money $mortgage = null): ?array
    {
        $comparison = $this->comparison();
        $household = $this->household($mortgage);

        return ResultPresenter::saleExplainer(
            $comparison->saleProceeds($household, $action),
            $comparison->buyOutcome($household, $action),
            $action,
            blendedRealReturn: 0.0176,
            investmentIncomeYield: 0.02,
        );
    }

    public function test_no_sale_configured_returns_null(): void
    {
        // A stay-put plan with no sale price has nothing to explain.
        $this->assertNull($this->explainer(new HousingAction(salePrice: Money::zero())));
    }

    public function test_the_waterfall_reconciles_and_shows_each_component_beside_the_figure(): void
    {
        // A 20% agent rate (~10x typical) must be visible as 20% of the sale, not buried in £.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(20)),
        ]);
        $se = $this->explainer($action, Money::fromPounds(50_000));

        $this->assertNotNull($se);
        $this->assertFalse($se['sellingCostsAssumed']);
        $this->assertSame('Estate agent', $se['sellingCostBreakdown'][0]['label']);
        $this->assertSame('20% of the sale price', $se['sellingCostBreakdown'][0]['detail']);
        $this->assertSame(Money::fromPounds(80_000)->format(), $se['sellingCostBreakdown'][0]['value']);
        $this->assertTrue($se['proceeds']['hasMortgage']);
        // 20% of £400k = £80,000 costs; net = 400,000 − 50,000 (mortgage) − 80,000 = £270,000.
        $this->assertSame(Money::fromPounds(80_000)->format(), $se['proceeds']['sellingCosts']);
        $this->assertSame(Money::fromPounds(270_000)->format(), $se['proceeds']['netProceeds']);
    }

    public function test_a_default_selling_cost_is_flagged_assumed(): void
    {
        $se = $this->explainer(new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertTrue($se['sellingCostsAssumed']);
        // The default applies the engine's all-in rate, READ from the constant so re-sourcing it
        // moves this expectation with it (4% = £16,000 on £400k).
        $this->assertSame(
            Money::fromPounds(400_000)->applyRate(Percent::fromBasisPoints(HousingProceeds::DEFAULT_SELLING_COST_RATE_BP))->format(),
            $se['proceeds']['sellingCosts'],
        );
    }

    public function test_a_sale_that_triggers_a_sixty_day_return_shows_the_cost_of_preparing_it(): void
    {
        // Card 0032. A UK residential disposal on which CGT is due must be reported and paid inside
        // 60 days, and an accountant prepares that return. It is not optional and it is real money
        // off the proceeds, so it must appear as its own named line the reader can see and argue
        // with — not be folded into conveyancing, and not be left out as it was.
        $comparison = $this->comparison();
        $household = new Household(
            'Sale',
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
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(1.5)),
        ]);
        $se = ResultPresenter::saleExplainer(
            $comparison->saleProceeds($household, $action),
            $comparison->buyOutcome($household, $action),
            $action,
            blendedRealReturn: 0.0176,
            investmentIncomeYield: 0.02,
        );

        $labels = array_column($se['sellingCostBreakdown'], 'label');
        $this->assertContains(HousingProceeds::CGT_RETURN_LABEL, $labels);

        $line = $se['sellingCostBreakdown'][array_search(HousingProceeds::CGT_RETURN_LABEL, $labels, true)];
        // The pounds shown are READ from the constant the engine charges, never restated here.
        $this->assertSame(Money::fromPence(HousingProceeds::CGT_RETURN_FEE_PENCE)->format(), $line['value']);
        $this->assertNull($line['detail'], 'a flat accountant fee is not a percentage of the sale');
        $this->assertTrue($se['proceeds']['cgtCharged']);
    }

    public function test_a_flat_fee_component_shows_no_percentage_detail(): void
    {
        // A flat fee is not a % of the sale, so it carries no "% of the sale price" detail.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), sellingCosts: [
            new SellingCostComponent('Estate agent', Percent::fromPercent(1.25)),
            new SellingCostComponent('Legal / conveyancing', Money::fromPounds(1_500)),
        ]);
        $se = $this->explainer($action);

        $this->assertFalse($se['sellingCostsAssumed']);
        $this->assertSame('1.25% of the sale price', $se['sellingCostBreakdown'][0]['detail']);
        $this->assertNull($se['sellingCostBreakdown'][1]['detail']);
        $this->assertSame(Money::fromPounds(1_500)->format(), $se['sellingCostBreakdown'][1]['value']);
        // £5,000 agent + £1,500 legal = £6,500 total.
        $this->assertSame(Money::fromPounds(6_500)->format(), $se['proceeds']['sellingCosts']);
    }

    public function test_the_rent_destination_invests_the_full_net_proceeds(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), annualRent: Money::fromPounds(14_000));
        $se = $this->explainer($action);

        // No mortgage, 4% costs: net = 400,000 − 16,000 = £384,000, all of it invested.
        $this->assertSame(Money::fromPounds(384_000)->format(), $se['rent']['invested']);
        $this->assertSame($se['proceeds']['netProceeds'], $se['rent']['invested']);
        $this->assertSame(Money::fromPounds(14_000)->format(), $se['rent']['annualRent']);
    }

    public function test_the_buy_destination_shows_only_with_a_buy_price_and_reconciles(): void
    {
        // Rent-only plan: no buy block.
        $this->assertNull($this->explainer(new HousingAction(salePrice: Money::fromPounds(400_000)))['buy']);

        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000));
        $se = $this->explainer($action);

        $this->assertNotNull($se['buy']);
        // Net £384,000 − buy £200,000 − SDLT £1,500 − moving £2,000 = surplus £180,500.
        $this->assertSame(Money::fromPounds(180_500)->format(), $se['buy']['surplus']);
        $this->assertTrue($se['buy']['coversPurchase']);
        $this->assertTrue($se['buy']['isFullyFunded']);
        $this->assertNull($se['buy']['fundedFromSavings']); // proceeds alone cover it
        $this->assertNull($se['buy']['mortgage']);
        $this->assertNull($se['buy']['unfundedGap']); // covered → no feasibility flag
    }

    public function test_a_buy_price_above_the_net_proceeds_reports_the_unfunded_gap(): void
    {
        // A big mortgage leaves little net; the home bought still costs more than that frees.
        // Net = 400,000 − 350,000 (mortgage) − 16,000 (4%) = £34,000. Buy £200k + £1,500 SDLT +
        // £2,000 moving = £203,500 → £169,500 short. With no savings and no buy mortgage, the
        // whole gap is UNFUNDED, surfaced so the plan visibly fails rather than silently
        // "buying" a home it cannot pay for.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000));
        $se = $this->explainer($action, Money::fromPounds(350_000));

        $this->assertFalse($se['buy']['coversPurchase']);
        $this->assertFalse($se['buy']['isFullyFunded']);
        $this->assertSame(Money::fromPounds(0)->format(), $se['buy']['surplus']);
        $this->assertSame(Money::fromPounds(169_500)->format(), $se['buy']['unfundedGap']);
    }

    public function test_a_buy_funded_from_savings_and_a_mortgage_shows_both_sources(): void
    {
        // £60k of cash savings + a 6% RIO: the £169,500 gap is funded £60k from savings first,
        // the £109,500 remainder borrowed. Both shown, nothing unfunded.
        $comparison = $this->comparison();
        $household = new Household(
            'Sale',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(60_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(350_000),
            ),
        );
        $action = new HousingAction(
            salePrice: Money::fromPounds(400_000),
            buyPrice: Money::fromPounds(200_000),
            buyMortgageRate: Percent::fromPercent(6),
        );
        $se = ResultPresenter::saleExplainer(
            $comparison->saleProceeds($household, $action),
            $comparison->buyOutcome($household, $action),
            $action,
            blendedRealReturn: 0.0176,
            investmentIncomeYield: 0.02,
        );

        $this->assertTrue($se['buy']['isFullyFunded']);
        $this->assertSame(Money::fromPounds(60_000)->format(), $se['buy']['fundedFromSavings']);
        $this->assertSame(Money::fromPounds(109_500)->format(), $se['buy']['mortgage']);
        $this->assertSame(Money::fromPounds(109_500)->applyRate(Percent::fromPercent(6))->format(), $se['buy']['mortgageInterest']);
        $this->assertNull($se['buy']['unfundedGap']);
    }

    public function test_the_blended_return_and_income_yield_are_shown_as_percentages(): void
    {
        $se = $this->explainer(new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertSame('1.76%', $se['blendedReturnPct']);
        $this->assertSame('2%', $se['incomeYieldPct']);
    }
}
