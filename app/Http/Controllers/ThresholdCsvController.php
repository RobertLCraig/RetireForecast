<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DecisionSupport\ThresholdCsvExporter;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a computed decision-support lever threshold as CSV (disclaimer + provenance +
 * crossing + the swept grid — see {@see ThresholdCsvExporter}). Owner-scoped, and the
 * threshold must belong to the scenario in the URL, so a tampered id cannot reach another
 * user's figures.
 */
class ThresholdCsvController extends Controller
{
    public function download(Scenario $scenario, ThresholdResult $threshold): StreamedResponse
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        abort_unless($threshold->scenario_id === $scenario->id, 404);

        $rows = ThresholdCsvExporter::rows($threshold);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "threshold-{$threshold->lever_key}.csv", ['Content-Type' => 'text/csv']);
    }
}
