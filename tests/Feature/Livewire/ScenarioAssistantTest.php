<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\BacklogItemKind;
use App\Enums\SimulationStatus;
use App\Livewire\ScenarioAssistant;
use App\Models\AssistantBacklogItem;
use App\Models\AssistantTurn;
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

    // --- The queued turn (the generation runs on the worker, the panel polls — 2026-07-08) ---
    // The test queue is sync, so dispatch executes the turn inline: after ask() the row is
    // already terminal and pollTurn() collects it — the full queue-and-poll loop in one pass.

    public function test_ask_queues_a_turn_and_the_poll_delivers_the_answer(): void
    {
        config(['assistant.enabled' => true]);
        Http::fake([
            '*/api/tags' => Http::response(['models' => []]),
            '*/api/chat' => Http::response(['message' => ['content' => 'Your plan holds up across the years shown.']]),
        ]);

        $component = Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->call('ask', 'Does my money last?')
            ->assertSee('Does my money last?')      // the user bubble is immediate
            ->assertSee('Thinking…');               // the pending state renders while the poll waits

        $this->assertNotNull($component->get('pendingTurnId'));

        $component->call('pollTurn')
            ->assertSee('Your plan holds up across the years shown.')
            ->assertSet('pendingTurnId', null);

        // The row is transient: deleted the moment its answer joined the (browser-local) transcript.
        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public function test_ask_is_inert_when_the_assistant_is_disabled(): void
    {
        config(['assistant.enabled' => false]);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->call('ask', 'Does my money last?');

        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public function test_an_unreachable_model_surfaces_as_a_visible_non_answer(): void
    {
        config(['assistant.enabled' => true]);
        Http::fake(['*' => Http::response('', 500)]);   // Ollama down → the guarded "isn't running" answer

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->call('ask', 'Does my money last?')
            ->call('pollTurn')
            ->assertSee("The local assistant isn't running")
            ->assertSet('pendingTurnId', null);

        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public function test_clear_deletes_the_pending_turn_rows(): void
    {
        config(['assistant.enabled' => true]);
        Http::fake([
            '*/api/tags' => Http::response(['models' => []]),
            '*/api/chat' => Http::response(['message' => ['content' => 'An answer.']]),
        ]);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->call('ask', 'Does my money last?')
            ->call('clear')
            ->assertSet('pendingTurnId', null)
            ->assertSet('messages', []);

        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public function test_polling_cannot_read_another_users_turn(): void
    {
        $other = User::factory()->create();
        $theirs = AssistantTurn::create([
            'user_id' => $other->id,
            'scenario_id' => ScenarioFixture::rich($other)->id,
            'compare' => false,
            'status' => SimulationStatus::Done,
            'question' => 'Their question',
            'answer' => ['text' => 'Their private answer', 'status' => 'answered'],
        ]);

        Livewire::test(ScenarioAssistant::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->set('open', true)
            ->set('pendingTurnId', $theirs->id)
            ->call('pollTurn')
            ->assertDontSee('Their private answer')
            ->assertSet('pendingTurnId', null);

        // Their row is untouched — the poll neither read nor deleted it.
        $this->assertDatabaseHas('assistant_turns', ['id' => $theirs->id]);
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

    // --- The Change tab: propose a what-if, review it, then confirm (card 0020) ---

    /** Enable the Change tab with a model that returns $reply to the one extraction call. */
    private function fakeEditingModel(string $reply): void
    {
        config(['assistant.enabled' => true, 'assistant.can_edit_scenarios' => true]);
        Http::fake([
            '*/api/tags' => Http::response(['models' => []]),
            '*/api/chat' => Http::response(['message' => ['content' => $reply]]),
        ]);
    }

    public function test_a_proposed_change_is_shown_for_review_and_writes_nothing_until_it_is_confirmed(): void
    {
        $this->fakeEditingModel('{"edits":[{"field":"people.p1.plannedRetirementAge","value":"68"}],"question":""}');
        $base = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ScenarioAssistant::class, ['scenario' => $base])
            ->set('open', true)
            ->call('switchTab', 'change')
            ->set('changeRequest', 'what if I retire at 68?')
            ->call('proposeChange')
            ->assertSee('Check this before it is created')
            ->assertSee('P1 · planned retirement age')
            ->assertSee('66')                                  // the value it changes FROM
            ->assertSee('68')                                  // the value it changes TO
            ->assertSet('proposedEdits', ['people.p1.plannedRetirementAge' => '68']);

        // Guardrail C3: the proposal exists only in the panel. Nothing is stored, and the base
        // plan is untouched.
        $this->assertSame(0, $base->children()->count());
        $this->assertSame('66', $base->fresh()->effectiveBuilderState()['people'][0]['plannedRetirementAge']);

        $component->call('confirmChange')->assertRedirect();

        $child = $base->children()->firstOrFail();
        $this->assertSame('what if I retire at 68?', $child->name);
    }

    public function test_a_confirmed_change_is_stored_exactly_like_a_hand_built_what_if(): void
    {
        $this->fakeEditingModel('{"edits":[{"field":"expenseLines.ess1.amount","value":"32000"}],"question":""}');
        $base = ScenarioFixture::rich($this->user);

        Livewire::test(ScenarioAssistant::class, ['scenario' => $base])
            ->set('open', true)
            ->call('switchTab', 'change')
            ->set('changeRequest', 'essentials to £32k')
            ->call('proposeChange')
            ->call('confirmChange');

        // A delta-child on the same builder-state model the UI writes: its own builder_state is
        // empty, its inputs are the base's overlaid with a sparse dot-path override.
        $child = $base->children()->firstOrFail();
        $this->assertSame($base->id, $child->parent_scenario_id);
        $this->assertSame([], $child->builder_state);
        $this->assertSame(['name' => 'essentials to £32k', 'expenseLines.ess1.amount' => '32000'], $child->overrides);
        $this->assertSame('32000', $child->effectiveBuilderState()['expenseLines'][0]['amount']);
        $this->assertSame('28000', $base->fresh()->effectiveBuilderState()['expenseLines'][0]['amount']);
    }

    public function test_a_refused_proposal_says_why_and_creates_nothing(): void
    {
        // A figure the reader never stated (guardrail C1) never reaches an override.
        $this->fakeEditingModel('{"edits":[{"field":"accounts.acc1.balance","value":"95000"}],"question":""}');
        $base = ScenarioFixture::rich($this->user);

        Livewire::test(ScenarioAssistant::class, ['scenario' => $base])
            ->set('open', true)
            ->call('switchTab', 'change')
            ->set('changeRequest', 'put my ISA up a bit')
            ->call('proposeChange')
            ->assertSet('proposedEdits', [])
            ->assertSee('only be guessing')
            ->assertDontSee('Check this before it is created');

        $this->assertSame(0, $base->children()->count());
    }

    public function test_discarding_a_proposal_leaves_nothing_behind(): void
    {
        $this->fakeEditingModel('{"edits":[{"field":"people.p1.plannedRetirementAge","value":"68"}],"question":""}');
        $base = ScenarioFixture::rich($this->user);

        Livewire::test(ScenarioAssistant::class, ['scenario' => $base])
            ->set('open', true)
            ->call('switchTab', 'change')
            ->set('changeRequest', 'retire at 68')
            ->call('proposeChange')
            ->call('discardChange')
            ->assertSet('proposedEdits', [])
            ->assertDontSee('Check this before it is created')
            ->call('confirmChange');

        $this->assertSame(0, $base->children()->count());
    }

    public function test_the_change_tab_is_absent_and_inert_unless_its_own_flag_is_on(): void
    {
        config(['assistant.enabled' => true, 'assistant.can_edit_scenarios' => false]);
        $base = ScenarioFixture::rich($this->user);

        Livewire::test(ScenarioAssistant::class, ['scenario' => $base])
            ->set('open', true)
            ->assertDontSee('Change plan')
            ->call('switchTab', 'change')
            ->assertSet('tab', 'ask')                       // an unknown tab falls back to Ask
            ->set('changeRequest', 'retire at 68')
            ->call('proposeChange')
            ->assertSet('proposedEdits', [])
            ->set('proposedEdits', ['people.p1.plannedRetirementAge' => '68'])
            ->call('confirmChange');

        $this->assertSame(0, $base->children()->count());
    }
}
