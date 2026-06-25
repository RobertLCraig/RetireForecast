<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Livewire\ScenarioResults;
use App\Models\Household;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\HouseholdFixture;
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
        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Buy-vs-rent',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2026-27',
            'iht_modelled' => false,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $scenario->fresh();
    }
}
