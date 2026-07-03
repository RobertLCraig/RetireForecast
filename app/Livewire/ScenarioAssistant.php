<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Assistant\AssistantService;
use App\Assistant\ComparisonContext;
use App\Assistant\DocIndex;
use App\Assistant\MethodologyRetriever;
use App\Assistant\OllamaChatClient;
use App\Assistant\OllamaEmbeddingClient;
use App\Assistant\ScenarioContext;
use App\Enums\ScenarioStatus;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfChanges;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    /** Compare mode: $scenario is the base of a family, and the assistant reasons over ALL the compared
     *  plans (base + ready what-ifs) so it can answer comparison questions. Off = single-scenario explainer. */
    public bool $compare = false;

    /** Whether the docked side panel is expanded. Collapsed to an edge tab by default so it
     *  stays out of the content until the reader wants it (a docked panel, not a floating chat bubble). */
    public bool $open = false;

    public string $question = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Forget the conversation and return the panel to its starting (suggested-questions) state.
     * The transcript is local component state only — nothing is persisted server-side — so this
     * just clears it.
     */
    public function clear(): void
    {
        $this->messages = [];
        $this->question = '';
    }

    /**
     * Starter questions, grouped, shown when the transcript is empty (and again after Clear).
     * They double as a "what to ask about a retirement plan" prompt: the "Risks worth checking"
     * group is the plain-English form of the five COBS 9.4.10G drawdown risk warnings a firm must
     * give (capital may be eroded; returns may be less than illustrated; income may not be
     * sustainable; you may live longer than expected; there are tax implications) — the same
     * "take to Pension Wise / an adviser" material as the adviser-pack backlog
     * (docs/RESEARCH-delta-2026-07-02 §3). Every question is answerable from the grounded context
     * and free of directive phrasing (BannedPhrasingTest scans this file). The home-sale question
     * only shows for a sell strategy (a stay-put plan pockets nothing).
     *
     * @return list<array{heading: string, questions: list<string>}>
     */
    public function suggestions(): array
    {
        if ($this->compare) {
            return [
                [
                    'heading' => 'Compare the plans',
                    'questions' => [
                        'Which plan leaves the most money at the end?',
                        'Which plans keep the money going for life, and which run short?',
                        'Which plan covers my essential spending every year?',
                        'How do these plans differ from the base plan?',
                    ],
                ],
                [
                    'heading' => 'How the forecast is worked out',
                    'questions' => [
                        'What assumptions is this comparison based on?',
                        'What data do you use for how long we might live?',
                    ],
                ],
            ];
        }

        return [
            [
                'heading' => 'Your plan',
                'questions' => [
                    'Does my money last, and until when?',
                    'How much spendable money is left at the end?',
                    'What happens to my essential spending over the years?',
                ],
            ],
            [
                'heading' => 'Risks worth checking (and worth raising with Pension Wise or an adviser)',
                'questions' => [
                    'Could my money run out, and how likely is that?',
                    'What if investment returns are lower than assumed?',
                    'What if one of us lives a lot longer than expected?',
                    'What could paying for care later in life do to the plan?',
                    'How does inflation affect what I can spend?',
                ],
            ],
            [
                'heading' => 'Tax and the home',
                'questions' => array_values(array_filter([
                    'How much tax would I pay if I took a pension lump sum?',
                    $this->isSellStrategy() ? 'If I sell the home, what do I actually pocket after costs?' : null,
                ])),
            ],
            [
                'heading' => 'How the forecast is worked out',
                'questions' => [
                    'What assumptions is this forecast based on?',
                    'What data do you use for how long we might live?',
                ],
            ],
        ];
    }

    private function isSellStrategy(): bool
    {
        return $this->scenario->variant->value !== 'stay_put';
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

    /**
     * Ask a question. With no argument it asks the text box; a suggested-question button passes the
     * question as $preset (so a click asks it directly without a round-trip through the input).
     */
    public function ask(?string $preset = null): void
    {
        if (! config('assistant.enabled')) {
            return;
        }

        $question = trim($preset ?? $this->question);
        if ($question === '') {
            return;
        }

        // Capture prior turns as history BEFORE appending this question (the service adds the
        // new question itself, so including it here would double it).
        $history = $this->historyForModel();

        $this->messages[] = ['role' => 'user', 'text' => $question, 'status' => 'user'];
        $this->question = '';

        $context = $this->compare ? $this->comparisonContext() : $this->scenarioContext();
        $answer = $this->service()->answer(
            $context,
            $question,
            adviceAllowed: Gate::allows('interpret'),
            history: $history,
            methodology: $this->methodologyFor($question),
        );

        $this->messages[] = ['role' => 'assistant', 'text' => $answer->text, 'status' => $answer->status];
    }

    /**
     * The single-scenario context (results page): this plan's headline, year-by-year ladder, Monte
     * Carlo probabilities, lump-sum tax shock and home-sale waterfall.
     */
    private function scenarioContext(): ScenarioContext
    {
        $forecaster = app(ScenarioForecaster::class);

        return ScenarioContext::for(
            $this->scenario,
            $forecaster,
            $this->simulationResult(),
            app(LumpSumTaxShock::class)->assess($this->scenario),
            $this->saleExplainer($forecaster),
        );
    }

    /**
     * The comparison context (Compare page): each compared plan's deterministic headline figures, so
     * the model can answer "which lasts longest / leaves the most / covers essentials?". Built from the
     * SAME per-variant deterministic forecasts the Compare table renders ({@see ScenarioCompare}), so the
     * assistant's figures are the table's (provenance).
     */
    private function comparisonContext(): ComparisonContext
    {
        $forecaster = app(ScenarioForecaster::class);

        $plans = $this->comparePlans()->map(fn (Scenario $plan): array => [
            'name' => $plan->name,
            'variant' => ResultPresenter::variantLabel($plan->variant),
            'forecast' => $forecaster->deterministicVariants($plan)[$plan->variant->value],
            'changes' => $this->changeSummary($plan),
        ])->all();

        return ComparisonContext::fromPlans($plans);
    }

    /** The base plan first, then its ready what-if children — the same family {@see ScenarioCompare} shows. */
    private function comparePlans(): Collection
    {
        return collect([$this->scenario])->concat(
            $this->scenario->children()->where('status', ScenarioStatus::Ready)->latest()->get(),
        );
    }

    /** A one-line summary of what a what-if changed from its base ('' for the base itself), reusing the
     *  same {@see WhatIfChanges} the Compare page shows, so the model can explain how the plans differ. */
    private function changeSummary(Scenario $plan): string
    {
        $changes = WhatIfChanges::of($plan);

        return implode('; ', array_map(
            static fn (array $c): string => trim("{$c['label']} {$c['from']} → {$c['to']}"),
            $changes,
        ));
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

    /**
     * The relevant methodology doc-RAG block for this question (Phase 2), or '' when the index is
     * absent, nothing clears the relevance threshold, or the local embedder is unreachable. A pure
     * scenario question adds no doc noise; methodology is additive and is never allowed to break a
     * scenario answer, so any failure degrades quietly to ''. See {@see MethodologyRetriever}.
     */
    private function methodologyFor(string $question): string
    {
        $indexPath = (string) config('assistant.doc_index_path');
        if (! is_file($indexPath)) {
            return '';
        }

        try {
            $data = json_decode((string) file_get_contents($indexPath), true);
            $chunks = is_array($data) ? ($data['chunks'] ?? []) : [];
            if (! is_array($chunks) || $chunks === []) {
                return '';
            }

            $embedder = new OllamaEmbeddingClient(
                (string) config('assistant.base_url'),
                (string) config('assistant.embed_model'),
                (int) config('assistant.timeout'),
                (int) config('assistant.probe_timeout'),
            );
            $retriever = new MethodologyRetriever(
                $embedder,
                DocIndex::fromArray($chunks),
                (int) config('assistant.retrieval_k'),
                (float) config('assistant.retrieval_threshold'),
            );

            return $retriever->retrieve($question);
        } catch (\Throwable) {
            return '';
        }
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
