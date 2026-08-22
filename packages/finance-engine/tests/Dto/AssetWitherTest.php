<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\Property;

/**
 * The same guard {@see HouseholdWitherTest} puts on `Household`, applied to the three asset DTOs
 * the capacity-for-loss stress re-values: a wither rebuilds the whole object, so a field added to
 * the DTO and forgotten in the wither would carry its DEFAULT into every stressed forecast rather
 * than the value that was entered — a repayment mortgage that quietly stops amortising, an
 * annuity purchase that never happens, a pot's growth override that reverts.
 *
 * Reflection-driven on purpose: it enumerates each DTO's own properties rather than a hand-written
 * list, so a NEW field is covered the moment it is declared. Reading the source and requiring the
 * field by name is what catches the case a value comparison cannot — a forgotten field whose
 * default happens to equal the fixture's value passes any equality check by luck.
 */
final class AssetWitherTest extends TestCase
{
    /**
     * Every rebuild site, and the fields it is ALLOWED to replace. `DcPension` routes both of its
     * withers through one private `copy()`, so that is the site to read.
     */
    public function test_each_rebuild_site_names_every_field_it_does_not_replace(): void
    {
        $sites = [
            [Property::class, 'withCurrentValue', ['currentValue']],
            [Account::class, 'withValue', ['balance', 'unrealisedGain']],
            [DcPension::class, 'copy', ['currentValue', 'annuityPurchase']],
        ];

        foreach ($sites as [$class, $method, $replaced]) {
            $this->assertRebuildSiteIsComplete($class, $method, $replaced);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $replaced
     */
    private function assertRebuildSiteIsComplete(string $class, string $method, array $replaced): void
    {
        $reflection = new ReflectionClass($class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $start = strpos($source, 'function '.$method.'(');
        $this->assertNotFalse($start, "{$class}::{$method}() no longer exists — update this test");

        $next = strpos($source, ' function ', $start + 1);
        $body = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);

        $fields = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $fields[] = $property->getName();
        }
        $this->assertNotEmpty($fields);

        foreach ($fields as $field) {
            if (in_array($field, $replaced, true)) {
                continue;
            }

            $this->assertStringContainsString(
                '$this->'.$field,
                $body,
                "{$class}::{$method}() never mentions \${$field}: it would be silently reset to its default",
            );
        }
    }
}
