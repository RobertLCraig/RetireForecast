<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;

/**
 * The compare-assumptions overlay: the scenario's central (best-estimate) projection run
 * once under each shipped, sourced assumption set, so the user can see how sensitive the
 * outcome is to the assumptions rather than reading a single number as certainty.
 *
 * This is deterministic (no Monte Carlo), so it renders immediately — like the lump-sum
 * panel — without waiting for a simulation run. It illustrates consequences under
 * different sourced assumptions; it does not rank or recommend a set.
 */
final class AssumptionComparison
{
    public function __construct(private readonly ScenarioForecaster $forecaster = new ScenarioForecaster) {}

    /**
     * One row per shipped assumption set, default first.
     *
     * @return list<array<string, mixed>>
     */
    public function compare(Scenario $scenario): array
    {
        $rows = [];

        foreach (AssumptionSetLibrary::all() as $set) {
            $result = $this->forecaster->deterministicWith($scenario, $set);

            $rows[] = [
                'name' => $set->name,
                'sourceNote' => $set->sourceNote,
                'essentialsMet' => $result->essentialsAlwaysMet,
                'fullSpendMet' => $result->fullSpendAlwaysMet,
                'depletionYear' => $result->depletionCalendarYear,
                'finalYear' => $result->finalCalendarYear,
                'terminalWealth' => $result->terminalTotalWealth->format(),
                'terminalPence' => $result->terminalTotalWealth->pence,
            ];
        }

        return $rows;
    }
}
