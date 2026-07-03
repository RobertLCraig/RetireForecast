<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Assistant\AssistantService;
use App\Assistant\OllamaChatClient;
use App\Assistant\ScenarioContext;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

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

        $context = ScenarioContext::for($this->scenario, app(ScenarioForecaster::class));
        $answer = $this->service()->answer(
            $context,
            $question,
            adviceAllowed: Gate::allows('interpret'),
            history: $history,
        );

        $this->messages[] = ['role' => 'assistant', 'text' => $answer->text, 'status' => $answer->status];
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
