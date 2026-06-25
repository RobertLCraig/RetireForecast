<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\Interpretation;
use App\Compliance\OutputPhrasing;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

/**
 * The regulatory boundary, enforced at build time: education/guidance only, never a
 * personal recommendation.
 *
 * This is a *partition* check (see DECISIONS 2026-06-25). The neutral zone — every
 * Blade template the user sees and every app-side string builder — must be free of
 * directive recommendation phrasing. Directive phrasing is permitted in exactly one
 * place: the walled-off, admin-granted {@see Interpretation} layer and
 * its single gated partial. If a "you should" leaks into a result template, this test
 * fails the build.
 */
class BannedPhrasingTest extends TestCase
{
    /** The one app namespace allowed to hold directive phrasing (and the lint itself). */
    private const WALLED_OFF_DIR = DIRECTORY_SEPARATOR.'Compliance'.DIRECTORY_SEPARATOR;

    /** The one view allowed to hold directive phrasing. */
    private const WALLED_OFF_VIEW = 'interpretation';

    public function test_no_result_template_or_app_string_contains_banned_phrasing(): void
    {
        $offenders = [];

        foreach ($this->neutralZoneFiles() as $file) {
            $violations = OutputPhrasing::violations(File::get($file->getPathname()));
            if ($violations !== []) {
                $offenders[$file->getPathname()] = $violations;
            }
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

    /**
     * Every user-facing Blade view (bar the walled-off interpretation partial) and every
     * app PHP file (bar the Compliance namespace, which holds the lint patterns and the
     * interpretation layer).
     *
     * @return list<SplFileInfo>
     */
    private function neutralZoneFiles(): array
    {
        $files = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains($file->getFilename(), self::WALLED_OFF_VIEW)) {
                continue;
            }
            $files[] = $file;
        }

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), self::WALLED_OFF_DIR)) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }
}
