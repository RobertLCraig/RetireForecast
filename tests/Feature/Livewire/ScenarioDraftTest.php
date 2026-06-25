<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * The builder auto-saves an in-progress forecast so navigation / an accidental leave /
 * a closed tab never loses work. With storage inverted (Phase B) the draft is just a
 * `draft`-status scenario holding the raw form-state; saving promotes it to `ready`
 * (not a duplicate), and discarding deletes it.
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

    /** @return Builder<Scenario> */
    private function drafts(): Builder
    {
        return Scenario::query()->where('user_id', $this->user->id)->where('status', ScenarioStatus::Draft);
    }

    public function test_moving_between_steps_saves_a_draft(): void
    {
        $this->assertSame(0, $this->drafts()->count());

        Livewire::test(ScenarioBuilder::class)
            ->set('name', 'My WIP forecast')
            ->set('householdName', 'The Smiths')
            ->call('nextStep');

        $draft = $this->drafts()->firstOrFail();
        $this->assertSame('My WIP forecast', $draft->builder_state['name']);
        $this->assertSame('The Smiths', $draft->builder_state['householdName']);
        $this->assertSame(2, $draft->builder_state['step']);
        $this->assertSame('My WIP forecast', $draft->name); // projected to the clear column
    }

    public function test_a_returning_user_resumes_their_draft_on_mount(): void
    {
        $draft = new Scenario;
        $draft->user_id = $this->user->id;
        $draft->fillFromBuilderState([
            'step' => 3,
            'name' => 'Resumed forecast',
            'people' => [['id' => 'p1', 'name' => 'Alex', 'dob' => '1960-01-01', 'sex' => 'male',
                'employmentStatus' => 'retired', 'grossSalary' => '', 'salaryGrowth' => '',
                'plannedRetirementAge' => '', 'niCategory' => '']],
        ]);
        $draft->status = ScenarioStatus::Draft;
        $draft->save();

        Livewire::test(ScenarioBuilder::class)
            ->assertSet('name', 'Resumed forecast')
            ->assertSet('step', 3)
            ->assertSet('people.0.name', 'Alex');
    }

    public function test_one_draft_per_user_is_kept_updated_not_duplicated(): void
    {
        Livewire::test(ScenarioBuilder::class)->set('name', 'first')->call('nextStep');
        Livewire::test(ScenarioBuilder::class)->set('name', 'second')->call('nextStep');

        $this->assertSame(1, $this->drafts()->count());
    }

    public function test_discarding_deletes_the_draft(): void
    {
        Livewire::test(ScenarioBuilder::class)->set('name', 'x')->call('nextStep');
        $this->assertSame(1, $this->drafts()->count());

        Livewire::test(ScenarioBuilder::class)
            ->call('discardDraft')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, $this->drafts()->count());
    }

    public function test_saving_the_forecast_promotes_the_draft_to_ready(): void
    {
        $component = Livewire::test(ScenarioBuilder::class)->set('name', 'draft')->call('nextStep');
        $this->assertSame(1, $this->drafts()->count());

        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('save');

        $this->assertSame(0, $this->drafts()->count());
        $this->assertSame(1, Scenario::query()->where('status', ScenarioStatus::Ready)->count());
        // The draft became the ready scenario — not a second row.
        $this->assertSame(1, Scenario::count());
    }
}
