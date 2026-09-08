<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Assumptions;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Board card 0062, criterion 4. The asset-class return and volatility are the two figures
 * that decide the whole answer, and they were the only economic figures in the engine with
 * no source and no verified-on date of their own: the citation lived in the SET's prose
 * sourceNote, where a reader cannot tell which sentence covers which number, and nothing
 * recorded when it was last checked. Every tax figure in this engine carries both; these
 * carry more weight than any of them.
 *
 * The return and the volatility are sourced SEPARATELY on purpose — the shipped default
 * takes its returns from the FCA and its volatilities from the long-run record — so a test
 * that only demanded one source per class would pass while half the figures stayed unsourced.
 */
final class AssetClassSourcingTest extends TestCase
{
    public function test_every_shipped_asset_class_carries_a_source_and_a_verified_on_date(): void
    {
        foreach (AssumptionSetLibrary::all() as $set) {
            foreach ($set->assetClasses as $assetClass) {
                $where = $set->name.' / '.$assetClass->name;

                foreach ([
                    'return' => [$assetClass->returnSource, $assetClass->returnVerifiedOn],
                    'volatility' => [$assetClass->volatilitySource, $assetClass->volatilityVerifiedOn],
                ] as $figure => [$source, $verifiedOn]) {
                    $this->assertNotNull($source, "{$where}: the {$figure} carries no source");
                    $this->assertNotSame('', trim((string) $source), "{$where}: the {$figure} source is blank");
                    $this->assertMatchesRegularExpression(
                        '/^\d{4}-\d{2}-\d{2}$/',
                        (string) $verifiedOn,
                        "{$where}: the {$figure} carries no ISO verified-on date",
                    );
                }
            }
        }
    }

    public function test_sourcing_is_optional_on_a_hand_built_assumption(): void
    {
        // A test fixture and an old stored snapshot both build asset classes without sourcing,
        // so the fields are nullable and read as "not stated" rather than throwing. What the
        // test above demands is that the SHIPPED library states them.
        $bare = new AssetClassAssumption(
            'Global equities',
            Percent::fromPercent(4.4),
            Percent::fromPercent(23),
        );

        $this->assertNull($bare->returnSource);
        $this->assertNull($bare->volatilityVerifiedOn);
    }
}
