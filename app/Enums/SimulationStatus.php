<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a simulation run. Nothing long-running may run silently, so every
 * run reports where it is: queued, running (with a progress percentage), then a
 * terminal state of done, failed (with a reason) or cancelled by the user.
 */
enum SimulationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }
}
