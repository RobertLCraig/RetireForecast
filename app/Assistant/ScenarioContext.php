<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;

/**
 * The bounded, labelled snapshot of a scenario's central (deterministic) forecast that the
 * assistant reasons over. It is the SINGLE source for two things at once: the context shown to
 * the model AND the grounding allow-list its answer is checked against ({@see promptBlock()}) —
 * so the model can only be given, and can only legitimately state, exactly these engine figures
 * (guardrail G1).
 *
 * A big part of the point is to let the reader interrogate figures the UI does NOT spell out —
 * "how much are my essentials in five years?", "what's my tax in 2035?" — so the snapshot carries
 * BOTH the headline summary AND the full year-by-year cashflow ladder (the same reconciled rows
 * the ladder panel shows). Every per-year figure is inline-labelled, so the model states the right
 * number for the right thing (mitigating the right-number-wrong-meaning risk, gotcha LA-8). Still
 * additive: the tax shock, sale waterfall and Monte Carlo probabilities are further fast-follows.
 */
final class ScenarioContext
{
    /**
     * @param  list<AssistantFact>  $facts
     */
    private function __construct(
        public readonly string $title,
        public readonly array $facts,
        public readonly string $ladder = '',
    ) {}

    /** Build the context by running the scenario's central deterministic forecast. */
    public static function for(Scenario $scenario, ScenarioForecaster $forecaster): self
    {
        return self::fromForecast(
            $scenario->name,
            ResultPresenter::strategyLabel($scenario->variant->value),
            $forecaster->deterministic($scenario),
        );
    }

    /**
     * Build the headline facts from a forecast result. Pure — no container, no I/O — so it is
     * unit-testable from a hand-built {@see ForecastResult}.
     */
    public static function fromForecast(string $title, string $strategyLabel, ForecastResult $forecast): self
    {
        $facts = [
            new AssistantFact('Plan', $title),
            new AssistantFact('Housing strategy', $strategyLabel),
        ];

        $facts[] = $forecast->depletionCalendarYear === null
            ? new AssistantFact('Does the money last', "Yes — it lasts to {$forecast->finalCalendarYear}")
            : new AssistantFact('Does the money last', "No — it runs short in {$forecast->depletionCalendarYear}");

        $facts[] = new AssistantFact('Final year of the plan', (string) $forecast->finalCalendarYear);
        $facts[] = new AssistantFact('Spendable wealth left at the end (excludes the home)', $forecast->terminalUsableWealth->format());
        $facts[] = new AssistantFact('Total wealth left at the end (includes the home)', $forecast->terminalTotalWealth->format());
        $facts[] = new AssistantFact('Essential spending funded every year', $forecast->essentialsAlwaysMet ? 'Yes' : 'No');
        $facts[] = new AssistantFact('Full (essential + discretionary) spending funded every year', $forecast->fullSpendAlwaysMet ? 'Yes' : 'No');

        foreach ($forecast->deathCalendarYears as $personId => $year) {
            $facts[] = new AssistantFact("Modelled year of death (person {$personId})", (string) $year);
        }

        $care = $forecast->careCostReal();
        if (! $care->isZero()) {
            $facts[] = new AssistantFact('Modelled late-life care cost on this path (today\'s money)', $care->format());
        }

        return new self($title, $facts, self::renderLadder($forecast));
    }

    /**
     * Render the facts + the year-by-year ladder as one block. This is BOTH the context shown to
     * the model and the grounding source {@see FigureGrounding} checks its answer against — one
     * home, so the model can only state figures it was actually shown here (plus any in the
     * reader's question).
     */
    public function promptBlock(): string
    {
        $block = implode("\n", array_map(
            static fn (AssistantFact $f): string => "- {$f->label}: {$f->value}",
            $this->facts,
        ));

        return $this->ladder === '' ? $block : $block."\n\n".$this->ladder;
    }

    /**
     * The full projection, one line per year, every figure inline-labelled — so any per-year
     * question resolves to a figure the model was actually given (and can't mislabel). Reuses the
     * reconciled {@see ResultPresenter::ladder()} rows, so the assistant's per-year figures are the
     * same numbers the ladder panel displays (provenance). Empty when there are no years.
     */
    private static function renderLadder(ForecastResult $forecast): string
    {
        $ladder = ResultPresenter::ladder($forecast);
        if ($ladder['rows'] === []) {
            return '';
        }

        $labels = $ladder['sourceLabels'];
        $lines = [];

        foreach ($ladder['rows'] as $row) {
            $income = [];
            foreach ($row['income'] as $source => $value) {
                if ($value !== '£0.00') { // only the sources that actually paid out that year
                    $income[] = ($labels[$source] ?? $source).' '.$value;
                }
            }
            $incomePart = $income === [] ? '' : ' income ('.implode(', ', $income).');';
            $growthPart = ($ladder['showGrowth'] && $row['investmentGrowth'] !== '£0.00') ? " investment growth {$row['investmentGrowth']};" : '';
            $shortfallPart = $row['shortfall'] !== null ? " shortfall {$row['shortfall']};" : '';
            $ages = $row['ages'] !== '' ? " (age {$row['ages']})" : '';

            $lines[] = "{$row['year']}{$ages}: total spend {$row['spend']} (essentials {$row['essentialSpend']}, discretionary {$row['discretionarySpend']});{$incomePart} tax {$row['tax']};{$growthPart}{$shortfallPart} spendable wealth {$row['usableWealth']}, total wealth {$row['totalWealth']}.";
        }

        return "YEAR-BY-YEAR (real terms, in today's money) — the full projection, so you can answer questions about any specific year:\n".implode("\n", $lines);
    }
}
