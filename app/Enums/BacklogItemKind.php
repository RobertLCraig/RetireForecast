<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of idea the assistant captured to the work queue (Phase 3). The model classifies a
 * reader's free-text idea as one of these; it is only ever a label on a queued item for a human to
 * review — the model never acts on it, builds anything, or edits code.
 */
enum BacklogItemKind: string
{
    case Research = 'research';
    case Feature = 'feature';
    case Task = 'task';

    public function label(): string
    {
        return match ($this) {
            self::Research => 'Research',
            self::Feature => 'Feature',
            self::Task => 'Task',
        };
    }

    /** The kind for a matching value, or Task as a safe default for anything unrecognised. */
    public static function fromValueOrDefault(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Task;
    }
}
