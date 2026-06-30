<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Livewire\ScenarioCompare;
use App\Livewire\ScenarioResults;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The admin-granted, off-by-default interpretation capability. The public default stays
 * neutral; the advice-style readouts appear only behind the `interpret` Gate and live
 * solely in the walled-off interpretation layer (DECISIONS 2026-06-25).
 */
class InterpretationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_capability_is_off_by_default_and_the_gate_denies(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can_interpret);
        $this->assertFalse(Gate::forUser($user)->allows('interpret'));
    }

    public function test_an_admin_granted_user_passes_the_gate(): void
    {
        $user = User::factory()->canInterpret()->create();

        $this->assertTrue(Gate::forUser($user)->allows('interpret'));
    }

    public function test_personal_use_mode_opens_the_capability_to_everyone(): void
    {
        // The single regulatory-line switch (config/compliance.php): in personal-use mode the
        // advice capability is on without an admin grant. (The suite default is the public
        // posture, so the tests above still exercise the off-by-default guidance behaviour.)
        config()->set('compliance.personal_use', true);
        $user = User::factory()->create();

        $this->assertFalse($user->can_interpret);
        $this->assertTrue(Gate::forUser($user)->allows('interpret'));
    }

    public function test_personal_use_mode_shows_the_advice_narrative_in_compare(): void
    {
        config()->set('compliance.personal_use', true);
        $user = User::factory()->create();
        $this->actingAs($user);

        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put']);
        $this->post(route('scenarios.compare.housing', $base)); // generate buy + rent strategy children

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertSee('What this suggests')        // the walled-off advice section
            ->assertSee('is the strongest plan');    // a directive recommendation
    }

    public function test_compare_stays_neutral_in_the_public_posture(): void
    {
        // With personal-use off (the suite default) and no per-user grant, Compare shows no advice.
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put']);
        $this->post(route('scenarios.compare.housing', $base));

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertDontSee('What this suggests')
            ->assertDontSee('is the strongest plan');
    }

    public function test_results_stay_neutral_without_the_capability(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenarioFor($user)])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Neutral guidance')
            ->assertDontSee('What this suggests')
            ->assertDontSee('you should');
    }

    public function test_a_granted_user_sees_the_walled_off_interpretation(): void
    {
        $user = User::factory()->canInterpret()->create();
        $this->actingAs($user);

        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenarioFor($user)])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('What this suggests')
            ->assertSee('Interpretation (advice-style');
    }

    private function scenarioFor(User $user): Scenario
    {
        return ScenarioFixture::rich($user);
    }
}
