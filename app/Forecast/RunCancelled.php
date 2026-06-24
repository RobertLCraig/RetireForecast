<?php

declare(strict_types=1);

namespace App\Forecast;

use RuntimeException;

/**
 * Thrown from a run's progress hook when the user has cancelled it, to abort the
 * engine mid-run. Caught by the runner, which then marks the run cancelled.
 */
final class RunCancelled extends RuntimeException {}
