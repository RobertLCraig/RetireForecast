<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\FigureGrounding;
use App\Assistant\ScenarioContext;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;

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
            propertyWealth: Money::fromPence(25_000_000),
            totalWealth: Money::fromPence(43_000_000),    // £430,000.00
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

    public function test_care_cost_appears_only_when_modelled(): void
    {
        $withCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058, [], Money::fromPence(5_000_000));
        $this->assertStringContainsString('£50,000.00', ScenarioContext::fromForecast('P', 'Stay put', $withCare)->promptBlock());

        $noCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);
        $this->assertStringNotContainsString('care cost', ScenarioContext::fromForecast('P', 'Stay put', $noCare)->promptBlock());
    }
}
