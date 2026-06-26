<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\BuilderStateDelta;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuilderStateFixture;

/**
 * The single merge function behind delta-child what-ifs (Phase C2). The guarantees a
 * child relies on: the delta captures exactly the changed leaves; merging it back onto
 * the base reproduces the edited state (round-trip); overrides target list rows by
 * stable id so they survive a base reorder; structural add/remove is refused, not
 * forked; and an override orphaned by a base change is surfaced, not applied blindly.
 */
class BuilderStateDeltaTest extends TestCase
{
    public function test_diff_of_identical_states_is_empty(): void
    {
        $state = BuilderStateFixture::full();

        $this->assertSame([], BuilderStateDelta::diff($state, $state));
    }

    public function test_merge_after_diff_is_the_identity(): void
    {
        $base = BuilderStateFixture::full();
        $effective = $base;
        $effective['expense']['essential'] = '31000';          // a nested map leaf
        $effective['variant'] = 'buy_outright';                // a top-level scalar (added)
        $effective['people'][0]['grossSalary'] = '70000';      // a list row by index→id
        $effective['pensions'][0]['currentValue'] = '450000';  // a different collection

        $overrides = BuilderStateDelta::diff($base, $effective);

        $this->assertEqualsCanonicalizing(
            ['variant', 'expense.essential', 'people.p1.grossSalary', 'pensions.dc1.currentValue'],
            array_keys($overrides),
        );
        $this->assertEquals($effective, BuilderStateDelta::merge($base, $overrides));
    }

    public function test_an_override_targets_a_row_by_id_not_index(): void
    {
        $base = BuilderStateFixture::full();
        $overrides = ['pensions.dc1.currentValue' => '999999'];

        // The base is edited so the dc pension is no longer first in the list.
        $reordered = $base;
        $reordered['pensions'] = array_reverse($base['pensions']);

        $merged = BuilderStateDelta::merge($reordered, $overrides);

        $dc = self::rowById($merged['pensions'], 'dc1');
        $this->assertSame('999999', $dc['currentValue']);
        // Every other pension is untouched.
        $this->assertSame($reordered['pensions'][0]['id'], $merged['pensions'][0]['id']);
    }

    public function test_structural_add_or_remove_is_detected(): void
    {
        $base = BuilderStateFixture::full();

        $sameValuesEdited = $base;
        $sameValuesEdited['expense']['essential'] = '40000';
        $this->assertFalse(BuilderStateDelta::structurallyDiffers($base, $sameValuesEdited));

        $rowAdded = $base;
        $rowAdded['accounts'][] = ['id' => 'acc9', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '5000'];
        $this->assertTrue(BuilderStateDelta::structurallyDiffers($base, $rowAdded));

        $rowRemoved = $base;
        array_pop($rowRemoved['accounts']);
        $this->assertTrue(BuilderStateDelta::structurallyDiffers($base, $rowRemoved));
    }

    public function test_an_override_orphaned_by_a_base_change_is_surfaced(): void
    {
        $base = BuilderStateFixture::full();
        $overrides = [
            'expense.essential' => '31000',           // still resolves
            'pensions.gone1.currentValue' => '12345', // targets a row the base never had
        ];

        $orphans = BuilderStateDelta::orphans($base, $overrides);

        $this->assertSame(['pensions.gone1.currentValue'], $orphans);

        // Merge still applies the resolvable override and simply skips the orphan.
        $merged = BuilderStateDelta::merge($base, $overrides);
        $this->assertSame('31000', $merged['expense']['essential']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function rowById(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return [];
    }
}
