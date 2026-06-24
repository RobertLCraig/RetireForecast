<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Assumptions;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;

final class AssumptionSetLibraryTest extends TestCase
{
    public function test_default_is_the_fca_set(): void
    {
        $default = AssumptionSetLibrary::default();

        $this->assertTrue($default->isDefault);
        $this->assertStringContainsString('FCA', $default->name);
        // Signed-off default equity real return is 4.4%.
        $this->assertSame(440, $default->assetClasses[0]->expectedRealReturn->basisPoints);
    }

    public function test_three_sets_ship_with_well_formed_correlation_matrices(): void
    {
        $sets = AssumptionSetLibrary::all();

        $this->assertCount(3, $sets);

        foreach ($sets as $set) {
            $n = count($set->assetClasses);
            $this->assertCount($n, $set->correlationMatrix, $set->name);
            foreach ($set->correlationMatrix as $i => $row) {
                $this->assertCount($n, $row, $set->name);
                $this->assertSame(1.0, $row[$i], "diagonal must be 1.0 in {$set->name}");
            }
            // Symmetry.
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $this->assertSame($set->correlationMatrix[$i][$j], $set->correlationMatrix[$j][$i], $set->name);
                }
            }
        }
    }

    public function test_only_one_set_is_marked_default(): void
    {
        $defaults = array_filter(AssumptionSetLibrary::all(), fn ($s) => $s->isDefault);

        $this->assertCount(1, $defaults);
    }
}
