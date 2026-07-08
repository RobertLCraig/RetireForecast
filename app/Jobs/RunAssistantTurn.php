<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Assistant\AssistantTurnRunner;
use App\Enums\SimulationStatus;
use App\Models\AssistantTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a queued assistant turn on the worker. Holds only the turn id (small payload, always
 * reads current state); delegates to {@see AssistantTurnRunner}. A turn the panel has already
 * abandoned (Clear deletes the row) simply finds nothing and exits.
 */
class RunAssistantTurn implements ShouldQueue
{
    use Queueable;

    /** One shot: a failed generation is re-asked by the reader, never silently re-billed to the model. */
    public int $tries = 1;

    /** The model may legitimately take minutes (120s x 2 guarded attempts) — outlive the worker default. */
    public int $timeout = 300;

    public function __construct(public readonly int $assistantTurnId) {}

    public function handle(AssistantTurnRunner $runner): void
    {
        $turn = AssistantTurn::find($this->assistantTurnId);

        if ($turn !== null) {
            $runner->execute($turn);
        }
    }

    /**
     * A dead worker (timeout, OOM, killed) must not strand a turn in Running while the panel
     * polls forever. Mark it Failed with the reason so the status is terminal and the UI
     * reports it — no silent failure.
     */
    public function failed(?Throwable $e): void
    {
        $turn = AssistantTurn::find($this->assistantTurnId);

        if ($turn !== null && ! $turn->status->isTerminal()) {
            $turn->update([
                'status' => SimulationStatus::Failed,
                'error' => $e?->getMessage() ?? 'The assistant worker stopped unexpectedly.',
            ]);
        }
    }
}
