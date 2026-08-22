<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every Blade view must compile with no control-flow directive left uncompiled. Blade does
 * not recognise a directive glued directly to a word character — `finished@if (...)` or
 * `payments@if (...)` — so the `@if`/`@endif` stay in the rendered HTML (and the conditional
 * body shows unconditionally), a bug that slips past a normal render because the page still
 * "works". This guards the whole class app-wide: a leaked control directive fails the build.
 */
final class BladeDirectivesCompileTest extends TestCase
{
    /**
     * Unambiguous signals of a directive that failed to compile: an end-token, a bare `@else`,
     * or an opener with its argument list, still present in the COMPILED output. Real directives
     * are gone by then. `@else` earns its own alternative because it takes no argument list:
     * `forecast@else` left the builder's `<h1>` rendering EMPTY and this test still passed.
     */
    private const LEAK = '/@(?:end(?:if|foreach|forelse|for|while|unless|switch)|else|(?:if|elseif|unless|foreach|forelse|for|while|switch)\s*\()/';

    public function test_no_view_leaks_an_uncompiled_control_directive(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $compiled = Blade::compileString(File::get($file->getPathname()));
            if (preg_match_all(self::LEAK, $compiled, $m)) {
                $offenders[$file->getRelativePathname()] = array_unique($m[0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Blade left a control directive uncompiled (likely glued to a word char, e.g. word@if): '
                .json_encode($offenders, JSON_PRETTY_PRINT),
        );
    }

    public function test_the_leak_pattern_catches_a_glued_directive(): void
    {
        // Guards against a vacuous pass: the two real shapes this has been bitten by, so
        // narrowing the pattern later cannot quietly stop catching them.
        foreach (['finished@if ($x)Yes@endif', '@if ($a)A@elseif ($b)forecast@else New @endif'] as $source) {
            $this->assertMatchesRegularExpression(self::LEAK, Blade::compileString($source), "Missed a glued directive in: {$source}");
        }
    }
}
