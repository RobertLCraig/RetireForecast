<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\QuickWhatIf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
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

    public function test_let_out_and_rent_keeps_the_home_and_adds_rental_income_and_rent(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create());

        $built = QuickWhatIf::build($base, 'let_out_and_rent');

        $this->assertSame('Let out & rent elsewhere', $built['name']);
        $overrides = $built['overrides'];

        // Keep the flat (do not sell).
        $this->assertSame('stay_put', $overrides['variant']);

        // A taxable rental income stream is added, plus a "Rent (our home)" essential cost.
        $addedRows = array_filter($overrides, 'is_array');
        $rental = array_filter($addedRows, fn ($v): bool => ($v['type'] ?? null) === 'rental');
        $rent = array_filter($addedRows, fn ($v): bool => ($v['label'] ?? null) === 'Rent (our home)');

        $this->assertCount(1, $rental);
        $this->assertTrue((bool) reset($rental)['taxable']);
        $this->assertCount(1, $rent);
        $this->assertSame('essential', reset($rent)['category']);
    }

    public function test_the_attendance_allowance_preset_claims_it_later_in_life_with_its_own_money(): void
    {
        // Card 0044. Claiming Attendance Allowance as health declines is the largest favourable
        // event a long survivor period can carry, and it could not be modelled at all: the flag was
        // on or off for life, and nothing offered it as a what-if.
        $base = ScenarioFixture::rich(User::factory()->create());

        $built = QuickWhatIf::build($base, 'claim_attendance_allowance');

        $this->assertStringContainsString('Attendance Allowance', $built['name']);
        $overrides = $built['overrides'];

        // Both partners claim, from the same age, and the flag carries its start age with it.
        foreach (['p1', 'p2'] as $id) {
            $this->assertTrue($overrides["people.{$id}.receivesDisabilityBenefit"]);
            $this->assertSame('80', $overrides["people.{$id}.disabilityBenefitFromAge"]);
        }

        // The benefit's own money arrives too, as a tax-free stream starting at the same age. It is
        // the lower rate, the cautious one, and it still qualifies for the Pension Credit addition.
        $rate = TaxYearRegistry::for($base->base_tax_year)->benefits;
        $expected = (string) round($rate->attendanceAllowanceLowerWeekly->pence * 52 / 100);
        $streams = array_filter($overrides, fn ($v): bool => is_array($v) && ($v['type'] ?? null) === 'disability_benefit');

        $this->assertCount(2, $streams);
        foreach ($streams as $stream) {
            $this->assertFalse($stream['taxable']);
            $this->assertSame('80', $stream['startAge']);
            $this->assertSame($expected, $stream['grossAnnual']);
        }
    }

    public function test_the_attendance_allowance_preset_leaves_an_existing_claim_alone(): void
    {
        // Nothing to model for somebody who already gets it, so no empty what-if is made.
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['receivesDisabilityBenefit'] = true;
        $base = ScenarioFixture::fromState(User::factory()->create(), $state);

        $this->assertNull(QuickWhatIf::build($base, 'claim_attendance_allowance'));
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
