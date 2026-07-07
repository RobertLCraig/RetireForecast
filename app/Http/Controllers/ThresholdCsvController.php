<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DecisionSupport\FrontierCsvExporter;
use App\DecisionSupport\ThresholdCsvExporter;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a computed decision-support lever threshold as CSV (disclaimer + provenance +
 * crossing + the swept grid — see {@see ThresholdCsvExporter}), or, for a 2-D frontier
 * record, the trade-off map (per-column crossings + every measured cell — see
 * {@see FrontierCsvExporter}). Owner-scoped, and the threshold must belong to the scenario
 * in the URL, so a tampered id cannot reach another user's figures.
 */
class ThresholdCsvController extends Controller
{
    public function download(Scenario $scenario, ThresholdResult $threshold): StreamedResponse
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        abort_unless($threshold->scenario_id === $scenario->id, 404);

        $isFrontier = $threshold->isFrontier();
        $rows = $isFrontier ? FrontierCsvExporter::rows($threshold) : ThresholdCsvExporter::rows($threshold);
        $filename = $isFrontier
            ? "frontier-{$threshold->lever_key}-by-{$threshold->condition_lever_key}.csv"
            : "threshold-{$threshold->lever_key}.csv";

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
