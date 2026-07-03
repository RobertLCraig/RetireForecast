<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\BacklogItemKind;
use App\Livewire\ScenarioAssistant;
use App\Models\AssistantBacklogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;
use Tests\Unit\Assistant\AssistantServiceTest;

/**
 * The assistant panel's UI behaviour (the model itself is inert in tests — {@see AssistantServiceTest}
 * proves the answering path with a fake client). These pin the docked-panel affordances Rob asked for:
 * a starting state of grouped starter questions, a Clear that wipes the transcript, and the home-sale
 * starter shown only when the plan actually sells.
 */
final class ScenarioAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_opening_the_panel_reveals_grouped_starter_questions(): void
    {
        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->assertSee('Ask about this forecast')          // the docked tab, collapsed
            ->assertDontSeeHtml('data-assistant-open')      // no open-panel marker while collapsed
            ->call('toggle')
            ->assertSeeHtml('data-assistant-open')          // the marker the page-shrink CSS keys off
            ->assertSee('Does my money last, and until when?')
            ->assertSee('Risks worth checking')             // the COBS-risk-warning / adviser group
            ->assertSee('Could my money run out')
            ->assertSee('What assumptions is this forecast based on?');
    }

    public function test_clear_appears_with_a_transcript_and_wipes_it(): void
    {
        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->set('messages', [['role' => 'user', 'text' => 'a past question', 'status' => 'user']])
            ->assertSee('Clear')
            ->assertSee('a past question')
            ->call('clear')
            ->assertSet('messages', [])
            ->assertDontSee('a past question')
            ->assertSee('Does my money last, and until when?');   // back to the starter state
    }

    public function test_no_clear_button_before_any_conversation(): void
    {
        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->assertDontSee('Clear');
    }

    public function test_the_home_sale_starter_shows_only_for_a_sell_strategy(): void
    {
        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])  // rent = a sell strategy
            ->set('open', true)
            ->assertSee('If I sell the home');

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user, ['variant' => 'stay_put'])])
            ->set('open', true)
            ->assertDontSee('If I sell the home');
    }

    public function test_compare_mode_offers_comparison_starter_questions(): void
    {
        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user), 'compare' => true])
            ->set('open', true)
            ->assertSee('Compare the plans')
            ->assertSee('Which plan leaves the most money at the end?')
            ->assertDontSee('Does my money last, and until when?');   // the single-scenario starters are replaced
    }

    // --- Phase 3: idea capture (the model's only write) ---

    public function test_capturing_an_idea_queues_it_and_shows_it_in_the_list(): void
    {
        config(['assistant.enabled' => true]);
        // Model unreachable → the capture falls back to storing the raw idea as a Task (never lost).
        Http::fake(['*' => Http::response('', 500)]);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->set('tab', 'ideas')
            ->set('idea', 'Could it model equity release?')
            ->call('captureIdea')
            ->assertSet('idea', '')
            ->assertSee('Added to the backlog')
            ->assertSee('Could it model equity release?');

        $this->assertDatabaseHas('assistant_backlog_items', [
            'user_id' => $this->user->id,
            'kind' => 'task',
            'title' => 'Could it model equity release?',
            'source' => 'Could it model equity release?',
        ]);
    }

    public function test_capture_is_inert_when_the_assistant_is_disabled(): void
    {
        config(['assistant.enabled' => false]);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('tab', 'ideas')
            ->set('idea', 'Something')
            ->call('captureIdea');

        $this->assertDatabaseCount('assistant_backlog_items', 0);
    }

    public function test_the_ideas_list_shows_only_this_users_items_and_can_delete_them(): void
    {
        $other = User::factory()->create();
        AssistantBacklogItem::create(['user_id' => $other->id, 'kind' => BacklogItemKind::Task, 'title' => 'Someone elses idea', 'source' => 'x']);
        $mine = AssistantBacklogItem::create(['user_id' => $this->user->id, 'kind' => BacklogItemKind::Feature, 'title' => 'My own idea', 'source' => 'y']);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->set('tab', 'ideas')
            ->assertSee('My own idea')
            ->assertDontSee('Someone elses idea')
            ->call('deleteIdea', $mine->id)
            ->assertDontSee('My own idea');

        $this->assertDatabaseMissing('assistant_backlog_items', ['id' => $mine->id]);
    }

    public function test_cannot_delete_another_users_idea(): void
    {
        $other = User::factory()->create();
        $theirs = AssistantBacklogItem::create(['user_id' => $other->id, 'kind' => BacklogItemKind::Task, 'title' => 'Not mine', 'source' => 'x']);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->call('deleteIdea', $theirs->id);

        $this->assertDatabaseHas('assistant_backlog_items', ['id' => $theirs->id]);
    }
}
