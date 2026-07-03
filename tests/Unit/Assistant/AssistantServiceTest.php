<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\AssistantService;
use App\Assistant\ScenarioContext;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;
use Tests\Support\FakeChatClient;

/**
 * The assistant's safety contract, proven without a running model. The headline invariant —
 * the same shape as the engine's reconciliation invariants — is that a figure the model
 * invented is NEVER surfaced: it is held back after a corrective retry fails. Alongside that:
 * a grounded answer passes, a retry can recover, recommendation phrasing is blocked in
 * guidance-only mode but allowed in advice mode, and an unreachable model reports itself.
 */
final class AssistantServiceTest extends TestCase
{
    private ScenarioContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        // Central forecast: lasts to 2058, £154,600.00 spendable / £200,000.00 total left.
        $forecast = new ForecastResult(
            years: [],
            essentialsAlwaysMet: true,
            fullSpendAlwaysMet: true,
            depletionCalendarYear: null,
            terminalTotalWealth: Money::fromPence(20_000_000),
            terminalUsableWealth: Money::fromPence(15_460_000),
            finalCalendarYear: 2058,
        );
        $this->context = ScenarioContext::fromForecast('My Plan', 'Stay put', $forecast);
    }

    public function test_a_grounded_answer_is_returned(): void
    {
        $client = new FakeChatClient(['Your money lasts to 2058 with £154,600.00 spendable left.']);

        $answer = (new AssistantService($client))->answer($this->context, 'Does my money last?', adviceAllowed: true);

        $this->assertTrue($answer->ok);
        $this->assertSame('answered', $answer->status);
        $this->assertStringContainsString('£154,600.00', $answer->text);
        $this->assertSame(1, $client->calls());
    }

    public function test_an_invented_figure_is_never_surfaced(): void
    {
        // The model insists on a hallucinated figure on every attempt.
        $client = new FakeChatClient(['Your wealth grows to £999,999.00 by 2099.']);

        $answer = (new AssistantService($client))->answer($this->context, 'How much will I have?', adviceAllowed: true);

        $this->assertFalse($answer->ok);
        $this->assertSame('ungrounded_refused', $answer->status);
        // The invented figures never reach the reader.
        $this->assertStringNotContainsString('999,999', $answer->text);
        $this->assertStringNotContainsString('2099', $answer->text);
        $this->assertNotSame([], $answer->warnings);
        // It tried once, then gave the model one corrective retry before refusing.
        $this->assertSame(2, $client->calls());
    }

    public function test_a_corrective_retry_can_recover_a_grounded_answer(): void
    {
        $client = new FakeChatClient([
            'You will have £999,999.00 left.',                 // ungrounded — rejected
            'Your money lasts to 2058 with £154,600.00 left.', // grounded — accepted
        ]);

        $answer = (new AssistantService($client))->answer($this->context, 'How much will I have?', adviceAllowed: true);

        $this->assertTrue($answer->ok);
        $this->assertSame('answered', $answer->status);
        $this->assertStringContainsString('£154,600.00', $answer->text);
        $this->assertSame(2, $client->calls());
    }

    public function test_recommendation_phrasing_is_blocked_in_guidance_only_mode(): void
    {
        $client = new FakeChatClient(['You should sell the house.']);

        $answer = (new AssistantService($client))->answer($this->context, 'What do I do?', adviceAllowed: false);

        $this->assertFalse($answer->ok);
        $this->assertSame('phrasing_refused', $answer->status);
        $this->assertStringNotContainsString('you should', strtolower($answer->text));
    }

    public function test_a_direct_steer_is_allowed_in_advice_mode(): void
    {
        $client = new FakeChatClient(['You should be fine — it lasts to 2058.']);

        $answer = (new AssistantService($client))->answer($this->context, 'Am I okay?', adviceAllowed: true);

        $this->assertTrue($answer->ok);
        $this->assertSame('answered', $answer->status);
    }

    public function test_an_unreachable_model_reports_itself_without_generating(): void
    {
        $client = new FakeChatClient(available: false);

        $answer = (new AssistantService($client))->answer($this->context, 'Anything?', adviceAllowed: true);

        $this->assertFalse($answer->ok);
        $this->assertSame('unavailable', $answer->status);
        $this->assertSame(0, $client->calls());
    }

    public function test_a_mid_generation_failure_reports_unavailable(): void
    {
        $client = new FakeChatClient(replies: ['irrelevant'], available: true, throwOnChat: true);

        $answer = (new AssistantService($client))->answer($this->context, 'Anything?', adviceAllowed: true);

        $this->assertFalse($answer->ok);
        $this->assertSame('unavailable', $answer->status);
    }

    public function test_a_methodology_figure_is_grounded_when_the_methodology_block_is_attached(): void
    {
        // Phase 2: the figure is not in the scenario CONTEXT — the retrieved methodology supplies it,
        // and folding methodology into the grounding source is what lets the model state it.
        $methodology = "METHODOLOGY — how the tool works:\n\nFrom ASSUMPTIONS.md: the personal allowance the engine uses is £12,570.";
        $client = new FakeChatClient(['The personal allowance the tool applies is £12,570.']);

        $answer = (new AssistantService($client))->answer(
            $this->context,
            'What personal allowance does it use?',
            adviceAllowed: true,
            methodology: $methodology,
        );

        $this->assertTrue($answer->ok);
        $this->assertSame('answered', $answer->status);
        $this->assertStringContainsString('£12,570', $answer->text);
        $this->assertSame(1, $client->calls());
    }

    public function test_the_same_methodology_figure_is_refused_without_the_block(): void
    {
        // The converse proves the block is what widened grounding: with no methodology attached,
        // £12,570 is a figure absent from the scenario CONTEXT, so it is held back.
        $client = new FakeChatClient(['The personal allowance the tool applies is £12,570.']);

        $answer = (new AssistantService($client))->answer(
            $this->context,
            'What personal allowance does it use?',
            adviceAllowed: true,
        );

        $this->assertFalse($answer->ok);
        $this->assertSame('ungrounded_refused', $answer->status);
    }
}
