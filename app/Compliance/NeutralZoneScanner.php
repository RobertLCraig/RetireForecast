<?php

declare(strict_types=1);

namespace App\Compliance;

use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * The single home for "what is the neutral zone" — every user-facing Blade view and every
 * app PHP file that must stay on the guidance side of the regulatory line, EXCEPT the one
 * walled-off place directive advice is allowed to live (the {@see Interpretation} layer and
 * its `interpretation` partial).
 *
 * Both the build-time partition test (`Tests\Feature\Compliance\BannedPhrasingTest`) and the
 * on-demand `compliance:advice-audit` command read the zone from here, so the definition of
 * "advice vs guidance" has one home and the two can never drift.
 *
 * The scan itself is posture-agnostic: it always finds where directive phrasing lives. Whether
 * that is a *build failure* (public guidance-only posture) or merely a *flagged inventory*
 * (personal-use advice mode) is the caller's decision — see DECISIONS 2026-07-04.
 */
final class NeutralZoneScanner
{
    /** The one app namespace allowed to hold directive phrasing (and the lint itself). */
    private const WALLED_OFF_DIR = DIRECTORY_SEPARATOR.'Compliance'.DIRECTORY_SEPARATOR;

    /** The one view allowed to hold directive phrasing (its filename carries this token). */
    private const WALLED_OFF_VIEW = 'interpretation';

    /**
     * Every neutral-zone file that contains banned recommendation phrasing, mapped to the
     * phrases found. Empty means the whole neutral zone is on the guidance side of the line.
     *
     * @return array<string, list<string>>
     */
    public static function advice(): array
    {
        $offenders = [];

        foreach (self::files() as $file) {
            $violations = OutputPhrasing::violations(File::get($file->getPathname()));
            if ($violations !== []) {
                $offenders[$file->getPathname()] = $violations;
            }
        }

        return $offenders;
    }

    /**
     * The neutral-zone files: every `.blade.php` under resources/views (bar the walled-off
     * interpretation partial) and every app PHP file (bar the Compliance namespace, which
     * holds the lint patterns and the interpretation layer itself).
     *
     * @return list<SplFileInfo>
     */
    public static function files(): array
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
