<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\ScenarioContext;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
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

    public function test_care_cost_appears_only_when_modelled(): void
    {
        $withCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058, [], Money::fromPence(5_000_000));
        $this->assertStringContainsString('£50,000.00', ScenarioContext::fromForecast('P', 'Stay put', $withCare)->promptBlock());

        $noCare = new ForecastResult([], true, true, null, Money::fromPence(1), Money::fromPence(1), 2058);
        $this->assertStringNotContainsString('care cost', ScenarioContext::fromForecast('P', 'Stay put', $noCare)->promptBlock());
    }
}
