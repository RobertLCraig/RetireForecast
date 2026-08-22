<?php

declare(strict_types=1);

namespace App\Export;

use App\Jobs\BuildScenarioExport;
use App\Models\Scenario;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * The "export all my forecasts" archive: a queued, one-scenario-at-a-time build for
 * exports too big to render in a single web request.
 *
 * Why it exists. The direct download renders every scenario into ONE dompdf document, and
 * dompdf holds the whole document in memory until it is written, so both the peak memory and
 * the render time grow with the number of scenarios (the figures are under {@see BATCH_ABOVE}).
 * Past that threshold the export moves to the worker and is built in batches of one: each
 * scenario is rendered as its own complete report and added to a zip, so peak memory is the
 * cost of the BIGGEST SINGLE report rather than the sum of all of them, and it stops growing
 * with the count. The download is then a file stream, which costs nothing to serve.
 *
 * The reader gets one PDF per forecast instead of one long PDF. That is the price of not
 * holding the whole document in memory: with no PDF-merging library in this project, a single
 * file can only be produced by a single render, which is the thing that does not scale.
 *
 * The reports themselves are assembled by {@see ScenarioReport}, the same class the direct
 * download uses, so a queued export prints exactly what a direct one would.
 *
 * Nothing fails silently: the build reports building / ready / failed-with-reason plus a
 * per-scenario progress count, which the dashboard polls and shows.
 */
class ScenarioExport
{
    /**
     * The largest export still rendered inside the web request.
     *
     * Measured on this machine, 2026-08-22 (card 0014), one request against batched:
     *
     *   forecasts | one request        | batched
     *   10        | 25.6s / 444 MB     | 15.3s / 200 MB
     *   20        | 78.9s / 774 MB     | 30.6s / 180 MB
     *   30        | 171.8s / 1,114 MB  | 45.7s / 200 MB
     *
     * A single request's memory grows about linearly with the count and its TIME grows faster
     * than that (dompdf's cost per page rises with document size), so the cliff is around
     * 35 to 40 forecasts, where a request meets both the 1512M memory limit and the 300s
     * gateway timeout. Batched, both stay flat: the peak is one report's, whatever the count.
     *
     * The threshold is set well inside the cliff rather than at it, because the count is the
     * user's to grow and it must not be a surprise when it breaks.
     */
    public const BATCH_ABOVE = 8;

    /** How long a finished archive is kept before it is treated as stale. */
    private const KEEP_FOR_HOURS = 24;

    public function __construct(private readonly ScenarioReport $reports) {}

    /** Would this user's export be built in the request, or queued? */
    public function fitsOneRequest(int $reportCount): bool
    {
        return $reportCount <= self::BATCH_ABOVE;
    }

    /**
     * The state of this user's queued export, or null if they have never asked for one.
     *
     * @return array{state: string, done: int, total: int, error: ?string, at: ?string}|null
     */
    public function status(User $user): ?array
    {
        return Cache::get($this->key($user));
    }

    /** Queue a fresh build, replacing any earlier one. */
    public function queue(User $user, int $total): void
    {
        $this->write($user, 'building', done: 0, total: $total);

        BuildScenarioExport::dispatch($user->id);
    }

    /**
     * Render every scenario into the archive, one at a time. Runs on the worker.
     *
     * The loop is the whole point: one report is assembled, rendered and thrown away before
     * the next is started, so nothing accumulates across scenarios. Each entry is a complete
     * standalone report — the same print a single download gives — named so the archive
     * opens in the dashboard's own order.
     */
    public function build(User $user): void
    {
        $scenarios = $this->reports->scenarios($user);
        $total = $scenarios->count();

        if ($total === 0) {
            $this->write($user, 'failed', 0, 0, 'There are no finished forecasts to export.');

            return;
        }

        Storage::disk('local')->makeDirectory(dirname($this->path($user)));

        $zip = new ZipArchive;
        $opened = $zip->open($this->absolutePath($user), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException("Could not create the export archive (zip error {$opened}).");
        }

        foreach ($scenarios as $index => $scenario) {
            $zip->addFromString($this->entryName($index, $scenario), $this->render($scenario));

            $this->write($user, 'building', done: $index + 1, total: $total);
        }

        $zip->close();

        $this->write($user, 'ready', done: $total, total: $total);
    }

    /** Record a terminal failure with its reason, so the dashboard stops waiting. */
    public function fail(User $user, string $reason): void
    {
        $status = $this->status($user);

        $this->write($user, 'failed', $status['done'] ?? 0, $status['total'] ?? 0, $reason);
    }

    /** The archive's path on the local (private) disk. */
    public function path(User $user): string
    {
        return "exports/{$user->id}/retireforecast-all-scenarios.zip";
    }

    /** Is there an archive on disk to download? */
    public function exists(User $user): bool
    {
        return Storage::disk('local')->exists($this->path($user));
    }

    /**
     * Delete a user's archive and forget its state. A built export is a file OUTSIDE the
     * database, so nothing cascades to it: without this, erasing an account would leave a
     * full copy of that household's forecasts on disk.
     */
    public function clear(User $user): void
    {
        Storage::disk('local')->deleteDirectory("exports/{$user->id}");
        Cache::forget($this->key($user));
    }

    /** Streamed from disk, so serving a big archive costs no more than serving a small one. */
    public function download(User $user): StreamedResponse
    {
        return Storage::disk('local')->download($this->path($user), 'retireforecast-all-scenarios.zip');
    }

    /** One scenario's complete report, rendered as its own PDF. */
    private function render(Scenario $scenario): string
    {
        // The facade resolves a NEW dompdf per call (it deliberately bypasses the facade
        // instance cache), so the previous scenario's document is not carried forward.
        return Pdf::loadView('pdf.results', ['reports' => [$this->reports->data($scenario)]])
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /** Numbered so the archive lists in export order, named so a reader can tell them apart. */
    private function entryName(int $index, Scenario $scenario): string
    {
        $slug = Str::slug($scenario->name) ?: 'forecast';

        return sprintf('%02d-%s.pdf', $index + 1, $slug);
    }

    private function absolutePath(User $user): string
    {
        return Storage::disk('local')->path($this->path($user));
    }

    private function key(User $user): string
    {
        return "scenario-export:{$user->id}";
    }

    private function write(User $user, string $state, int $done, int $total, ?string $error = null): void
    {
        Cache::put($this->key($user), [
            'state' => $state,
            'done' => $done,
            'total' => $total,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ], now()->addHours(self::KEEP_FOR_HOURS));
    }
}
