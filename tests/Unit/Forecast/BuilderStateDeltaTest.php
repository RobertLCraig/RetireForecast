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

    public function test_value_at_reads_the_leaf_an_override_would_replace(): void
    {
        $base = BuilderStateFixture::full();

        // Top-level map leaf, a row addressed by id, and a row nested inside a row.
        $this->assertSame('62000', BuilderStateDelta::valueAt($base, 'people.p1.grossSalary'));
        $this->assertSame('28000', BuilderStateDelta::valueAt($base, 'expenseLines.ess1.amount'));
        $this->assertSame('18000', BuilderStateDelta::valueAt($base, 'housing.annualRent'));
        $this->assertSame('100000', BuilderStateDelta::valueAt($base, 'pensions.dc1.withdrawals.wd1.amount'));

        // A path that does not resolve reads as null (no prior value), never an error.
        $this->assertNull(BuilderStateDelta::valueAt($base, 'people.pX.grossSalary'));
        $this->assertNull(BuilderStateDelta::valueAt($base, 'nope.not.here'));
    }

    public function test_an_added_row_round_trips_through_diff_and_merge(): void
    {
        // A what-if that adds a one-off cost (e.g. a mortgage deposit) stores the row whole at
        // its id path, and merge rebuilds it — so the round trip restores the edited state.
        $base = BuilderStateFixture::full();
        $edited = $base;
        $edited['oneOffCosts'][] = ['id' => 'oneoff9', 'atAge' => '70', 'amount' => '40000', 'label' => 'Mortgage deposit'];

        $overrides = BuilderStateDelta::diff($base, $edited);
        $this->assertArrayHasKey('oneOffCosts.oneoff9', $overrides);
        $this->assertSame(['id' => 'oneoff9', 'atAge' => '70', 'amount' => '40000', 'label' => 'Mortgage deposit'], $overrides['oneOffCosts.oneoff9']);

        $merged = BuilderStateDelta::merge($base, $overrides);
        $this->assertEquals($edited['oneOffCosts'], $merged['oneOffCosts']);
    }

    public function test_adding_the_first_row_to_an_empty_list_keeps_it_positional(): void
    {
        // The base has no one-off costs at all and the what-if adds the first. An empty list is
        // shapeless — nothing in it carries an id, so it cannot be recognised as a row list — and
        // setPath used to fall through to the map branch and write the row under its own id. That
        // turned a positional list into an id-keyed map, which the next diff() then walks down a
        // different branch, while orphans() reports the write as a success. Reachable whenever a
        // what-if adds the first income stream, one-off cost, account or withdrawal.
        $base = BuilderStateFixture::full();
        $base['oneOffCosts'] = [];

        $edited = $base;
        $edited['oneOffCosts'][] = ['id' => 'oneoff9', 'atAge' => '70', 'amount' => '40000', 'label' => 'New roof'];

        $overrides = BuilderStateDelta::diff($base, $edited);
        $merged = BuilderStateDelta::merge($base, $overrides);

        $this->assertTrue(array_is_list($merged['oneOffCosts']), 'the first added row turned the list into an id-keyed map');
        $this->assertEquals($edited['oneOffCosts'], $merged['oneOffCosts']);
        $this->assertSame([], BuilderStateDelta::orphans($base, $overrides));
    }

    public function test_removing_a_row_from_a_list_the_base_has_emptied_is_a_no_op(): void
    {
        // The same shapeless-empty hole from the other side: a stored REMOVED sentinel for a row
        // the base has since dropped altogether. The map branch wrote the sentinel string itself
        // into the list under the missing row's id, so the "removal" left behind a fake row.
        $base = BuilderStateFixture::full();
        $base['accounts'] = [];

        $merged = BuilderStateDelta::merge($base, ['accounts.acc1' => BuilderStateDelta::REMOVED]);

        $this->assertSame([], $merged['accounts'], 'removing an already-absent row invented one');
    }

    public function test_a_capital_receipt_row_adds_and_removes_through_diff_and_merge(): void
    {
        // The receipts collection is a row list like any other: a what-if adding a documented
        // receipt stores the row whole at its id path; removing the base's stores the sentinel.
        $base = BuilderStateFixture::full();
        $edited = $base;
        $edited['capitalReceipts'][] = ['id' => 'cr9', 'ownerId' => 'p2', 'year' => '2032', 'amount' => '25000', 'label' => 'Car sale'];
        array_shift($edited['capitalReceipts']); // drop the base's cr1

        $overrides = BuilderStateDelta::diff($base, $edited);
        $this->assertSame(BuilderStateDelta::REMOVED, $overrides['capitalReceipts.cr1']);
        $this->assertSame(['id' => 'cr9', 'ownerId' => 'p2', 'year' => '2032', 'amount' => '25000', 'label' => 'Car sale'], $overrides['capitalReceipts.cr9']);

        $merged = BuilderStateDelta::merge($base, $overrides);
        $this->assertSame(['cr9'], array_column($merged['capitalReceipts'], 'id'));
    }

    public function test_a_removed_row_round_trips_through_diff_and_merge(): void
    {
        $base = BuilderStateFixture::full();
        $edited = $base;
        array_pop($edited['accounts']); // drop the last account (acc3)

        $overrides = BuilderStateDelta::diff($base, $edited);
        $this->assertSame(BuilderStateDelta::REMOVED, $overrides['accounts.acc3']);

        $merged = BuilderStateDelta::merge($base, $overrides);
        $this->assertSame(['acc1', 'acc2'], array_column($merged['accounts'], 'id'));
    }

    public function test_a_value_override_for_a_deleted_row_stays_an_orphan_not_an_add(): void
    {
        // The add/remove support must not resurrect a leaf override whose row the base deleted:
        // only a whole-row value is an add; a leaf override with no row remains a flagged orphan.
        $base = BuilderStateFixture::full();

        $this->assertSame(
            ['pensions.gone1.currentValue'],
            BuilderStateDelta::orphans($base, ['pensions.gone1.currentValue' => '999']),
        );
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
