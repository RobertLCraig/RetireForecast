<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a run is a quick synchronous preview or the full queued run. The path
 * counts follow the plan: ~1,000 paths for a responsive preview, 10,000 for the
 * headline run.
 */
enum SimulationMode: string
{
    case Preview = 'preview';
    case Full = 'full';

    public function defaultPaths(): int
    {
        return match ($this) {
            self::Preview => 1_000,
            self::Full => 10_000,
        };
    }
}
