<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\ScenarioAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
