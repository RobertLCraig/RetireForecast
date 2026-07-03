<?php

declare(strict_types=1);

namespace App\Assistant;

use RetireForecast\FinanceEngine\Forecast\ForecastResult;

/**
 * The assistant's world on the Compare page: several plans (a base and its what-ifs) laid out side
 * by side so the model can answer comparison questions — "which plan leaves the most?", "which run
 * short?", "how do they differ?". Like {@see ScenarioContext} it is the SINGLE source of both the
 * model's context and the grounding allow-list ({@see promptBlock()}), built from the SAME per-plan
 * deterministic forecasts the comparison table shows (provenance — the assistant's figures are the
 * table's).
 *
 * Deterministic only (one central projection per plan, matching the Compare page); it carries no
 * Monte Carlo, so {@see includesMonteCarlo()} is false. The model compares by READING the figures —
 * it is told (via {@see systemIntro()}) to name the plan(s) and never to do arithmetic across plans
 * (state each plan's figure, not their difference), so a compared figure is always grounded, never
 * an invented delta (guardrail G1).
 */
final class ComparisonContext implements AssistantContext
{
    /**
     * @param  list<string>  $planBlocks  one labelled block per plan
     */
    private function __construct(private readonly array $planBlocks) {}

    /**
     * @param  list<array{name: string, variant: string, forecast: ForecastResult, changes?: string}>  $plans
     *                                                                                                         base first, then its what-ifs
     */
    public static function fromPlans(array $plans): self
    {
        $blocks = array_map(
            static fn (array $p): string => self::planBlock($p['name'], $p['variant'], $p['forecast'], $p['changes'] ?? ''),
            $plans,
        );

        return new self(array_values($blocks));
    }

    private static function planBlock(string $name, string $variant, ForecastResult $forecast, string $changes): string
    {
        $lines = ["PLAN: {$name} (housing strategy: {$variant})"];

        if ($changes !== '') {
            $lines[] = "- How it differs from the base plan: {$changes}";
        }

        $lines[] = $forecast->depletionCalendarYear === null
            ? "- Does the money last: Yes, it lasts to {$forecast->finalCalendarYear}"
            : "- Does the money last: No, it runs short in {$forecast->depletionCalendarYear}";
        $lines[] = '- Essential spending funded every year: '.($forecast->essentialsAlwaysMet ? 'Yes' : 'No');
        $lines[] = '- Full (essential + discretionary) spending funded every year: '.($forecast->fullSpendAlwaysMet ? 'Yes' : 'No');
        $lines[] = '- Spendable wealth left at the end (excludes the home): '.$forecast->terminalUsableWealth->format();
        $lines[] = '- Total wealth left at the end (includes the home): '.$forecast->terminalTotalWealth->format();

        return implode("\n", $lines);
    }

    public function promptBlock(): string
    {
        return "COMPARISON — the plans being compared, side by side (one central deterministic projection each):\n\n"
            .implode("\n\n", $this->planBlocks);
    }

    public function includesMonteCarlo(): bool
    {
        return false;
    }

    public function systemIntro(): string
    {
        return 'You help the reader COMPARE several plans (a base plan and its what-ifs), shown under CONTEXT below. '
            .'When asked which plan does better on some measure (lasts longest, leaves the most, covers essentials), '
            .'compare the plans\' figures and name the plan or plans; never invent a figure that is not shown, and do '
            .'not do arithmetic across plans (state each plan\'s own figure rather than the difference between them).';
    }
}
