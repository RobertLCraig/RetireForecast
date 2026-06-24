<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Enums\SimulationStatus;
use App\Jobs\RunScenarioSimulation;
use App\Livewire\ScenarioResults;
use App\Models\Household;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class ScenarioResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_a_preview_runs_and_renders_headline_numbers_as_text(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Will the money last?')
            ->assertSee('Essentials always met')
            ->assertSee('Sell & rent')
            ->assertSee('Stay put')
            ->assertSee('%');
    }

    public function test_the_fan_chart_ships_with_an_accessible_data_table(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Projected wealth over time')
            ->assertSeeHtml('<table')
            ->assertSeeHtml('<caption')
            ->assertSee('Median')
            ->assertSee('Show the numbers behind this chart');
    }

    public function test_a_completed_preview_persists_three_variant_results(): void
    {
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview');

        $run = SimulationRun::findOrFail($component->get('runId'));
        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(3, $run->results()->count());
    }

    public function test_the_full_run_is_queued_then_can_be_cancelled(): void
    {
        Queue::fake();
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()]);

        $component->call('runFull');
        Queue::assertPushed(RunScenarioSimulation::class);
        $run = SimulationRun::findOrFail($component->get('runId'));
        $this->assertSame(SimulationStatus::Queued, $run->status);

        $component->call('cancel');
        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);
    }

    public function test_the_results_page_shows_run_controls_before_any_run(): void
    {
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Run a quick preview')
            ->assertSee('No completed run yet.');
    }

    public function test_a_user_cannot_view_another_users_results(): void
    {
        $scenario = $this->scenario();
        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.results', $scenario))->assertForbidden();
    }

    private function scenario(): Scenario
    {
        $household = Household::fromDto(HouseholdFixture::household(), $this->user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $this->user->id,
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
