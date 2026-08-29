<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\EditTarget;
use App\Assistant\ScenarioEditVocabulary;
use App\Forecast\WhatIfWriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Guardrail C2 — the closed menu of fields the assistant may change. Pinned against the full
 * builder fixture, because the menu drifting from the builder's real field surface is the way
 * this feature breaks quietly (SE-4): a field the chat cannot reach is fine, a field it reaches
 * with the wrong type or a made-up path is not.
 */
final class ScenarioEditVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_target_resolves_against_the_base_state_and_is_correctly_typed(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());

        $targets = ScenarioEditVocabulary::for($base);
        $byPath = collect($targets)->keyBy(fn (EditTarget $t): string => $t->path);

        // The Phase 1 breadth: the plan itself, each working person's salary and retirement age,
        // each pot/balance/spending line. Nothing else, and nothing structural.
        $this->assertEqualsCanonicalizing([
            'variant',
            'people.p1.grossSalary', 'people.p1.plannedRetirementAge',
            'pensions.dc1.currentValue',
            'accounts.acc1.balance', 'accounts.acc2.balance', 'accounts.acc3.balance',
            'expenseLines.ess1.amount', 'expenseLines.disc1.amount',
        ], $byPath->keys()->all());

        $this->assertSame('money', $byPath['people.p1.grossSalary']->type);
        $this->assertSame('int', $byPath['people.p1.plannedRetirementAge']->type);
        $this->assertSame('money', $byPath['expenseLines.ess1.amount']->type);
        $this->assertSame('enum', $byPath['variant']->type);
        $this->assertEqualsCanonicalizing(['buy_outright', 'rent', 'stay_put'], $byPath['variant']->options);
    }

    public function test_a_target_carries_the_label_and_the_value_the_reader_already_sees(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());

        $byPath = collect(ScenarioEditVocabulary::for($base))->keyBy(fn (EditTarget $t): string => $t->path);

        // Labelled exactly as the resulting what-if diff will label it (one labelling, two surfaces),
        // and showing the figure it holds today, so no figure moves that the reader cannot see.
        $this->assertSame('DC pension · current value', $byPath['pensions.dc1.currentValue']->label);
        $this->assertSame('£410,000', $byPath['pensions.dc1.currentValue']->currentDisplay());
        $this->assertSame('ISA account · balance', $byPath['accounts.acc1.balance']->label);
        $this->assertSame('Essentials · amount', $byPath['expenseLines.ess1.amount']->label);
        $this->assertSame('Sell & rent', $byPath['variant']->currentDisplay());
        $this->assertStringContainsString('pensions.dc1.currentValue | DC pension · current value | money | now £410,000', ScenarioEditVocabulary::menu([$byPath['pensions.dc1.currentValue']]));
    }

    public function test_a_retired_person_is_offered_no_salary_or_retirement_age(): void
    {
        // P2 is retired with both fields blank: offering them would propose a figure that
        // changes nothing anyone can see.
        $base = ScenarioFixture::rich(User::factory()->create());

        $paths = array_column(ScenarioEditVocabulary::for($base), 'path');

        $this->assertNotContains('people.p2.grossSalary', $paths);
        $this->assertNotContains('people.p2.plannedRetirementAge', $paths);
    }

    public function test_an_assumption_is_offered_only_once_the_plan_actually_carries_one(): void
    {
        $user = User::factory()->create();

        // The fixture overrides no assumption, so the state has no assumptionOverrides map: an
        // override there could not be merged back, so it is not offered.
        $this->assertNotContains('assumptionOverrides.inflation', array_column(ScenarioEditVocabulary::for(ScenarioFixture::rich($user)), 'path'));

        $withOverride = ScenarioFixture::rich($user, ['assumptionOverrides' => ['inflation' => '2.5']]);
        $byPath = collect(ScenarioEditVocabulary::for($withOverride))->keyBy(fn (EditTarget $t): string => $t->path);

        $this->assertSame('rate', $byPath['assumptionOverrides.inflation']->type);
        $this->assertSame('Inflation (CPI)', $byPath['assumptionOverrides.inflation']->label);
        $this->assertSame('2.5%', $byPath['assumptionOverrides.inflation']->currentDisplay());
    }

    public function test_a_what_if_child_is_offered_its_bases_menu(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);
        $child = WhatIfWriter::create($base, 'A what-if', ['people.p1.grossSalary' => '70000']);

        // A change proposed from a child's results page still edits the base's fields (what-ifs
        // stay two-level), so the menu is the base's, with the base's own values.
        $byPath = collect(ScenarioEditVocabulary::for($child))->keyBy(fn (EditTarget $t): string => $t->path);

        $this->assertSame('£62,000', $byPath['people.p1.grossSalary']->currentDisplay());
    }
}
