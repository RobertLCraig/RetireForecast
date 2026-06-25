<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioBuilder;
use App\Models\ScenarioDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * The builder auto-saves an in-progress forecast so navigation / an accidental leave /
 * a closed tab never loses work — the draft is the raw form state, deleted only when the
 * forecast is finally saved or explicitly discarded.
 */
class ScenarioDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_moving_between_steps_saves_a_draft(): void
    {
        $this->assertSame(0, ScenarioDraft::count());

        Livewire::test(ScenarioBuilder::class)
            ->set('name', 'My WIP forecast')
            ->set('householdName', 'The Smiths')
            ->call('nextStep');

        $draft = ScenarioDraft::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('My WIP forecast', $draft->payload['name']);
        $this->assertSame('The Smiths', $draft->payload['householdName']);
        $this->assertSame(2, $draft->payload['step']);
    }

    public function test_a_returning_user_resumes_their_draft_on_mount(): void
    {
        ScenarioDraft::create([
            'user_id' => $this->user->id,
            'payload' => [
                'step' => 3,
                'name' => 'Resumed forecast',
                'people' => [['id' => 'p1', 'name' => 'Alex', 'dob' => '1960-01-01', 'sex' => 'male',
                    'employmentStatus' => 'retired', 'grossSalary' => '', 'salaryGrowth' => '',
                    'plannedRetirementAge' => '', 'niCategory' => '']],
            ],
        ]);

        Livewire::test(ScenarioBuilder::class)
            ->assertSet('name', 'Resumed forecast')
            ->assertSet('step', 3)
            ->assertSet('people.0.name', 'Alex');
    }

    public function test_one_draft_per_user_is_kept_updated_not_duplicated(): void
    {
        Livewire::test(ScenarioBuilder::class)->set('name', 'first')->call('nextStep');
        Livewire::test(ScenarioBuilder::class)->set('name', 'second')->call('nextStep');

        $this->assertSame(1, ScenarioDraft::where('user_id', $this->user->id)->count());
    }

    public function test_discarding_deletes_the_draft(): void
    {
        ScenarioDraft::create(['user_id' => $this->user->id, 'payload' => ['name' => 'x']]);

        Livewire::test(ScenarioBuilder::class)
            ->call('discardDraft')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, ScenarioDraft::count());
    }

    public function test_saving_the_forecast_clears_the_draft(): void
    {
        ScenarioDraft::create(['user_id' => $this->user->id, 'payload' => ['name' => 'old draft']]);

        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('save');

        $this->assertSame(0, ScenarioDraft::count());
    }
}
