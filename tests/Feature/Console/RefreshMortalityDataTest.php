<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

class RefreshMortalityDataTest extends TestCase
{
    public function test_it_reports_the_embedded_data_in_sync_and_fresh(): void
    {
        // Integration: the command wires the integrity check + freshness (the date maths itself is
        // covered by FigureFreshnessTest) and exits zero when the embedded data matches its source.
        $this->artisan('mortality:refresh')
            ->expectsOutputToContain('the embedded data matches its JSON source exactly (5100 cells)')
            ->expectsOutputToContain('Cohort life expectancy at 65')
            ->assertExitCode(0);
    }
}
