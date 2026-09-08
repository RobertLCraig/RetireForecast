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

    /**
     * Board card 0065, criterion #4. The command used to sweep the statutory figures only, so the
     * ECONOMIC assumptions, which move the answer far more, could age indefinitely with nothing to
     * notice. Every figure on every shipped set is now swept by the same command.
     */
    public function test_it_reports_the_economic_assumptions_of_every_shipped_set(): void
    {
        $this->artisan('figures:freshness --months=1200')
            ->expectsOutputToContain('FCA default')
            ->expectsOutputToContain('DMS historical')
            ->expectsOutputToContain('Real care-fee escalation')
            ->expectsOutputToContain('Inflation persistence')
            ->assertExitCode(0);
    }

    /** A stale economic assumption must fail the command, or it is a report and not a guardrail. */
    public function test_a_stale_economic_assumption_fails_the_command(): void
    {
        // Zero months: everything verified before today is stale, so the exit code has to be
        // non-zero whatever the calendar says when this runs.
        $this->artisan('figures:freshness --months=0')->assertExitCode(1);
    }
}
