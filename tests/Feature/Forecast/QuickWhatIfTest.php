<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\QuickWhatIf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuilderStateFixture;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * One-click what-ifs ("retire 2 years later", "live 10 years longer"): a preset edits the
 * base's people and the result is stored as an ordinary delta-child, so it carries exactly
 * the minimal override delta and opens like any other what-if. The properties that matter:
 * each preset moves the right lever for the people it can; a preset that would change nothing
 * makes nothing; and the endpoint is owner-scoped.
 */
class QuickWhatIfTest extends TestCase
{
    use RefreshDatabase;

    public function test_retire_later_pushes_each_working_persons_retirement_age_out(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());

        $built = QuickWhatIf::build($base, 'retire_2_years_later');

        // P1 is employed retiring at 66 -> 68; P2 is retired with no age, so is left alone.
        $this->assertSame('Retire 2 years later', $built['name']);
        $this->assertSame(['people.p1.plannedRetirementAge' => '68'], $built['overrides']);
    }

    public function test_live_longer_extends_each_persons_lifespan_via_the_offset_lever(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());

        $built = QuickWhatIf::build($base, 'live_10_years_longer');

        // Both partners move onto a +10-year offset (the fixture leaves longevity at peer default).
        $this->assertSame('Live 10 years longer', $built['name']);
        $this->assertEqualsCanonicalizing([
            'people.p1.longevityMode', 'people.p1.longevityValue',
            'people.p2.longevityMode', 'people.p2.longevityValue',
        ], array_keys($built['overrides']));
        $this->assertSame('offset_years', $built['overrides']['people.p1.longevityMode']);
        $this->assertSame('10', $built['overrides']['people.p2.longevityValue']);
    }

    public function test_a_preset_that_would_change_nothing_builds_nothing(): void
    {
        // A lone retired person with no retirement age: "retire later" has nothing to move.
        $base = ScenarioFixture::fromState(User::factory()->create(), BuilderStateFixture::minimalValid());

        $this->assertNull(QuickWhatIf::build($base, 'retire_2_years_later'));
        $this->assertNull(QuickWhatIf::build($base, 'no_such_preset'));
    }

    public function test_the_endpoint_creates_a_delta_child_and_opens_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        $this->post(route('scenarios.whatif.quick', $base), ['preset' => 'retire_2_years_later'])
            ->assertRedirect();

        $child = $base->children()->firstOrFail();
        $this->assertSame('Retire 2 years later', $child->name);
        $this->assertSame(['name' => 'Retire 2 years later', 'people.p1.plannedRetirementAge' => '68'], $child->overrides);
        // The child resolves the override on top of the base (effective inputs reflect it).
        $this->assertSame('68', $child->effectiveBuilderState()['people'][0]['plannedRetirementAge']);
    }

    public function test_repeated_quick_what_ifs_get_distinct_names(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        $this->post(route('scenarios.whatif.quick', $base), ['preset' => 'live_10_years_longer']);
        $this->post(route('scenarios.whatif.quick', $base), ['preset' => 'live_10_years_longer']);

        $names = $base->children()->pluck('name')->all();
        $this->assertContains('Live 10 years longer', $names);
        $this->assertContains('Live 10 years longer (2)', $names);
    }

    public function test_a_no_op_preset_creates_no_child(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::fromState($user, BuilderStateFixture::minimalValid());

        $this->post(route('scenarios.whatif.quick', $base), ['preset' => 'retire_2_years_later'])
            ->assertRedirect();

        $this->assertSame(0, $base->children()->count());
    }

    public function test_the_endpoint_is_owner_scoped(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());
        $this->actingAs(User::factory()->create());

        $this->post(route('scenarios.whatif.quick', $base), ['preset' => 'retire_2_years_later'])
            ->assertForbidden();

        $this->assertSame(0, $base->children()->count());
    }
}
