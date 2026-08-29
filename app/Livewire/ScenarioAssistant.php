<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Assistant\AssistantService;
use App\Assistant\AssistantTurnRunner;
use App\Assistant\BacklogCapture;
use App\Assistant\ChatClient;
use App\Assistant\OllamaChatClient;
use App\Assistant\ScenarioEditCapture;
use App\Assistant\ScenarioEditVocabulary;
use App\Enums\SimulationStatus;
use App\Forecast\BuilderStateDelta;
use App\Forecast\WhatIfChanges;
use App\Forecast\WhatIfWriter;
use App\Jobs\RunAssistantTurn;
use App\Models\AssistantBacklogItem;
use App\Models\AssistantTurn;
use App\Models\Scenario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * The in-page assistant: a plain-English explainer over THIS scenario's forecast, running on a
 * LOCAL model. It explains, it captures an idea for the backlog, and — only when
 * `config('assistant.can_edit_scenarios')` is on — it can PROPOSE a what-if from what the reader
 * says. It never runs anything, never builds anything, and never changes a stored plan: a
 * proposal is shown as a diff and written only on a confirm click, and then only as a new
 * delta-child what-if. See {@see proposeChange()} and docs/build/PLAN-assistant-scenario-editing.md.
 *
 * It is inert unless `config('assistant.enabled')` is on (so a machine without the local runtime
 * shows nothing). Every figure it may state is engine-derived and re-verified at runtime; the
 * advice-vs-guidance line is the app's own `interpret` gate, resolved for the asking user. The
 * generation runs on the background worker as a queued {@see AssistantTurn} this panel polls —
 * a slow local model was outliving the web server's gateway timeout in the synchronous v1 and
 * surfacing as a raw 504 (2026-07-08). The trust-critical work lives in
 * {@see AssistantTurnRunner} + {@see AssistantService} (unit-tested
 * with a fake model); this component is thin glue that queues turns and renders the transcript.
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

    /** Which view the panel shows: 'ask' (explain), 'ideas' (capture backlog items), 'change' (propose a what-if). */
    public string $tab = 'ask';

    public string $question = '';

    /** The reader's free-text idea for the tool, on the Ideas tab. */
    public string $idea = '';

    /** A one-line confirmation after capturing an idea (visible, never silent). */
    public string $captureNotice = '';

    /** What the reader asked to change, on the Change tab. */
    public string $changeRequest = '';

    /**
     * The proposed edits (form-state dot-path => new value), held UNWRITTEN until the reader
     * confirms — guardrail C3. Nothing here has touched a stored scenario.
     *
     * @var array<string, string>
     */
    public array $proposedEdits = [];

    /** The assistant's question back, or the reason a proposal was refused (visible, never silent). */
    public string $changeNotice = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Forget the conversation and return the panel to its starting (suggested-questions) state.
     * The transcript is local component state only; the one server-side residue is any queued
     * {@see AssistantTurn} rows (including a still-pending one — its job then finds nothing and
     * exits), so those are deleted too.
     */
    public function clear(): void
    {
        AssistantTurn::query()
            ->where('user_id', auth()->id())
            ->where('scenario_id', $this->scenario->id)
            ->delete();
        $this->pendingTurnId = null;
        $this->messages = [];
        $this->question = '';
    }

    /** Switch between the Ask (explain), Ideas (capture) and Change (propose a what-if) views. */
    public function switchTab(string $tab): void
    {
        $tabs = $this->canEditScenarios() ? ['ask', 'ideas', 'change'] : ['ask', 'ideas'];
        $this->tab = in_array($tab, $tabs, true) ? $tab : 'ask';
        $this->captureNotice = '';
        $this->discardChange();
    }

    /**
     * Phase 3 — capture the reader's idea to the work queue. This is the model's ONE write, and the
     * ceiling of its agency: it structures the idea into a queued item; it never builds it. The write
     * is append-only, attributed and reversible (deletable below), so it needs no confirm step. If the
     * model can't structure it, the raw idea is still saved (a Task) — an idea is never lost.
     */
    public function captureIdea(): void
    {
        if (! config('assistant.enabled')) {
            return;
        }

        $raw = trim($this->idea);
        if ($raw === '') {
            return;
        }

        $structured = (new BacklogCapture($this->chatClient()))->structure($raw);

        AssistantBacklogItem::create([
            'user_id' => auth()->id(),
            'kind' => $structured['kind'],
            'title' => $structured['title'],
            'note' => $structured['note'],
            'source' => $raw,
        ]);

        $this->idea = '';
        $this->captureNotice = 'Added to the backlog for review. The assistant only captures ideas — it never builds them.';
    }

    /** Remove a queued idea (owner-scoped) — the write is reversible. */
    public function deleteIdea(int $id): void
    {
        AssistantBacklogItem::query()->where('user_id', auth()->id())->whereKey($id)->delete();
        $this->captureNotice = '';
    }

    /**
     * This reader's queued ideas, newest first — the review list where they are promoted (by a human,
     * elsewhere) or deleted. Capped to a recent window.
     *
     * @return Collection<int, AssistantBacklogItem>
     */
    public function backlogItems(): Collection
    {
        return AssistantBacklogItem::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->limit(30)
            ->get();
    }

    // --- The Change tab: propose a what-if, then confirm it (PLAN-assistant-scenario-editing) ---

    /** Whether this panel may propose plan changes at all — its own switch, off by default (C4). */
    public function canEditScenarios(): bool
    {
        return (bool) config('assistant.enabled') && (bool) config('assistant.can_edit_scenarios');
    }

    /**
     * Turn what the reader asked for into a PROPOSAL. This writes nothing: it fills in the
     * changes and hands them back for review (guardrail C3), so a stored scenario is never
     * mutated by a sentence. The model may only pick from the app's closed menu (C2) and may
     * only carry figures the reader stated (C1) — see {@see ScenarioEditCapture}.
     *
     * Synchronous, like the Ideas tab's capture: this is one short structured-extraction call,
     * not the long grounded generation the Ask tab queues onto the worker.
     */
    public function proposeChange(): void
    {
        if (! $this->canEditScenarios()) {
            return;
        }

        $this->discardChange();

        $proposal = (new ScenarioEditCapture($this->chatClient()))->propose(
            ScenarioEditVocabulary::for($this->scenario),
            $this->changeRequest,
        );

        $this->proposedEdits = $proposal['edits'];
        $this->changeNotice = $proposal['question'];
    }

    /**
     * Write the reviewed proposal as an ordinary delta-child what-if — the same sparse override
     * delta over the same base that a hand-built what-if stores, through the same writer, so an
     * assistant edit and a manual one are indistinguishable afterwards. The base plan itself is
     * untouched (C4: base editing is not built). An edit that will not assemble is reported and
     * creates nothing (SE-5).
     */
    public function confirmChange(): mixed
    {
        if (! $this->canEditScenarios() || $this->proposedEdits === []) {
            return null;
        }

        // A what-if is always a child of the base, even when proposed from a child's results.
        $base = $this->scenario->baseScenario();
        $baseState = $base->effectiveBuilderState();
        $overrides = BuilderStateDelta::diff($baseState, BuilderStateDelta::merge($baseState, $this->proposedEdits));

        if ($overrides === []) {
            $this->discardChange();
            $this->changeNotice = 'That would not change anything in this plan, so nothing was created.';

            return null;
        }

        try {
            $child = WhatIfWriter::create($base, Str::limit(trim($this->changeRequest), 60, ''), $overrides);
        } catch (Throwable $e) {
            $this->discardChange();
            $this->changeNotice = 'That change would not add up: '.$e->getMessage().' Nothing was saved.';

            return null;
        }

        return $this->redirect(route('scenarios.results', $child), navigate: true);
    }

    /** Drop an unconfirmed proposal. Nothing was written, so there is nothing to undo. */
    public function discardChange(): void
    {
        $this->proposedEdits = [];
        $this->changeNotice = '';
    }

    /**
     * The proposal as the reader reviews it — the same base-value → new-value diff a saved
     * what-if is described by, so the confirm card and the what-if afterwards read alike.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public function proposedChanges(): array
    {
        if ($this->proposedEdits === []) {
            return [];
        }

        return WhatIfChanges::compute($this->scenario->baseScenario()->effectiveBuilderState(), $this->proposedEdits);
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
                    'What happens to the survivor\'s income when the first of us dies?',
                    'What could paying for care later in life do to the plan?',
                    'How does inflation affect what I can spend?',
                ],
            ],
            [
                'heading' => 'How far can we go?',
                'questions' => array_values(array_filter([
                    'What limits have been computed for this plan, and what do they say?',
                    $this->plansABuy() ? 'How much can we spend on a new home before the plan stops holding up?' : null,
                ])),
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

    /** Whether the plan funds a purchase (the buy-price limit question only makes sense then) — the same gate the explorer's buy-price lever uses. */
    private function plansABuy(): bool
    {
        $action = $this->scenario->toHousingAction();

        return $action->salePrice->isPositive() && ($action->buyPrice?->isPositive() ?? false);
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

    /** The queued turn the panel is waiting on (null = idle). Drives the poll + the Thinking… state. */
    public ?int $pendingTurnId = null;

    /**
     * Ask a question. With no argument it asks the text box; a suggested-question button passes the
     * question as $preset (so a click asks it directly without a round-trip through the input).
     * The generation is QUEUED as an {@see AssistantTurn} and collected by {@see pollTurn()} — it
     * runs on the worker, not in this web request.
     */
    public function ask(?string $preset = null): void
    {
        if (! config('assistant.enabled') || $this->pendingTurnId !== null) {
            return;
        }

        $question = trim($preset ?? $this->question);
        if ($question === '') {
            return;
        }

        // Self-heal abandoned rows (browser closed mid-turn). Only clearly stale ones — a
        // fresh turn may legitimately belong to this scenario open in another tab.
        AssistantTurn::query()
            ->where('user_id', auth()->id())
            ->where('scenario_id', $this->scenario->id)
            ->where('created_at', '<', now()->subDay())
            ->delete();

        // Capture prior turns as history BEFORE appending this question (the service adds the
        // new question itself, so including it here would double it).
        $history = $this->historyForModel();

        $this->messages[] = ['role' => 'user', 'text' => $question, 'status' => 'user'];
        $this->question = '';

        $turn = AssistantTurn::create([
            'user_id' => auth()->id(),
            'scenario_id' => $this->scenario->id,
            'compare' => $this->compare,
            'status' => SimulationStatus::Queued,
            'question' => $question,
            'history' => $history,
        ]);
        RunAssistantTurn::dispatch($turn->id);

        $this->pendingTurnId = $turn->id;
    }

    /**
     * Collect the pending turn (the view polls this while one is in flight). A terminal turn's
     * outcome joins the transcript — answered, refused-with-reason, or failed-with-reason, never
     * a silent blank — and the row is deleted: the transcript lives only in this component's
     * state, so nothing conversational persists server-side.
     */
    public function pollTurn(): void
    {
        if ($this->pendingTurnId === null) {
            return;
        }

        $turn = AssistantTurn::query()
            ->where('user_id', auth()->id())
            ->find($this->pendingTurnId);

        if ($turn === null) {
            // Deleted out from under us (e.g. Clear in another tab) — report, don't hang.
            $this->messages[] = ['role' => 'assistant', 'text' => 'That question was cancelled before it finished.', 'status' => 'unavailable'];
            $this->pendingTurnId = null;

            return;
        }

        if (! $turn->status->isTerminal()) {
            return; // still queued/running — keep polling
        }

        $this->messages[] = $turn->status === SimulationStatus::Done && $turn->answer !== null
            ? ['role' => 'assistant', 'text' => $turn->answer['text'], 'status' => $turn->answer['status']]
            : ['role' => 'assistant', 'text' => "The local assistant couldn't answer: ".($turn->error ?? 'the worker stopped unexpectedly.'), 'status' => 'unavailable'];

        $turn->delete();
        $this->pendingTurnId = null;
    }

    /** The pending turn row, owner-scoped (null when idle) — the view reads its awaiting-worker hint off this. */
    public function pendingTurn(): ?AssistantTurn
    {
        if ($this->pendingTurnId === null) {
            return null;
        }

        return AssistantTurn::query()
            ->where('user_id', auth()->id())
            ->find($this->pendingTurnId);
    }

    /** The local chat client, built from config — used by the Ideas and Change tabs' structurers. */
    private function chatClient(): ChatClient
    {
        return new OllamaChatClient(
            (string) config('assistant.base_url'),
            (string) config('assistant.model'),
            (int) config('assistant.timeout'),
            (int) config('assistant.probe_timeout'),
        );
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
