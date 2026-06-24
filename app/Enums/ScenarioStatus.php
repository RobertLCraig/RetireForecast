<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A scenario's lifecycle position, kept as a clear structural column for listing
 * and filtering. A scenario starts as a draft and becomes ready once it holds
 * enough to run. Per-run progress (queued / running / done / failed) belongs to the
 * SimulationRun added with the forecast services, not here.
 */
enum ScenarioStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
}
