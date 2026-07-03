# Research / spec: in-app local-model assistant (scenario explainer + backlog capture)

_Captured 2026-07-03. Purpose: Rob wants an in-page **"chatbot"**, driven by a **local AI model**,
that can (1) **answer questions about the loaded scenario** and the project, (2) **queue additional
research requests**, and (3) be the **interface for building the feature/task list**. This doc records
the feasibility investigation, the design that keeps it inside this project's accuracy and regulatory
discipline, and the phasing. **Post-v1; specced before building.**_

> **The one-line framing (inherited doctrine, not new):** the model's job is **explaining and
> capturing, never predicting or calculating**. Every number it says comes from the engine; the only
> thing it may *write* is a line on the development backlog. It never builds, edits code, or offers to.

**Build status (2026-07-03):** **Phase 1 (grounded scenario-explainer) is BUILT** — `App\Assistant\` +
`App\Livewire\ScenarioAssistant`, both guardrails tested, verified end-to-end against `qwen3:14b`; see §6.
Phases 2 (methodology doc-RAG) and 3 (backlog capture) remain specced.

---

## 0. The decisions that scope this (Rob, 2026-07-03)

- **Scope: all three asks, phased** — explainer first, then methodology Q&A, then research/feature capture.
- **The model may only append to the dev backlog / work queue.** It **never builds anything, never edits
  code, never offers to build.** "Autonomous writes" means exactly one capability: appending an
  attributed, reversible item to the work queue with no confirm step. Everything else is read-only.
- **Local-only.** The scenario is a real couple's encrypted financial PII; it must never leave the
  machine. This is the same rule the document-import investigation settled ([RESEARCH-document-import.md](RESEARCH-document-import.md) §2, DI-7).

## 1. This is not greenfield — it inherits a settled stance

The 2026-06-28 local-AI / Ollama investigation ([RESEARCH-document-import.md](RESEARCH-document-import.md),
folded into [PLAN.md](PLAN.md)) already settled the doctrine this feature must obey:

- *"The valuable use of a model is **wrangling and explaining, not predicting**."*
- **The model is never the source of a number.** LLMs are non-deterministic and unreliable at finance
  arithmetic (the hallucination literature cited there). Numbers come from the engine; the model narrates.
- **Local-only**, because the data is the most sensitive the app holds.
- **Walled off** behind the same guardrails as everything else.

That framing *is* the correct assistant design. Nothing below relitigates it; it applies it.

## 2. The runtime already exists on this machine

Feasibility at the infrastructure level is **already proven** — Ollama is running on
`localhost:11434` with a full stack pulled:

| Model (local) | Role it fits |
|---|---|
| `qwen3:14b`, `llama3.1:8b-instruct`, `qwen2.5:7b`, `mistral-nemo` | chat + **tool-calling** (grounding figures via function calls) |
| `nomic-embed-text` | **local embeddings** for doc-RAG over `docs/` (Phase 2) |

Laravel 13 ships the HTTP client, so the app talks to Ollama with a plain `Http::post()` — **app-layer
only, engine untouched** (honours the framework-free rule). No new infrastructure, no new service to
stand up. The chosen model is a **runtime choice**, not baked in (same philosophy as the assumption
sets): default to a tool-calling model, swap freely.

## 3. The honest reframe — who each capability is for

The three asks are not equal in value, and the difference decides the design:

- **#1 Answer questions about the scenario** — high, *unique* value. It sits next to the results, knows
  the loaded forecast, and speaks plain English about *this couple's* numbers. Needs to be in-app and
  local. **This is the feature.**
- **#2 Queue research + #3 build the task list** — these are **natural-language-to-structured-item
  capture**. The heavy lifting (actual research, actual feature planning, actual building) already has a
  far better home: Rob + Claude Code, with repo access, web research and a frontier model. A 14B local
  model doing real research or planning would be **strictly worse and duplicative**. Its right role is a
  **thin capture layer**: turn "I wonder about X" / "we should add Y" into a **backlog line**, which Rob
  or Claude Code later actions. The model fills the form; it does not do the work — **and does not build.**

So: **a grounded scenario-explainer as the core, plus a backlog-append capability.** Not a local model
pretending to be a research/coding agent.

## 4. Architecture

```
┌─ Livewire panel on the results page  (new: app/Livewire/ScenarioAssistant)
│    · question box · streamed answer · sources/figures shown with provenance
│
├─ App\Assistant\AssistantService  (app-layer; engine stays pure)
│    ├─ intent split:
│    │     "about my forecast"   → TOOL-CALLING mode  (Phase 1)
│    │     "about methodology"   → DOC-RAG mode       (Phase 2, nomic-embed over docs/)
│    │     "queue research / add a feature/task" → CAPTURE mode (Phase 3, backlog append)
│    │
│    ├─ Ollama client (Http:: → localhost:11434, streaming; reports unavailability loudly)
│    │
│    ├─ read-only tools the model may call (each returns ENGINE figures, never the model's own):
│    │     forecastSummary(scenario)  → ResultPresenter headline fields
│    │     ladderYear(scenario, year) → cashflow-ladder row
│    │     compareVariants(scenario)  → buy / rent / stay figures
│    │     taxShock(scenario)         → App\Forecast\LumpSumTaxShock
│    │
│    └─ the ONE write tool (Phase 3):
│          queueBacklogItem(kind: research|feature|task, title, note)
│            → append-only to a dedicated, attributed store (below). No build. No code. No confirm.
│
└─ TWO guardrails wrap every response (the load-bearing engineering):
     G1 Figure-grounding: the model may only restate tool-returned figures; a verification pass
        rejects/regenerates any number not present in the tool output. (No invented pennies.)
     G2 Phrasing partition: run output through App\Compliance\OutputPhrasing::violations();
        in guidance-only mode a violation is refused/regenerated, never shown. In personal-use mode,
        advice-style asks route through App\Compliance\Interpretation (the existing walled-off layer).
```

**Two retrieval modes is the crucial call.** Scenario/figure questions use **tool-calls, never
embeddings** — the user's own numbers must come structured from the engine so they cannot be
hallucinated. Embedding-RAG is for *methodology* ("how do you model emergency tax?") over the small
`docs/` corpus. This mirrors [Interpretation.php](../app/Compliance/Interpretation.php), which already
produces sentences strictly from computed figures and even reuses `ResultPresenter::formatPercent` so an
interpreted figure is byte-identical to the panel's.

### The backlog / work-queue store (Phase 3, the only write path)

Autonomous, but **safe by construction** — it honours "no silent failure" and the doc-hygiene rule
(never let a 14B model edit curated PLAN/DECISIONS prose):

- **Append-only** to a dedicated home — an `assistant_backlog` table (or a clearly-marked
  `docs/BACKLOG-assistant.md` section), **never** an in-line edit of PLAN.md / DECISIONS.md / HANDOVER.md.
- **Attributed + timestamped + reversible.** Every item is stamped "assistant-generated", dated, and
  shown in a review list where Rob can promote it into the real backlog or delete it.
- **Visible, not silent.** The panel confirms on screen what was queued (no fire-and-forget).
- Promotion into PLAN.md's real backlog stays a **human/Claude Code** act — the model proposes the line,
  a human curates it into the source-of-truth-for-scope.

## 5. Risks (ranked)

| # | Risk | Why it bites *here* specifically | Mitigation |
|---|------|----------------------------------|------------|
| A1 | **Hallucinated / transposed figure** ("lasts to 2058" when the engine says 2054) | The tool's whole credibility is penny-accuracy + sourced figures; a wrong number is corrosive, not cosmetic | G1 figure-grounding: figures only via tool-calls; verification pass; provenance shown; the assistant *explains over* the authoritative panels, never replaces them |
| A2 | **Regulatory — a public-release blocker** | You cannot build-time-lint a runtime model's output; a free-form LLM will eventually say "you should draw the ISA first" | G2 runtime `OutputPhrasing` guard + the `interpret` gate. Safe in personal-use mode today; a **flagged public-release blocker** alongside `personal_use=false`, the JST dataset swap, CSP nonces |
| A3 | **Local model instruction-following / tool-call reliability** | 14B local < frontier; may ignore grounding or mis-call a tool | narrow well-typed tools; **refuse rather than guess** ("no silent failure"); if Ollama is down or the model won't ground, say so |
| A4 | **Scope creep / duplication / building** | A local model attempting research, planning or code duplicates Claude Code and underdelivers — and Rob has ruled building out entirely | the capture-and-route framing; the model's **only** write is a backlog append; it never builds and is prompted to never offer to |
| A5 | **Autonomous backlog spam** | No confirm step → low-quality machine items accumulate in the queue | dedicated attributed append-only store + a human promotion step; items are cheap to delete and never touch curated docs |

## 6. Phasing (each phase delivers alone; earlier ones carry no dependency on later)

1. ✅ **Phase 1 — grounded scenario-explainer — BUILT (2026-07-03).** Results-page Livewire panel
   (`App\Livewire\ScenarioAssistant`, inert unless `config('assistant.enabled')`); **G1 figure-grounding**
   (`App\Assistant\FigureGrounding`) + **G2 phrasing guard** (reuses `App\Compliance\OutputPhrasing`) as
   first-class, tested pieces; behind the `interpret` gate. The *"the assistant never surfaces an ungrounded
   figure"* invariant is pinned (`AssistantServiceTest`), and it's verified end-to-end against the real
   `qwen3:14b`. **As built it PROMPT-STUFFS the bounded figure snapshot (`ScenarioContext`) rather than
   tool-calling** — more reliable on a smaller local model (risk A3), and the snapshot simply appends more
   facts as context grows; tool-calling stays the path only if the snapshot ever gets too large to inline.
   v1 is a synchronous call with a "Thinking…" state; streaming/queueing, richer context (tax shock, sale
   waterfall, Monte Carlo probabilities) and a side-nav entry are fast-follows.
2. **Phase 2 — methodology doc-RAG.** `nomic-embed-text` over `docs/` (small, high-trust corpus) for
   "how does it model X" questions, kept distinct from scenario-figure questions.
3. **Phase 3 — research/feature capture-and-route.** The `queueBacklogItem` write tool → the append-only
   attributed store + a review/promote list. **This is the model's only write, and the ceiling of its
   agency: it queues, it does not build.**

## 7. Gotchas — what could bite

| # | Bite | Mitigation |
|---|------|------------|
| LA-1 | Model states a figure it wasn't given (or transposes digits) | G1: figures only from tool output; verification pass rejects ungrounded numbers |
| LA-2 | Model emits banned recommendation phrasing in guidance-only mode | G2: runtime `OutputPhrasing` scan; refuse/regenerate; `interpret` gate |
| LA-3 | Scenario PII sent to a cloud endpoint | local-only (Ollama on localhost); no cloud fallback, ever |
| LA-4 | Model "helpfully" offers to build / edits something | prompt + capability boundary: the only write tool is `queueBacklogItem`; no code/file tools are exposed to it |
| LA-5 | Ollama not running / model not pulled → silent failure | detect + report loudly ("the local assistant isn't running"); never fabricate an answer in its place |
| LA-6 | Embedding-RAG used for the user's own numbers → stale/wrong figure | two-mode split: figures = tool-calls only; embeddings = methodology docs only |
| LA-7 | Autonomous backlog items silently corrupt curated PLAN/DECISIONS | append-only to a separate attributed store; human promotion; never in-line edits |
| LA-8 | A wrong grounded explanation of a *right* number (misreads what a figure means) | tool payloads carry the figure's meaning/label, not just the value; provenance link back to the panel that owns it |

## 8. Sources

- Prior settled stance (local-only, wrangling-not-predicting, never the source of numbers):
  [docs/RESEARCH-document-import.md](RESEARCH-document-import.md) §2 + DI-6/DI-7; [docs/PLAN.md](PLAN.md)
  "Statement-driven onboarding" key calls.
- LLM arithmetic / hallucination in finance (why the model never sources a figure):
  [Deficiency of LLMs in Finance — Hallucination (arXiv)](https://arxiv.org/pdf/2311.15548),
  [FAITH: tabular hallucinations in finance (arXiv)](https://arxiv.org/pdf/2508.05201).
- Local-LLM finance / privacy (why local-only): [DZone — private LLM finance analyzer](https://dzone.com/articles/local-llm-finance-tracker).
- The existing walls this reuses: [app/Compliance/Interpretation.php](../app/Compliance/Interpretation.php),
  [app/Compliance/OutputPhrasing.php](../app/Compliance/OutputPhrasing.php),
  [config/compliance.php](../config/compliance.php).
</content>
</invoke>
