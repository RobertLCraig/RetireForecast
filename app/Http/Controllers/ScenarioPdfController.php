<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScenarioStatus;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a scenario's results as a downloadable PDF summary. The figures are built
 * from the SAME ResultPresenter the on-screen results page uses, so the printed report
 * cannot drift from what the user saw (the displayed-figure provenance rule). The core
 * report is deterministic, so it is always available without a Monte Carlo run; if a
 * completed run exists, its headline summary is added.
 */
class ScenarioPdfController extends Controller
{
    public function download(Scenario $scenario): Response
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        // A draft has no runnable result; there is nothing to print.
        abort_if($scenario->status === ScenarioStatus::Draft, 404);

        $pdf = Pdf::loadView('pdf.results', $this->data($scenario));

        return $pdf->download("retireforecast-scenario-{$scenario->id}.pdf");
    }

    /**
     * Assemble the report data. Public so the view-render test exercises the exact data
     * the controller produces (no second, drift-prone assembly in the test).
     *
     * @return array<string, mixed>
     */
    public function data(Scenario $scenario): array
    {
        // Deterministic projections, exactly as the results page does, so the printed figures
        // match the screen: the cashflow ladder follows the scenario's own chosen strategy (the
        // page's default), while the income floor reads the raw (stay-put) household.
        $variants = app(ScenarioForecaster::class)->deterministicVariants($scenario);
        $ladderForecast = $variants[$scenario->variant->value] ?? $variants['stay_put'];
        $forecast = $variants['stay_put'];

        // The SAME run the results page presents (latest completed), so the PDF can never
        // print a Monte Carlo summary the screen is hiding.
        [$presented, $mcRun] = $this->monteCarlo($scenario);

        return [
            'scenario' => $scenario,
            'generatedAt' => now()->format('j F Y'),
            'shock' => app(LumpSumTaxShock::class)->assess($scenario),
            'budget' => ResultPresenter::expenseBreakdown($scenario->effectiveBuilderState()),
            'plsa' => ResultPresenter::plsaBenchmark($scenario->toHousehold()),
            'incomeFloor' => ResultPresenter::incomeFloor($forecast),
            'ladder' => ResultPresenter::ladder($ladderForecast, $scenario->safetyBufferMonths()),
            // Monte Carlo headline summary + the run's provenance, only if a completed run
            // exists, so a 1,000-path preview can't masquerade as the 10k report.
            'presented' => $presented,
            'mcRun' => $mcRun,
        ];
    }

    /**
     * The Monte Carlo summary + the producing run's provenance, from the scenario's
     * latest completed run (the one source the results page also reads).
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function monteCarlo(Scenario $scenario): array
    {
        $run = $scenario->latestCompletedRun();

        if (! $run instanceof SimulationRun) {
            return [null, null];
        }

        $resultsByVariant = $run->results
            ->keyBy(fn (Result $r): string => $r->variant->value);

        $presented = ResultPresenter::build($resultsByVariant, $scenario->variant->value);

        $mcRun = [
            'mode' => $run->mode->value,
            'paths' => $run->n_paths,
            'seed' => $run->seed,
            'date' => $run->updated_at?->format('j F Y'),
        ];

        return [$presented, $mcRun];
    }
}
