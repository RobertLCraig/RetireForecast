<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * The freshness command runs over the real tax-year configs. A very large threshold keeps
 * the assertion date-independent (it would otherwise flip to failing once the real figures
 * genuinely age past the default); the freshness arithmetic itself is unit-tested separately.
 */
class CheckFigureFreshnessTest extends TestCase
{
    public function test_it_reports_every_supported_tax_year_and_passes_within_threshold(): void
    {
        $this->artisan('figures:freshness --months=1200')
            ->expectsOutputToContain('2025-26')
            ->expectsOutputToContain('2026-27')
            ->assertExitCode(0);
    }
}
