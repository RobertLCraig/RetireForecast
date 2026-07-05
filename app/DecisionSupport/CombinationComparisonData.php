<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * Assembles the plan set the combination-comparison surface (and its CSV) work from, so the
 * Compare page and the CSV download can never disagree on which plans are compared or which
 * figures they carry. One home for "the base plus its ready what-ifs, each with its central
 * deterministic forecast and its latest completed Monte Carlo result".
 *
 * The deterministic forecast is projected on the plan's OWN housing strategy (the same
 * per-variant single source the Compare table and the results-page ladder use), and the Monte
 * Carlo result is read from the plan's own chosen variant — so an unsimulated plan comes back
 * with a null result rather than a borrowed one.
 */
final class CombinationComparisonData
{
    /** The compared plans: the base first, then its ready what-if children (newest first). */
    public static function plans(Scenario $base): Collection
    {
        return collect([$base])->concat(
            $base->children()->where('status', ScenarioStatus::Ready)->latest()->get(),
        );
    }

    /**
     * The full trio per plan: the scenario, its central deterministic forecast (on its own
     * variant), and its latest completed Monte Carlo result (null when it has not been run).
     *
     * @return list<array{scenario: Scenario, forecast: ForecastResult, mc: ?SimulationResult}>
     */
    public static function assemble(Scenario $base, ScenarioForecaster $forecaster): array
    {
        return self::plans($base)->map(static function (Scenario $plan) use ($forecaster): array {
            $forecast = $forecaster->deterministicVariants($plan)[$plan->variant->value];

            $run = $plan->latestCompletedRun();
            $mc = null;
            if ($run !== null) {
                $result = $run->results->firstWhere(fn ($r): bool => $r->variant === $plan->variant) ?? $run->results->first();
                $mc = $result?->simulationResult();
            }

            return ['scenario' => $plan, 'forecast' => $forecast, 'mc' => $mc];
        })->all();
    }
}
