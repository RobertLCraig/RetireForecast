<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\Interpretation;
use App\Compliance\NeutralZoneScanner;
use App\Compliance\OutputPhrasing;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The regulatory boundary: education/guidance only, never a personal recommendation.
 *
 * This is a *partition* check (see DECISIONS 2026-06-25). The neutral zone — every Blade
 * template the user sees and every app-side string builder — must be free of directive
 * recommendation phrasing. Directive phrasing is permitted in exactly one place: the
 * walled-off {@see Interpretation} layer and its single gated partial.
 *
 * **Posture-aware (DECISIONS 2026-07-04).** The partition is ENFORCED (a build failure) only
 * in the public guidance-only posture (`compliance.personal_use = false`). In personal-use
 * advice mode (the default while this is a private family tool) the partition is deliberately
 * relaxed: advice phrasing is allowed in the neutral zone and this test SKIPS rather than
 * fails, reporting how many advice spots exist. The spots stay findable at any time via
 * `php artisan compliance:advice-audit`, and flipping `personal_use` to false before any public
 * release re-enforces the partition — turning every advice spot back into a listed failure to fix.
 */
class BannedPhrasingTest extends TestCase
{
    public function test_neutral_zone_stays_on_the_guidance_side_of_the_line(): void
    {
        $offenders = NeutralZoneScanner::advice();

        if (config('compliance.personal_use')) {
            $this->markTestSkipped(
                'Personal-use advice mode (compliance.personal_use=true): guidance-only partition '.
                'relaxed by design. '.count($offenders).' advice spot(s) in the neutral zone — run '.
                '`php artisan compliance:advice-audit` to list them, or set COMPLIANCE_PERSONAL_USE=false '.
                'to re-enforce the partition before a public release.',
            );
        }

        $this->assertSame(
            [],
            $offenders,
            'Banned recommendation phrasing found in the neutral zone: '.json_encode($offenders, JSON_PRETTY_PRINT),
        );
    }

    public function test_the_lint_actually_catches_a_recommendation(): void
    {
        // Guards against a vacuous pass (e.g. an empty pattern list).
        $this->assertNotEmpty(OutputPhrasing::violations('On these numbers you should sell and buy.'));
        $this->assertContains('you should', OutputPhrasing::violations('you should do this'));
    }

    public function test_the_interpretation_layer_is_a_genuine_exemption(): void
    {
        // The wall only matters if the thing behind it would otherwise be flagged. The
        // interpretation service deliberately speaks in directive terms, so scanning it
        // must surface violations — proving the partition is load-bearing, not trivially
        // satisfied because nothing anywhere uses advice-style wording.
        $interpretation = app_path('Compliance'.DIRECTORY_SEPARATOR.'Interpretation.php');

        $this->assertFileExists($interpretation);
        $this->assertNotEmpty(
            OutputPhrasing::violations(File::get($interpretation)),
            'The walled-off Interpretation layer is expected to contain directive phrasing; '.
            'if it does not, the partition check proves nothing.',
        );
    }
}
