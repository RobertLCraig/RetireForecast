<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The engine is the trustworthy product: a framework-free path package with zero Laravel
 * dependencies, no container, no I/O, no clock (see CLAUDE.md). That isolation is what
 * makes the HMRC worked-example tests believable, so it must not erode silently.
 *
 * This guard scans the engine source for any `use App\...` or `use Illuminate\...`
 * import. It exists because Pint's fully_qualified_strict_types fixer once turned a
 * docblock cross-reference to an app class into a real `use App\...` import — a quiet
 * breach that no test would otherwise have caught.
 */
final class EngineIsolationTest extends TestCase
{
    public function test_no_engine_source_file_imports_the_app_or_the_framework(): void
    {
        $offenders = [];

        foreach ($this->engineSourceFiles() as $file) {
            $source = file_get_contents($file);
            if (preg_match('/^\s*use\s+(App|Illuminate)\\\\/m', $source) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The framework-free engine must never import App\\ or Illuminate\\:\n".implode("\n", $offenders),
        );
    }

    /** @return list<string> every .php file under the engine's src/ */
    private function engineSourceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
