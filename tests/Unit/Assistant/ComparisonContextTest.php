<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\ComparisonContext;
use App\Assistant\FigureGrounding;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The comparison context is the assistant's world on the Compare page: each compared plan's
 * deterministic headline figures, so the model can answer "which lasts longest / leaves the most?".
 * These pin that every plan reaches the prompt block (so it is both shown and groundable), a
 * depletion reads as running short, and a comparison answer citing the plans' own figures grounds.
 */
final class ComparisonContextTest extends TestCase
{
    public function test_each_plan_reaches_the_prompt_and_comparison_figures_are_groundable(): void
    {
        $stay = new ForecastResult(
            years: [], essentialsAlwaysMet: true, fullSpendAlwaysMet: false, depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPence(25_000_000), terminalUsableWealth: Money::fromPence(376_734), finalCalendarYear: 2058,
        );
        $rent = new ForecastResult(
            years: [], essentialsAlwaysMet: true, fullSpendAlwaysMet: true, depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPence(30_000_000), terminalUsableWealth: Money::fromPence(1_200_000), finalCalendarYear: 2062,
        );

        $context = ComparisonContext::fromPlans([
            ['name' => 'Stay put', 'variant' => 'Stay put', 'forecast' => $stay],
            ['name' => 'Sell & rent', 'variant' => 'Sell and rent', 'forecast' => $rent, 'changes' => 'Housing strategy Stay put → Sell and rent'],
        ]);
        $block = $context->promptBlock();

        $this->assertStringContainsString('PLAN: Stay put', $block);
        $this->assertStringContainsString('PLAN: Sell & rent', $block);
        $this->assertStringContainsString('lasts to 2058', $block);
        $this->assertStringContainsString('lasts to 2062', $block);
        $this->assertStringContainsString('£3,767.34', $block);   // stay-put spendable left
        $this->assertStringContainsString('£12,000.00', $block);  // sell-&-rent spendable left
        $this->assertStringContainsString('Housing strategy Stay put → Sell and rent', $block);
        $this->assertFalse($context->includesMonteCarlo());

        // A comparison answer that cites each plan's own figures is grounded (no invented delta).
        $this->assertSame([], FigureGrounding::ungrounded(
            'Sell & rent leaves £12,000.00 at the end; Stay put leaves £3,767.34.', $block, '',
        ));
    }

    public function test_a_depletion_year_reads_as_running_short(): void
    {
        $forecast = new ForecastResult(
            years: [], essentialsAlwaysMet: false, fullSpendAlwaysMet: false, depletionCalendarYear: 2041,
            terminalTotalWealth: Money::zero(), terminalUsableWealth: Money::zero(), finalCalendarYear: 2058,
        );

        $block = ComparisonContext::fromPlans([
            ['name' => 'Retire early', 'variant' => 'Stay put', 'forecast' => $forecast],
        ])->promptBlock();

        $this->assertStringContainsString('runs short in 2041', $block);
    }
}
