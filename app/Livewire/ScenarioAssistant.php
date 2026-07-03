<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Assistant\AssistantService;
use App\Assistant\OllamaChatClient;
use App\Assistant\ScenarioContext;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * The in-page assistant: a plain-English explainer over THIS scenario's forecast, running on a
 * LOCAL model. It only explains — it cannot change the plan, run anything, or build anything.
 *
 * It is inert unless `config('assistant.enabled')` is on (so a machine without the local runtime
 * shows nothing). Every figure it may state is engine-derived and re-verified at runtime; the
 * advice-vs-guidance line is the app's own `interpret` gate, passed straight through. The
 * trust-critical work lives in {@see AssistantService} (unit-tested with a fake model); this
 * component is thin glue that builds the local client from config and renders the transcript.
 */
class ScenarioAssistant extends Component
{
    public Scenario $scenario;

    /** Whether the side panel is expanded. Collapsed to a launcher button by default so it
     *  stays out of the way until the reader wants it. */
    public bool $open = false;

    public string $question = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * The visible transcript. Each entry: role (user|assistant), the text, and — for an
     * assistant turn — the guardrail status (answered | unavailable | ungrounded_refused |
     * phrasing_refused), so a held-back or unreachable reply is shown as such, never as a
     * silent blank.
     *
     * @var list<array{role: string, text: string, status: string}>
     */
    public array $messages = [];

    public function ask(): void
    {
        if (! config('assistant.enabled')) {
            return;
        }

        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        // Capture prior turns as history BEFORE appending this question (the service adds the
        // new question itself, so including it here would double it).
        $history = $this->historyForModel();

        $this->messages[] = ['role' => 'user', 'text' => $question, 'status' => 'user'];
        $this->question = '';

        $forecaster = app(ScenarioForecaster::class);
        $context = ScenarioContext::for(
            $this->scenario,
            $forecaster,
            $this->simulationResult(),
            app(LumpSumTaxShock::class)->assess($this->scenario),
            $this->saleExplainer($forecaster),
        );
        $answer = $this->service()->answer(
            $context,
            $question,
            adviceAllowed: Gate::allows('interpret'),
            history: $history,
        );

        $this->messages[] = ['role' => 'assistant', 'text' => $answer->text, 'status' => $answer->status];
    }

    /**
     * The latest completed Monte Carlo run's aggregate for this scenario's own variant, or null
     * if no run has finished (the deterministic context still stands). Mirrors how the results
     * page resolves its results ({@see ScenarioResults}).
     */
    private function simulationResult(): ?SimulationResult
    {
        $run = $this->scenario->latestCompletedRun();
        if ($run === null) {
            return null;
        }

        $byVariant = $run->results->keyBy(fn (Result $r): string => $r->variant->value);
        $result = $byVariant[$this->scenario->variant->value] ?? $run->results->first();

        return $result?->simulationResult();
    }

    /**
     * The home-sale waterfall for this scenario's strategy, or null when it isn't a sell strategy
     * ({@see ResultPresenter::saleExplainer()} returns null on a zero sale price). Assembled exactly
     * as the results page does, so the assistant's figures are the sale-waterfall panel's.
     *
     * @return array<string, mixed>|null
     */
    private function saleExplainer(ScenarioForecaster $forecaster): ?array
    {
        $household = $this->scenario->toHousehold();
        $action = $this->scenario->toHousingAction();
        $assumptions = $forecaster->assumptions($this->scenario);
        $allocation = $forecaster->settings($this->scenario)->allocation();
        $housing = $forecaster->housingComparison($this->scenario);

        return ResultPresenter::saleExplainer(
            $housing->saleProceeds($household, $action),
            $housing->buyOutcome($household, $action),
            $action,
            $allocation->blendedRealReturn($assumptions),
            $assumptions->investmentIncomeYield->asFraction(),
        );
    }

    private function service(): AssistantService
    {
        $client = new OllamaChatClient(
            (string) config('assistant.base_url'),
            (string) config('assistant.model'),
            (int) config('assistant.timeout'),
            (int) config('assistant.probe_timeout'),
        );

        return new AssistantService($client);
    }

    /**
     * Prior turns as model history — user turns and only ANSWERED assistant turns (a refusal or
     * an "unavailable" is not real forecast context), capped to the recent window.
     *
     * @return list<array{role: string, content: string}>
     */
    private function historyForModel(): array
    {
        $history = [];
        foreach (array_slice($this->messages, -6) as $m) {
            if ($m['role'] === 'user') {
                $history[] = ['role' => 'user', 'content' => $m['text']];
            } elseif ($m['status'] === 'answered') {
                $history[] = ['role' => 'assistant', 'content' => $m['text']];
            }
        }

        return $history;
    }

    public function render(): View
    {
        return view('livewire.scenario-assistant');
    }
}
