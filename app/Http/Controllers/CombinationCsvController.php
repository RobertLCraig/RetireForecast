<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DecisionSupport\CombinationComparison;
use App\DecisionSupport\CombinationComparisonData;
use App\DecisionSupport\CombinationCsvExporter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the decision-support combination comparison as CSV (disclaimer + one row per compared
 * plan with its Monte Carlo figures — see {@see CombinationCsvExporter}). Owner-scoped, and
 * base-centric like the Compare page: given a child, it exports the child's whole family, so the
 * download always matches the on-screen comparison.
 */
class CombinationCsvController extends Controller
{
    public function download(Scenario $scenario): StreamedResponse
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        $base = $scenario->baseScenario();
        $plans = CombinationComparisonData::assemble($base, app(ScenarioForecaster::class));
        $rows = CombinationCsvExporter::rows(CombinationComparison::build($plans));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "compare-futures-{$base->id}.csv", ['Content-Type' => 'text/csv']);
    }
}
