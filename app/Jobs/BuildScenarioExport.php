<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Export\ScenarioExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds a user's "export all to PDF" archive on the worker, one scenario at a time.
 *
 * Holds only the user id (so the payload stays small and always reads current state);
 * the work and the progress reporting belong to {@see ScenarioExport}.
 */
class BuildScenarioExport implements ShouldQueue
{
    use Queueable;

    /**
     * A big export is minutes of rendering, not seconds, so the worker's 60-second default
     * would kill it part-way. One attempt only: a re-render costs as much as the first and
     * a failure here is a bug or a memory ceiling, not a transient fault.
     */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(ScenarioExport $export): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            $export->build($user);
        }
    }

    /**
     * A dead worker (timeout, OOM, killed) must not leave the dashboard polling forever.
     * Record the reason so the state is terminal and the user is told — no silent failure.
     */
    public function failed(?Throwable $e): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            app(ScenarioExport::class)->fail($user, $e?->getMessage() ?? 'The export worker stopped unexpectedly.');
        }
    }
}
