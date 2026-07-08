<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SimulationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued assistant turn: a question, the prior turns it was asked with, and — once the
 * background worker has run the local model — the answer. Reuses the {@see SimulationStatus}
 * lifecycle (queued → running → done/failed) so the panel can poll it exactly as the results
 * page polls a run. Rows are transient: the panel deletes a turn the moment its answer joins
 * the (browser-local) transcript, so nothing conversational persists server-side.
 *
 * @property int $user_id
 * @property int $scenario_id
 * @property bool $compare
 * @property SimulationStatus $status
 * @property string $question
 * @property array|null $history
 * @property array{text: string, status: string}|null $answer
 * @property string|null $error
 */
class AssistantTurn extends Model
{
    protected $fillable = [
        'user_id', 'scenario_id', 'compare', 'status', 'question', 'history', 'answer', 'error',
    ];

    protected function casts(): array
    {
        return [
            'compare' => 'boolean',
            'status' => SimulationStatus::class,
            'question' => 'encrypted',
            'history' => 'encrypted:array',
            'answer' => 'encrypted:array',
        ];
    }

    /** Seconds a turn may sit queued before we surface the "is a worker running?" hint. */
    private const WORKER_WAIT_HINT_SECONDS = 15;

    /**
     * True when this turn has sat queued long enough that, run locally, the likely cause is
     * that no queue worker is running — the same rule (and reason) as
     * {@see SimulationRun::isAwaitingWorker()}: a queued turn must never look like a hung
     * "Thinking…" with no explanation.
     */
    public function isAwaitingWorker(): bool
    {
        return $this->status === SimulationStatus::Queued
            && $this->created_at !== null
            && $this->created_at->lte(now()->subSeconds(self::WORKER_WAIT_HINT_SECONDS));
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
