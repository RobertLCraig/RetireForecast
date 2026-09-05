<?php

declare(strict_types=1);

namespace App\Assistant;

use App\DecisionSupport\ThresholdFacts;
use App\Enums\ScenarioStatus;
use App\Enums\SimulationStatus;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfChanges;
use App\Models\AssistantTurn;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Throwable;

/**
 * Executes one queued {@see AssistantTurn} on the background worker: rebuilds the grounded
 * context from the scenario's CURRENT state (scenario or comparison mode), retrieves any
 * relevant methodology, runs the guarded {@see AssistantService} turn, and stores the outcome
 * on the row for the panel's poll to collect. This is the context-assembly that used to live
 * in the Livewire component — moved here so the model generation happens on the worker, where
 * a slow local model cannot outlive the web server's gateway timeout (the 2026-07-08 504).
 *
 * The advice-vs-guidance line is resolved per turn with {@see Gate::forUser()} against the
 * turn's OWNER — a worker has no authenticated session, and the answer must carry the asker's
 * own interpret permission, not nobody's.
 */
final class AssistantTurnRunner
{
    /**
     * Run the turn to a terminal state. Every outcome is explicit on the row — done (with the
     * answer, which itself may be a guarded refusal), or failed with the reason — so the
     * polling panel never waits on a silently dead turn (no silent failure).
     */
    public function execute(AssistantTurn $turn): void
    {
        // A re-delivered job (e.g. the queue's retry_after lapsed mid-generation) must not
        // run the model twice; only a queued turn may start.
        if ($turn->status !== SimulationStatus::Queued) {
            return;
        }

        $turn->update(['status' => SimulationStatus::Running]);

        try {
            $answer = $this->answerFor(
                $turn->scenario,
                $turn->compare,
                $turn->question,
                $turn->history ?? [],
                Gate::forUser($turn->user)->allows('interpret'),
            );

            $turn->update([
                'status' => SimulationStatus::Done,
                'answer' => ['text' => $answer->text, 'status' => $answer->status],
            ]);
        } catch (Throwable $e) {
            $turn->update([
                'status' => SimulationStatus::Failed,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One guarded assistant answer over the scenario's current state — the same assembly the
     * panel ran synchronously before the turn was queued.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function answerFor(Scenario $scenario, bool $compare, string $question, array $history, bool $adviceAllowed): AssistantAnswer
    {
        $context = $compare ? $this->comparisonContext($scenario) : $this->scenarioContext($scenario);

        return $this->service()->answer(
            $context,
            $question,
            adviceAllowed: $adviceAllowed,
            history: $history,
            methodology: $this->methodologyFor($question),
        );
    }

    /**
     * The single-scenario context (results page): this plan's headline, year-by-year ladder,
     * Monte Carlo probabilities, lump-sum tax shock, home-sale waterfall, income floor +
     * survivor cliff, and the computed "how far can we go" limits — hash-matched to the
     * CURRENT inputs by {@see ThresholdFacts}, so a stale limit never enters the context or
     * the grounding allow-list.
     */
    private function scenarioContext(Scenario $scenario): ScenarioContext
    {
        $forecaster = app(ScenarioForecaster::class);

        return ScenarioContext::for(
            $scenario,
            $forecaster,
            $this->simulationResult($scenario),
            app(LumpSumTaxShock::class)->assess($scenario),
            $this->saleExplainer($scenario, $forecaster),
            app(ThresholdFacts::class)->for($scenario),
        );
    }

    /**
     * The comparison context (Compare page): each compared plan's deterministic headline
     * figures, built from the SAME per-variant deterministic forecasts the Compare table
     * renders, so the assistant's figures are the table's (provenance).
     */
    private function comparisonContext(Scenario $scenario): ComparisonContext
    {
        $forecaster = app(ScenarioForecaster::class);

        $plans = $this->comparePlans($scenario)->map(fn (Scenario $plan): array => [
            'name' => $plan->name,
            'variant' => ResultPresenter::variantLabel($plan->variant),
            'forecast' => $forecaster->deterministicVariants($plan)[$plan->variant->value],
            'changes' => $this->changeSummary($plan),
        ])->all();

        return ComparisonContext::fromPlans($plans);
    }

    /** The base plan first, then its ready what-if children — the same family the Compare page shows. */
    private function comparePlans(Scenario $scenario): Collection
    {
        return collect([$scenario])->concat(
            $scenario->children()->where('status', ScenarioStatus::Ready)->latest()->get(),
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
     * page resolves its results.
     */
    private function simulationResult(Scenario $scenario): ?SimulationResult
    {
        $run = $scenario->latestCompletedRun();
        if ($run === null) {
            return null;
        }

        $byVariant = $run->results->keyBy(fn (Result $r): string => $r->variant->value);
        $result = $byVariant[$scenario->variant->value] ?? $run->results->first();

        return $result?->simulationResult();
    }

    /**
     * The home-sale waterfall for this scenario's strategy, or null when it isn't a sell
     * strategy ({@see ResultPresenter::saleExplainer()} returns null on a zero sale price).
     * Assembled exactly as the results page does, so the assistant's figures are the panel's.
     *
     * @return array<string, mixed>|null
     */
    private function saleExplainer(Scenario $scenario, ScenarioForecaster $forecaster): ?array
    {
        $household = $scenario->toHousehold();
        $action = $scenario->toHousingAction();
        $assumptions = $forecaster->assumptions($scenario);
        $allocation = $forecaster->settings($scenario)->allocation();
        $housing = $forecaster->housingComparison($scenario);

        return ResultPresenter::saleExplainer(
            $housing->saleProceeds($household, $action),
            $housing->buyOutcome($household, $action, $forecaster->settings($scenario)->baseYear),
            $action,
            $allocation->blendedRealReturn($assumptions),
            $assumptions->investmentIncomeYield->asFraction(),
        );
    }

    /**
     * The relevant methodology doc-RAG block for this question (Phase 2), or '' when the index
     * is absent, nothing clears the relevance threshold, or the local embedder is unreachable.
     * Methodology is additive and is never allowed to break a scenario answer, so any failure
     * degrades quietly to ''. See {@see MethodologyRetriever}.
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
        } catch (Throwable) {
            return '';
        }
    }

    private function service(): AssistantService
    {
        return new AssistantService($this->chatClient());
    }

    /** The local chat client, built from config. */
    private function chatClient(): ChatClient
    {
        return new OllamaChatClient(
            (string) config('assistant.base_url'),
            (string) config('assistant.model'),
            (int) config('assistant.timeout'),
            (int) config('assistant.probe_timeout'),
        );
    }
}
