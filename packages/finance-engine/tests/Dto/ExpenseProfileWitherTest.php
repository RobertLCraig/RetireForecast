<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\SpendPath;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The guard {@see HouseholdWitherTest} puts on `Household` and {@see AssetWitherTest} puts on the
 * asset DTOs, applied to {@see ExpenseProfile} — which had none, and had already drifted:
 * `withoutPropertyCosts()` dropped `$propertyCostsRealGrowth` while the other two withers carried
 * it, and only an unrelated gate in the projector (a zero bucket cannot escalate) kept that from
 * moving a number.
 *
 * Two tests, because neither alone is enough. The VALUE test proves a wither carries a field it
 * does not name. The SOURCE test proves the single rebuild site knows the field exists at all — a
 * field forgotten there carries its DEFAULT, which equals the fixture only by luck and so passes
 * any equality check.
 */
final class ExpenseProfileWitherTest extends TestCase
{
    /** A profile with every field set to something distinctive, so a dropped one is detectable. */
    private function fullyPopulated(): ExpenseProfile
    {
        $essential = SpendPath::fromBands([
            ['fromAge' => 0, 'amount' => Money::fromPounds(24_000)],
            ['fromAge' => 80, 'amount' => Money::fromPounds(21_000)],
        ]);

        return new ExpenseProfile(
            essentialAnnualSpend: $essential->startAmount(),
            discretionaryAnnualSpend: Money::fromPounds(6_000),
            survivorSpendFactor: Percent::fromPercent(70),
            oneOffCosts: [
                ['atAge' => 72, 'amount' => Money::fromPounds(9_000), 'label' => 'Major works', 'condition' => 'while_owning_home'],
                ['atAge' => 75, 'amount' => Money::fromPounds(4_000), 'label' => 'New car'],
            ],
            propertyCosts: Money::fromPounds(3_000),
            employmentCosts: Money::fromPounds(1_500),
            mortgageCosts: Money::fromPounds(5_000),
            essentialSpendPath: $essential,
            discretionarySpendPath: SpendPath::flat(Money::fromPounds(6_000)),
            propertyCostsRealGrowth: Percent::fromPercent(2),
            propertyCostsUtilities: Money::fromPounds(900),
        );
    }

    /** @return list<string> */
    private function fieldNames(): array
    {
        $names = [];
        foreach ((new ReflectionClass(ExpenseProfile::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $names[] = $property->getName();
        }

        return $names;
    }

    /**
     * Every wither changes only the fields it is declared to change, and carries the rest through
     * untouched. Driven off the DTO's own property list, so a new field is guarded automatically.
     */
    public function test_each_wither_carries_every_field_it_does_not_change(): void
    {
        $base = $this->fullyPopulated();

        // wither => the fields it is ALLOWED to change. Anything else moving is a silent drop.
        // `withoutPropertyCosts` clears the sold home's buckets and folds the utilities part back
        // into the essential floor as ordinary spend, so it legitimately zeroes the utilities
        // marker (nothing may strip it a second time). It does NOT get to lose the growth rate.
        $cases = [
            'withoutPropertyCosts' => [
                fn (): ExpenseProfile => $base->withoutPropertyCosts(),
                ['essentialAnnualSpend', 'essentialSpendPath', 'oneOffCosts', 'propertyCosts', 'mortgageCosts', 'propertyCostsUtilities'],
            ],
            'withMortgageCosts' => [
                fn (): ExpenseProfile => $base->withMortgageCosts(Money::fromPounds(7_500)),
                ['essentialAnnualSpend', 'essentialSpendPath', 'mortgageCosts'],
            ],
            'withOneOffCost' => [
                fn (): ExpenseProfile => $base->withOneOffCost(70, Money::fromPounds(2_000), 'Deposit'),
                ['oneOffCosts'],
            ],
        ];

        $fields = $this->fieldNames();
        $this->assertNotEmpty($fields);

        foreach ($cases as $wither => [$make, $changed]) {
            $copy = $make();

            foreach ($changed as $field) {
                $this->assertContains($field, $fields, "{$field} is not an ExpenseProfile field any more — update this test");
            }

            foreach ($fields as $field) {
                if (in_array($field, $changed, true)) {
                    continue;
                }
                $this->assertEquals(
                    $base->{$field},
                    $copy->{$field},
                    "{$wither}() silently dropped or altered \${$field} — pass it through ExpenseProfile::copy()",
                );
            }
        }
    }

    /**
     * The value test above only proves a field survives. This proves the single rebuild site
     * mentions every public property by name: a field added to the constructor and forgotten in
     * `copy()` would carry its DEFAULT through, which equals the base only by luck.
     */
    public function test_the_single_copy_method_names_every_expense_profile_field(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ExpenseProfile::class))->getFileName());

        $anchor = 'private function copy(';
        $start = strpos($source, $anchor);
        $this->assertNotFalse($start, 'ExpenseProfile::copy() no longer exists — the withers have no single rebuild site');

        $next = strpos($source, ' function ', $start + strlen($anchor));
        $body = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);

        foreach ($this->fieldNames() as $field) {
            $this->assertStringContainsString(
                $field.':',
                $body,
                "ExpenseProfile::copy() never mentions \${$field}: a wither would silently reset it to its default",
            );
        }
    }
}
