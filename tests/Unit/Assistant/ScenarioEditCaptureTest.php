<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\ScenarioEditCapture;
use App\Assistant\ScenarioEditGrounding;
use App\Assistant\ScenarioEditVocabulary;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeChatClient;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The assistant proposing a scenario edit, with a FAKE model (no runtime needed). What matters
 * is not that the model behaves — it is that nothing rests on it behaving: an off-menu target
 * (C2), a figure the reader never said (C1), an unreadable value and a no-op are each dropped,
 * and the reader gets a question back rather than a guess. Nothing here writes anything.
 */
final class ScenarioEditCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Scenario $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = ScenarioFixture::rich(User::factory()->create());
    }

    /** @param list<string> $replies */
    private function propose(array $replies, string $request): array
    {
        return (new ScenarioEditCapture(new FakeChatClient($replies)))
            ->propose(ScenarioEditVocabulary::for($this->base), $request);
    }

    public function test_it_maps_what_the_reader_said_onto_the_menu(): void
    {
        $proposal = $this->propose(
            ['{"edits":[{"field":"people.p1.plannedRetirementAge","value":"68"},{"field":"expenseLines.ess1.amount","value":"32000"}],"question":""}'],
            'retire at 68 and put essentials up to £32k',
        );

        // Form-state shape: plain strings on real dot-paths, ready to diff into an override map.
        $this->assertSame([
            'people.p1.plannedRetirementAge' => '68',
            'expenseLines.ess1.amount' => '32000',
        ], $proposal['edits']);
        $this->assertSame('', $proposal['question']);
    }

    public function test_it_normalises_the_readers_own_phrasing_of_a_figure(): void
    {
        $proposal = $this->propose(
            ['{"edits":[{"field":"accounts.acc1.balance","value":"£95,000"}],"question":""}'],
            'what if the ISA were £95,000',
        );

        $this->assertSame(['accounts.acc1.balance' => '95000'], $proposal['edits']);
    }

    public function test_a_target_that_is_not_on_the_menu_is_refused(): void
    {
        // C2: the model naming a real-looking path the menu does not offer changes nothing.
        $proposal = $this->propose(
            ['{"edits":[{"field":"people.p1.dob","value":"1961-04-02"}],"question":""}'],
            'make me two years younger',
        );

        $this->assertSame([], $proposal['edits']);
        $this->assertStringContainsString('only change the figures listed', $proposal['question']);
    }

    public function test_a_figure_the_reader_never_stated_is_refused(): void
    {
        // C1: the model supplying its own number is the input-side form of the failure G1 catches.
        $proposal = $this->propose(
            ['{"edits":[{"field":"accounts.acc1.balance","value":"95000"}],"question":""}'],
            'put my ISA up a bit',
        );

        $this->assertSame([], $proposal['edits']);
        $this->assertStringContainsString('only be guessing', $proposal['question']);
    }

    public function test_a_value_that_is_already_the_plans_value_creates_no_edit(): void
    {
        $proposal = $this->propose(
            ['{"edits":[{"field":"people.p1.plannedRetirementAge","value":"66"}],"question":""}'],
            'set my retirement age to 66',
        );

        $this->assertSame([], $proposal['edits']);
        $this->assertStringContainsString('already 66', $proposal['question']);
    }

    public function test_an_unreadable_value_is_refused_rather_than_guessed_at(): void
    {
        $proposal = $this->propose(
            ['{"edits":[{"field":"expenseLines.ess1.amount","value":"a bit more"}],"question":""}'],
            'spend a bit more on essentials',
        );

        $this->assertSame([], $proposal['edits']);
        $this->assertStringContainsString('Essentials', $proposal['question']);
    }

    public function test_an_ambiguous_request_comes_back_as_a_question(): void
    {
        $proposal = $this->propose(
            ['{"edits":[],"question":"Which account did you mean, the ISA or the cash?"}'],
            'increase my savings',
        );

        $this->assertSame([], $proposal['edits']);
        $this->assertSame('Which account did you mean, the ISA or the cash?', $proposal['question']);
    }

    public function test_an_unusable_model_reply_asks_again_rather_than_writing_a_guess(): void
    {
        // Unlike idea capture, there is no "save the raw text" fallback: an unclear edit must
        // not become a scenario (SE-8).
        $proposal = $this->propose(['I think you should probably retire later.'], 'retire at 68');

        $this->assertSame([], $proposal['edits']);
        $this->assertNotSame('', $proposal['question']);
    }

    public function test_an_unreachable_model_says_so_and_proposes_nothing(): void
    {
        $capture = new ScenarioEditCapture(new FakeChatClient([], available: false));

        $proposal = $capture->propose(ScenarioEditVocabulary::for($this->base), 'retire at 68');

        $this->assertSame([], $proposal['edits']);
        $this->assertStringContainsString("isn't running", $proposal['question']);
    }

    public function test_the_grounding_guard_accepts_only_figures_the_reader_stated(): void
    {
        $this->assertTrue(ScenarioEditGrounding::isStated('32000', 'essentials to £32k'));
        $this->assertTrue(ScenarioEditGrounding::isStated('410000', 'the pot is £410,000'));
        $this->assertTrue(ScenarioEditGrounding::isStated('3.5', 'assume 3.5% inflation'));
        $this->assertTrue(ScenarioEditGrounding::isStated('stay_put', 'keep the flat'));   // a menu pick, not a figure

        $this->assertFalse(ScenarioEditGrounding::isStated('33000', 'essentials to about £32k'));  // a rounding is the model's, not the reader's
        $this->assertFalse(ScenarioEditGrounding::isStated('41000', 'the pot is £410,000'));       // the transposition SE-1 names
    }
}
