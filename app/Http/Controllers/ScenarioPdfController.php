<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScenarioStatus;
use App\Enums\SimulationStatus;
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
        // One deterministic central projection feeds the ladder + income floor, exactly as
        // the results page does, so the printed figures match the screen.
        $forecast = app(ScenarioForecaster::class)->deterministic($scenario);

        return [
            'scenario' => $scenario,
            'generatedAt' => now()->format('j F Y'),
            'shock' => app(LumpSumTaxShock::class)->assess($scenario),
            'budget' => ResultPresenter::expenseBreakdown($scenario->effectiveBuilderState()),
            'plsa' => ResultPresenter::plsaBenchmark($scenario->toHousehold()),
            'incomeFloor' => ResultPresenter::incomeFloor($forecast),
            'ladder' => ResultPresenter::ladder($forecast),
            // Monte Carlo headline summary, only if a completed run exists.
            'presented' => $this->monteCarloSummary($scenario),
        ];
    }

    /** @return array<string, mixed>|null */
    private function monteCarloSummary(Scenario $scenario): ?array
    {
        $run = $scenario->simulationRuns()
            ->where('status', SimulationStatus::Done)
            ->latest()
            ->first();

        if (! $run instanceof SimulationRun) {
            return null;
        }

        $resultsByVariant = $run->load('results')->results
            ->keyBy(fn (Result $r): string => $r->variant->value);

        return ResultPresenter::build($resultsByVariant, $scenario->variant->value);
    }
}
