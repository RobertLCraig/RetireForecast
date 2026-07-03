<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;

/**
 * The bounded, labelled snapshot of a scenario's central (deterministic) forecast that the
 * assistant reasons over. It is the SINGLE source for two things at once:
 *
 *   1. the context block shown to the model ({@see promptBlock()}), and
 *   2. the grounding allow-list its answer is checked against ({@see groundedValues()}).
 *
 * Both come from the same {@see AssistantFact} list, so the model can only be given, and can
 * only legitimately state, exactly these figures — every number is engine-derived, none is
 * the model's own (guardrail G1). The figures are the deterministic {@see ForecastResult}
 * headline; richer sources (the tax shock, the sale waterfall, Monte Carlo probabilities)
 * are additive fast-follows that simply append more facts.
 */
final class ScenarioContext
{
    /**
     * @param  list<AssistantFact>  $facts
     */
    private function __construct(
        public readonly string $title,
        public readonly array $facts,
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

        return new self($title, $facts);
    }

    /**
     * Render the facts as a labelled block. This is BOTH the context shown to the model and
     * the grounding source {@see FigureGrounding} checks its answer against — one home, so the
     * model can only state figures it was actually shown here (plus any in the reader's question).
     */
    public function promptBlock(): string
    {
        return implode("\n", array_map(
            static fn (AssistantFact $f): string => "- {$f->label}: {$f->value}",
            $this->facts,
        ));
    }
}
