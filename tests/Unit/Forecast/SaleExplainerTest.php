<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
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
        // The default applies the engine's 2% → £8,000 on £400k.
        $this->assertSame(Money::fromPounds(8_000)->format(), $se['proceeds']['sellingCosts']);
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

        // No mortgage, 2% costs: net = 400,000 − 8,000 = £392,000 — all of it invested.
        $this->assertSame(Money::fromPounds(392_000)->format(), $se['rent']['invested']);
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
        // Net £392,000 − buy £200,000 − SDLT £1,500 − moving £2,000 = surplus £188,500.
        $this->assertSame(Money::fromPounds(188_500)->format(), $se['buy']['surplus']);
        $this->assertTrue($se['buy']['coversPurchase']);
        $this->assertNull($se['buy']['shortfall']); // covered → no feasibility flag
    }

    public function test_a_buy_price_above_the_net_proceeds_is_flagged_as_a_shortfall(): void
    {
        // A big mortgage leaves little net; the cheaper home still costs more than that frees.
        // Net = 400,000 − 350,000 (mortgage) − 8,000 (2%) = £42,000. Buy £200k + £1,500 SDLT +
        // £2,000 moving = £203,500 → £161,500 short. The surplus is floored at £0, so the plan
        // must flag it rather than silently "buy" a home it cannot afford from the sale.
        $action = new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000));
        $se = $this->explainer($action, Money::fromPounds(350_000));

        $this->assertFalse($se['buy']['coversPurchase']);
        $this->assertSame(Money::fromPounds(0)->format(), $se['buy']['surplus']);
        $this->assertSame(Money::fromPounds(161_500)->format(), $se['buy']['shortfall']);
    }

    public function test_the_blended_return_and_income_yield_are_shown_as_percentages(): void
    {
        $se = $this->explainer(new HousingAction(salePrice: Money::fromPounds(400_000)));

        $this->assertSame('1.76%', $se['blendedReturnPct']);
        $this->assertSame('2%', $se['incomeYieldPct']);
    }
}
