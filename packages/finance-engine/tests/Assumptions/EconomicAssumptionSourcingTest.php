<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Assumptions;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * Board card 0065, criterion #4. Statutory figures have carried a source URL and a verified-on
 * date since the start. The ECONOMIC assumptions, which move the answer far more, sat behind one
 * prose note per set with nothing machine-checkable in it, so nothing could tell whether they had
 * been looked at this year or four years ago.
 *
 * This is the guard that keeps "every" honest. It ENUMERATES the DTO's own properties by
 * reflection, the same trick {@see HouseholdWitherTest} uses, so adding a figure to
 * {@see AssumptionSet} reddens this until it is sourced. Only the four that are not figures are
 * exempt, and the exemption is listed here where a reader can see it rather than being implied by
 * a figure's absence.
 */
final class EconomicAssumptionSourcingTest extends TestCase
{
    /**
     * The constructor properties that are not economic assumptions: the set's own identity, its
     * prose note, the asset classes (which carry their own per-figure sourcing on
     * {@see AssetClassAssumption}, return and volatility cited apart), the default flag, and the
     * sourcing list itself, which is the answer rather than a question.
     */
    private const NOT_A_FIGURE = ['name', 'sourceNote', 'assetClasses', 'isDefault', 'economicSourcing'];

    /** @return list<string> */
    private function economicFigures(): array
    {
        $out = [];
        foreach ((new ReflectionClass(AssumptionSet::class))->getConstructor()->getParameters() as $parameter) {
            if (! in_array($parameter->getName(), self::NOT_A_FIGURE, true)) {
                $out[] = $parameter->getName();
            }
        }

        return $out;
    }

    public function test_every_shipped_set_sources_every_economic_assumption_it_carries(): void
    {
        $figures = $this->economicFigures();
        $this->assertNotEmpty($figures, 'reflection should find the economic assumptions');

        foreach (AssumptionSetLibrary::all() as $set) {
            $sourced = $set->economicSourcing();

            foreach ($figures as $figure) {
                $this->assertArrayHasKey(
                    $figure,
                    $sourced,
                    "the '{$set->name}' set carries no source for its {$figure}",
                );
            }
        }
    }

    public function test_every_source_names_a_url_and_a_verifiable_date(): void
    {
        foreach (AssumptionSetLibrary::all() as $set) {
            foreach ($set->economicSourcing() as $figure => $source) {
                $this->assertStringContainsString(
                    'https://',
                    $source->source,
                    "the '{$set->name}' set's {$figure} source is prose, not a citation",
                );
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $source->verifiedOn,
                    "the '{$set->name}' set's {$figure} has no verified-on date the freshness command can read",
                );
            }
        }
    }
}
