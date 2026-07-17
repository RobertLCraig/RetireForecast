<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\FigureGrounding;
use App\Assistant\ScenarioContext;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * The context is the assistant's whole world: the figures shown to the model and the ones it
 * may state are the same list, built from the engine's {@see ForecastResult}. These pin that
 * the headline facts (does the money last, wealth left, death years, care) reach the prompt
 * block verbatim — so the model is shown, and grounded against, exactly the engine's numbers.
 */
final class ScenarioContextTest extends TestCase
{
    public function test_the_headline_forecast_facts_reach_the_prompt_block(): void
    {
        $forecast = new ForecastResult(
            years: [],
            essentialsAlwaysMet: true,
            fullSpendAlwaysMet: false,
            depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPence(20_000_000),   // £200,000.00
            terminalUsableWealth: Money::fromPence(15_460_000),  // £154,600.00
            finalCalendarYear: 2058,
            deathCalendarYears: ['p1' => 2049, 'p2' => 2052],
        );

        $block = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast)->promptBlock();

        $this->assertStringContainsString('My Plan', $block);
        $this->assertStringContainsString('Stay put', $block);
        $this->assertStringContainsString('it lasts to 2058', $block);
        $this->assertStringContainsString('£154,600.00', $block);
        $this->assertStringContainsString('£200,000.00', $block);
        $this->assertStringContainsString('2049', $block);
        $this->assertStringContainsString('2052', $block);
        $this->assertStringContainsString('Essential spending funded every year: Yes', $block);
        $this->assertStringContainsString('Full (essential + discretionary) spending funded every year: No', $block);
    }

    public function test_a_depletion_year_is_reported_as_running_short(): void
    {
        $forecast = new ForecastResult(
            years: [],
            essentialsAlwaysMet: false,
            fullSpendAlwaysMet: false,
            depletionCalendarYear: 2041,
            terminalTotalWealth: Money::zero(),
            terminalUsableWealth: Money::zero(),
            finalCalendarYear: 2058,
        );

        $block = ScenarioContext::fromForecast('Rent', 'Sell and rent', $forecast)->promptBlock();

        $this->assertStringContainsString('it runs short in 2041', $block);
    }

    public function test_the_year_by_year_ladder_reaches_the_prompt_and_is_groundable(): void
    {
        $year = new YearResult(
            yearIndex: 0,
            calendarYear: 2030,
            ages: ['p1' => 67, 'p2' => 64],
            aliveCount: 2,
            grossIncome: Money::fromPence(2_680_000),
            totalTax: Money::fromPence(120_000),          // £1,200.00
            netIncome: Money::fromPence(2_560_000),
            spendTarget: Money::fromPence(2_800_000),     // £28,000.00
            essentialSpend: Money::fromPence(2_200_000),  // £22,000.00
            shortfallFunded: Money::zero(),
            unmetSpend: Money::zero(),
            essentialsMet: true,
            liquidWealth: Money::fromPence(18_000_000),   // £180,000.00 spendable
            pensionWealth: Money::zero(),
            propertyWealth: Money::fromPence(25_000_000), // total derives to £430,000.00
            incomeBySource: ['state_pension' => Money::fromPence(1_150_200)], // £11,502.00
        );

        $forecast = new ForecastResult(
            years: [$year],
            essentialsAlwaysMet: true,
            fullSpendAlwaysMet: true,
            depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPence(43_000_000),
            terminalUsableWealth: Money::fromPence(18_000_000),
            finalCalendarYear: 2030,
        );

        $block = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast)->promptBlock();

        // The per-year figures the UI ladder shows are in the context, each inline-labelled.
        $this->assertStringContainsString('2030', $block);
        $this->assertStringContainsString('essentials £22,000.00', $block);
        $this->assertStringContainsString('discretionary £6,000.00', $block); // spend − essentials
        $this->assertStringContainsString('State Pension £11,502.00', $block);
        $this->assertStringContainsString('spendable wealth £180,000.00', $block);

        // So a per-year question ("essentials in five years") is now answerable, not refused.
        $this->assertSame([], FigureGrounding::ungrounded('Your essentials in 2030 are £22,000.00.', $block, ''));
    }

    public function test_monte_carlo_probabilities_and_ranges_reach_the_prompt_and_are_groundable(): void
    {
        $forecast = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);
        $mc = new SimulationResult(
            nPaths: 10000,
            seed: 42,
            successProbabilityEssentials: 0.92,
            successProbabilityFullSpend: 0.78,
            depletionRate: 0.12,
            medianDepletionYear: 2052,
            terminalWealthPercentiles: [
                'p10' => Money::fromPence(30_000_000), 'p25' => Money::fromPence(35_000_000),
                'p50' => Money::fromPence(40_000_000), 'p75' => Money::fromPence(45_000_000),
                'p90' => Money::fromPence(50_000_000),
            ],
            fanChart: [],
            usableWealthPercentiles: [
                'p10' => Money::fromPence(5_000_000), 'p25' => Money::fromPence(8_000_000),
                'p50' => Money::fromPence(12_000_000), 'p75' => Money::fromPence(18_000_000),
                'p90' => Money::fromPence(25_000_000),
            ],
        );

        $context = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast, $mc);
        $block = $context->promptBlock();

        $this->assertTrue($context->hasMonteCarlo);
        $this->assertStringContainsString('78%', $block);            // chance full spend funded
        $this->assertStringContainsString('92%', $block);            // chance essentials funded
        $this->assertStringContainsString('12%', $block);            // chance of running out
        $this->assertStringContainsString('£120,000.00', $block);    // spendable wealth, median (usable p50)
        $this->assertStringContainsString('2052', $block);           // typical depletion year

        // A probability question is now answerable, and grounded.
        $this->assertSame([], FigureGrounding::ungrounded('There is a 78% chance your full spending is funded for life.', $block, ''));
    }

    public function test_without_a_completed_run_the_context_says_probabilities_are_unavailable(): void
    {
        $forecast = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);

        $context = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast);

        $this->assertFalse($context->hasMonteCarlo);
        $this->assertStringContainsString('Not available yet', $context->promptBlock());
    }

    public function test_the_lump_sum_tax_shock_reaches_the_prompt_and_is_groundable(): void
    {
        $forecast = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);

        // The shape App\Forecast\LumpSumTaxShock::assess() returns (already-formatted figures).
        $shock = [
            'kind' => 'UFPLS (uncrystallised lump sum)',
            'ownerLabel' => 'You',
            'atAge' => 60,
            'taxYear' => '2026-27',
            'workingAssumed' => true,
            'otherIncome' => '£20,000.00',
            'gross' => '£100,000.00',
            'taxFree' => '£25,000.00',
            'taxable' => '£75,000.00',
            'taxAtSource' => '£29,000.00',
            'emergencyApplied' => true,
            'marginalTax' => '£17,432.00',
            'overDeduction' => '£11,568.00',
            'hasOverDeduction' => true,
            'reclaimForm' => 'P55',
            'netReceived' => '£71,000.00',
            'mpaaTriggered' => true,
            'warnings' => [],
        ];

        $block = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast, null, $shock)->promptBlock();

        $this->assertStringContainsString('£25,000.00', $block);   // tax-free 25%
        $this->assertStringContainsString('£17,432.00', $block);   // marginal tax due
        $this->assertStringContainsString('£11,568.00', $block);   // Month-1 emergency over-deduction
        $this->assertStringContainsString('P55', $block);          // reclaim form

        $this->assertSame([], FigureGrounding::ungrounded('You can reclaim £11,568.00 using form P55.', $block, ''));
    }

    public function test_the_home_sale_waterfall_reaches_the_prompt_and_is_groundable(): void
    {
        $forecast = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);

        // The shape App\Forecast\ResultPresenter::saleExplainer() returns for a "sell & buy cheaper" plan.
        $sale = [
            'sellingCostsAssumed' => false,
            'sellingCostBreakdown' => [],
            'cgtDetail' => null,
            'proceeds' => [
                'salePrice' => '£400,000.00', 'mortgage' => '£118,000.00', 'hasMortgage' => true,
                'sellingCosts' => '£8,000.00', 'cgt' => '£0.00', 'cgtCharged' => false,
                'netProceeds' => '£274,000.00', 'clearsCosts' => true,
            ],
            'rent' => ['invested' => '£274,000.00', 'annualRent' => null],
            'buy' => [
                'netProceeds' => '£274,000.00', 'buyPrice' => '£165,000.00', 'sdlt' => '£800.00',
                'movingCosts' => '£1,500.00', 'surplus' => '£106,700.00', 'coversPurchase' => true,
                'isFullyFunded' => true, 'fundedFromSavings' => null, 'mortgage' => null,
                'mortgageInterest' => null, 'unfundedGap' => null,
            ],
            'blendedReturnPct' => '3.5%', 'incomeYieldPct' => '2.0%',
        ];

        $block = ScenarioContext::fromForecast('My Plan', 'Sell and buy cheaper', $forecast, null, null, $sale)->promptBlock();

        $this->assertStringContainsString('£274,000.00', $block);   // net proceeds pocketed
        $this->assertStringContainsString('£165,000.00', $block);   // cheaper home price
        $this->assertStringContainsString('£106,700.00', $block);   // surplus left to invest

        $this->assertSame([], FigureGrounding::ungrounded('You pocket £274,000.00 and have £106,700.00 to invest after buying.', $block, ''));
    }

    public function test_the_purchase_funding_waterfall_reaches_the_prompt_including_an_unfunded_gap(): void
    {
        $forecast = new ForecastResult([], true, false, 2026, Money::fromPence(1), Money::fromPence(1), 2058);

        // A buy above the proceeds: part savings-funded, part mortgaged, part UNFUNDED — each
        // documented source (and the failure) must be a groundable fact the assistant can cite.
        $sale = [
            'sellingCostsAssumed' => false,
            'sellingCostBreakdown' => [],
            'cgtDetail' => null,
            'proceeds' => [
                'salePrice' => '£400,000.00', 'mortgage' => '£118,000.00', 'hasMortgage' => true,
                'sellingCosts' => '£8,000.00', 'cgt' => '£0.00', 'cgtCharged' => false,
                'netProceeds' => '£274,000.00', 'clearsCosts' => true,
            ],
            'rent' => ['invested' => '£274,000.00', 'annualRent' => null],
            'buy' => [
                'netProceeds' => '£274,000.00', 'buyPrice' => '£500,000.00', 'sdlt' => '£15,000.00',
                'movingCosts' => '£1,500.00', 'surplus' => '£0.00', 'coversPurchase' => false,
                'isFullyFunded' => false, 'fundedFromSavings' => '£60,000.00', 'mortgage' => '£100,000.00',
                'mortgageInterest' => '£6,000.00', 'unfundedGap' => '£82,500.00',
            ],
            'blendedReturnPct' => '3.5%', 'incomeYieldPct' => '2.0%',
        ];

        $block = ScenarioContext::fromForecast('My Plan', 'Sell and buy', $forecast, null, null, $sale)->promptBlock();

        $this->assertStringContainsString('£60,000.00', $block);       // savings drawn
        $this->assertStringContainsString('£100,000.00', $block);      // mortgage taken
        $this->assertStringContainsString('£82,500.00', $block);       // the unfunded gap
        $this->assertStringContainsString('UNFUNDED', $block);          // named as a failure, not a source

        $this->assertSame([], FigureGrounding::ungrounded('£82,500.00 of the purchase is unfunded, with £60,000.00 drawn from savings.', $block, ''));
    }

    public function test_care_cost_appears_only_when_modelled(): void
    {
        $withCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058, [], Money::fromPence(5_000_000));
        $this->assertStringContainsString('£50,000.00', ScenarioContext::fromForecast('P', 'Stay put', $withCare)->promptBlock());

        $noCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);
        $this->assertStringNotContainsString('care cost', ScenarioContext::fromForecast('P', 'Stay put', $noCare)->promptBlock());
    }
}
