<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScenarioStatus;
use App\Export\ScenarioExport;
use App\Export\ScenarioReport;
use App\Models\Scenario;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a scenario's results as a downloadable PDF summary.
 *
 * The report is a COMPLETE print of the results page, not a digest: every section the screen
 * renders is assembled by {@see ScenarioReport} from the SAME ResultPresenter calls the
 * Livewire component makes, so the printed report cannot drift from what the user saw (the
 * displayed-figure provenance rule), and nothing the reader relied on is silently missing when
 * they share the PDF with family or an adviser. The ScenarioPdfTest feature test holds a
 * per-section completeness guard — it reads the screen's own view data and fails when a section
 * the results page renders is not also exported here — so a new screen section cannot quietly
 * skip the print.
 *
 * The screen's charts are ApexCharts canvases, which dompdf (no JavaScript) cannot draw. They
 * are re-rendered server-side as vector SVG by App\Export\ChartSvg from the very option blobs
 * the screen charts are initialised with, so a printed chart plots the identical numbers.
 *
 * Only the genuinely interactive controls are omitted, because they carry no figures to print:
 * the run buttons, the what-if lever sliders, the "How far can we go?" explorer (its sliders
 * start at a lever's mid-range and its limits are queued on demand, so there is nothing to
 * print until the reader drives it) and the assistant panel. The core report is deterministic,
 * so it is always available without a Monte Carlo run; a completed run adds its sections.
 */
class ScenarioPdfController extends Controller
{
    public function __construct(
        private readonly ScenarioReport $reports,
        private readonly ScenarioExport $export,
    ) {}

    public function download(Scenario $scenario): Response
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        // A draft has no runnable result; there is nothing to print.
        abort_if($scenario->status === ScenarioStatus::Draft, 404);

        $pdf = Pdf::loadView('pdf.results', ['reports' => [$this->data($scenario)]])->setPaper('a4', 'landscape');

        return $pdf->download("retireforecast-scenario-{$scenario->id}.pdf");
    }

    /**
     * Every ready scenario in one PDF — bases newest-first (the dashboard's order), each
     * followed by its what-if children, one report per page. Drafts have nothing to print
     * and are excluded, as on the single download.
     *
     * A small export is rendered here and streamed straight back. A bigger one cannot be:
     * one dompdf document holds every page in memory until it is written, so past
     * {@see ScenarioExport::BATCH_ABOVE} reports the request would run at the memory limit
     * and the gateway timeout. That export is queued instead and built one scenario at a
     * time, and the user is sent back to the dashboard, which shows its progress and then
     * the download.
     */
    public function downloadAll(): Response
    {
        $user = auth()->user();
        $count = $this->reports->scenarios($user)->count();

        abort_if($count === 0, 404);

        if (! $this->export->fitsOneRequest($count)) {
            // A second click while one is already running would only rebuild the same archive.
            if (($this->export->status($user)['state'] ?? null) !== 'building') {
                $this->export->queue($user, $count);
            }

            return redirect()->route('dashboard')->with(
                'status',
                "Building your export of {$count} forecasts in the background — it will appear here to download when it is ready.",
            );
        }

        $pdf = Pdf::loadView('pdf.results', ['reports' => $this->reports->reports($user)])->setPaper('a4', 'landscape');

        return $pdf->download('retireforecast-all-scenarios.pdf');
    }

    /** The finished archive from a queued export, streamed from disk rather than held in memory. */
    public function downloadArchive(): Response
    {
        $user = auth()->user();

        abort_unless($this->export->exists($user), 404);

        return $this->export->download($user);
    }

    /**
     * One report data set per ready scenario, in export order. Public so the view-render
     * test exercises the exact data the export produces.
     *
     * @return list<array<string, mixed>>
     */
    public function reports(User $user): array
    {
        return $this->reports->reports($user);
    }

    /**
     * Assemble the report data — the same variable set App\Livewire\ScenarioResults hands its
     * view, section for section. Public so the view-render test exercises the exact data the
     * controller produces (no second, drift-prone assembly in the test).
     *
     * @return array<string, mixed>
     */
    public function data(Scenario $scenario): array
    {
        return $this->reports->data($scenario);
    }
}
