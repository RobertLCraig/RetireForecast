# Plan: assistant-driven scenario editing (conversational what-ifs + gated base edits)

_Specced 2026-07-04. Purpose: Rob wants the in-app local assistant to **create scenarios** — ask
targeted questions about what the reader wants to change **from the base**, then build the what-if
for them; and, **only on an explicit request**, update the base plan itself. This doc is the
spec-before-build. Status: **APPROVED scope (2026-07-04) — awaiting build.** Rob's calls: **widen the
doctrine fully** (conversational what-ifs **and** gated base editing), with **base editing deferred to
Phase 3, `can_edit_base` default off**. Minor items (§8 Q3–Q5) ride on the recommendations unless
changed. Needs a DECISIONS entry at build/checkpoint._

> **The one-line framing:** the assistant turns the reader's **own stated changes** into a
> **reviewable what-if**, using the same delta-child machinery a hand-built what-if uses. It never
> invents a figure, never computes an outcome, and never touches the base plan unless explicitly told
> to. It fills the form from what you say; the engine still does the forecasting.

---

## 0. This changes a recorded decision — name it first

The assistant was built under an emphatic rule, stated in [RESEARCH-local-assistant.md](RESEARCH-local-assistant.md)
(§0, §3, risk A4), [config/assistant.php](../config/assistant.php) and DECISIONS 2026-07-03:

> *"The model may only append to the dev backlog. It never builds anything, never edits code, never
> offers to build."* — and A4 records *"Rob has ruled building out entirely."*

This plan **deliberately widens that** — so it needs Rob's explicit go-ahead and a DECISIONS entry, not
a quiet build. The case for why it's still inside the discipline, not a betrayal of it:

| What the old rule protected | Why creating a what-if doesn't break it |
|---|---|
| The model is never the **source of a number** (it hallucinates finance arithmetic — A1) | Every value in the what-if is a figure **the reader stated**. The model maps "bump my retirement to 68" onto a field; it supplies no figure of its own (guard **C1**). |
| The model never **computes an outcome** | Creation produces **inputs only**. The existing deterministic engine + Monte Carlo forecast the result, exactly as for a hand-built what-if. The model never asserts what the change will do. |
| Writes are **safe, reversible, human-reviewed** (the backlog is append-only + deletable) | A what-if is a **delta-child** — a throwaway draft off the base, reviewed as a diff before it's saved and deletable after. The base is untouched by default. |
| The model doesn't do **research / planning / code** (that's Claude Code's job, and worse on a 14B) | Still ruled out. This is **data-entry assistance**, categorically different from authoring content or predicting. |

So the reframe is narrow: **"explains + captures"** becomes **"explains + captures + assembles a
reviewable what-if from the reader's own figures."** "Never builds code / never predicts / never
sources a figure" all stand. If Rob is uncomfortable widening it, the fallback is Phase 1 only, behind
its own off-by-default flag — no base editing, ever.

## 1. Scope (Rob, 2026-07-04)

- **Primary: conversational what-if.** The assistant asks targeted questions about what to change
  **from the base**, and creates a **delta-child what-if** — never a from-scratch scenario (that
  bigger version stays out; see the prior chat's two-fork recommendation).
- **Secondary, gated: update the base**, only on an **explicit** request ("change my base plan to…",
  not "what if…"), behind a separate off-by-default capability flag, with a louder confirm.
- **Local-only, unchanged.** Still Ollama on `localhost`; no new data exposure (the reader's figures
  never leave the machine — LA-3/DI-7).

## 2. Why this is feasible cheaply — it's mostly wiring that already exists

Almost every piece is built; this is a new **producer** of the delta a what-if already stores, plus a
confirm step. The reused machinery:

- **The delta-child model** — a child stores a sparse `overrides` map (dot-path → leaf value) over its
  base; [BuilderStateDelta](../app/Forecast/BuilderStateDelta.php) `diff`/`merge`/`valueAt`/`orphans`,
  row-lists addressed by **stable id**, with **add** (whole row) and **remove** (`REMOVED` sentinel)
  support. This is exactly the shape our output must produce.
- **The programmatic-what-if precedent** — [QuickWhatIf](../app/Forecast/QuickWhatIf.php) already edits
  base form-state and `diff`s it to `{name, overrides}`; [QuickWhatIfController](../app/Http/Controllers/QuickWhatIfController.php)
  already **persists that as a Ready child** (`new Scenario` → `parent_scenario_id` → `overrides` →
  `builder_state=[]` → `projectFrom(effectiveBuilderState())` → `save`). Our conversational path
  produces the **same `{name, overrides}` shape**, so persistence is a straight reuse (extract the
  child-creation into a shared `WhatIfWriter::create()`).
- **The confirm/echo-back diff** — [WhatIfChanges](../app/Forecast/WhatIfChanges.php)`::compute(baseState,
  overrides)` already turns an override map into a readable `{label, from, to}` list. This **is** the
  confirmation card ("Retirement age 66 → 68; Essentials £28,000 → £32,000").
- **The panel + guardrail spine** — [ScenarioAssistant](../app/Livewire/ScenarioAssistant.php) (tabs,
  local `ChatClient`, transcript), [AssistantService](../app/Assistant/AssistantService.php) (G1/G2,
  one-corrective-retry-then-refuse), [BacklogCapture](../app/Assistant/BacklogCapture.php) (the
  NL→structured-item pattern, with a never-lose-the-input fallback) are all directly analogous.

## 3. Architecture

```
Panel: a new "Change" tab in app/Livewire/ScenarioAssistant  (alongside Ask / Ideas)
  · a short conversation: the model asks what to change, gathers the value(s)
  · a CONFIRM CARD (WhatIfChanges diff) — nothing is written until the reader clicks Create
  · Create → a delta-child, opened like any what-if.   Update base (gated) → louder confirm.

App layer (engine untouched):

 App\Assistant\ScenarioEditVocabulary        ← the closed set of editable targets (guard C2)
    ::for(Scenario $base): list<EditTarget>
    Built FROM the base's effective builder_state, so every target is a REAL, resolvable path with a
    known type. Each: { id, label (reuse WhatIfChanges labelling), path, type
    (money|rate|int|enum|bool|text), currentValue, enumOptions? }. The model may only pick from this
    menu — it can never fabricate a path or touch a field not on it.

 App\Assistant\ScenarioEditCapture           ← NL → a validated proposal (BacklogCapture-shaped)
    ::propose(vocabulary, conversation): Proposal
    One structured-extraction call: emit list<{targetId, value}> (+ optional add/remove in Phase 2).
    Then VALIDATE each: targetId ∈ vocabulary (C2); value is present in the reader's own turns (C1);
    value coerces to the target's type. Anything unmatched/ambiguous → a clarifying question, not a
    guess. Injected ChatClient, so unit-testable with a fake — no running model needed.

 App\Assistant\ScenarioEditGrounding         ← guard C1 (the inverse of FigureGrounding)
    Every figure in the proposal must appear in the reader's conversation. A value the model
    introduced (not said by the reader) is rejected → re-ask. (No invented inputs.)

 App\Forecast\WhatIfWriter                    ← shared persistence (extracted from QuickWhatIfController)
    ::create(Scenario $base, string $name, array $overrides): Scenario   (child; QuickWhatIf reuses it)
    ::editBase(Scenario $base, array $edits): void                        (Phase 3, gated)

 Proposal → edited state = setPath(copy of base state, target.path, value) for each
          → overrides   = BuilderStateDelta::diff(baseState, editedState)   (minimal, correct shape)
          → name         = a short model- or reader-supplied label, de-duped like QuickWhatIf
          → validate by assembling: projectFrom() runs HouseholdAssembler; a bad edit throws BEFORE
            persist, is surfaced, and creates nothing (no broken child).
```

**Why a menu, not raw JSON.** A 14B model emitting a raw override map across ~70 possible fields (and
guessing this base's row ids) is the unreliable path. Instead the app hands the model a **closed,
labelled menu of real targets** and the model only **selects + attaches the reader's value**. That
bounds its agency precisely and makes C1/C2 structural, not hoped-for. Same reason Phase 2 methodology
is *not* intent-routed (a 14B router is unreliable — LA-9).

**Why a dedicated tab, not intent detection.** The existing panel uses **explicit modes** (Ask / Ideas),
deliberately, because routing on a 14B is unreliable. A "Change" tab keeps that discipline: the reader
chooses to edit; we never guess that an "explain" question was secretly an edit request.

## 4. Guardrails (named + tested, like G1/G2)

| ID | Guard | Enforced by |
|----|-------|-------------|
| **C1** | **Input grounding** — every figure in a proposal is one the reader stated; the model supplies no number of its own | `ScenarioEditGrounding` scans the proposal's values against the reader's turns; unmatched → re-ask. The confirm card (C3) is the human backstop against a transposition (£410k→£41k). |
| **C2** | **Closed target vocabulary** — the model may only edit paths on the app-built menu; it can't fabricate a path or reach a field not offered | Structural: the override is assembled from the menu selection + `BuilderStateDelta::diff`, never from free-form model output. |
| **C3** | **Mandatory confirm** — the change is shown as a `WhatIfChanges` diff; nothing persists without an explicit Create click | Livewire: a two-step (propose → confirm) flow; no write in the propose step. |
| **C4** | **Child by default; base only on explicit + gated request** — a what-if is a throwaway child; base edits are a separate, louder, `config('assistant.can_edit_base')`-gated path | Default posture is what-if-only; base editing is off unless the flag is set (Phase 3). |
| **C5** | **No outcome claims in creation** — the flow produces inputs, not predictions | The Change-tab prompt forbids asserting results; G1 still refuses any ungrounded figure if it tries. The reader runs the forecast to see the effect, as today. |
| G1/G2 | (unchanged) figure-grounding + phrasing partition | Any explanatory turn still routes through `AssistantService`. |

## 5. Phasing (each ships + is verified E2E vs real `qwen3:14b`, like the prior phases)

1. **Phase 1 — conversational what-if, value edits only.** Change **existing** leaf fields: each
   person's `plannedRetirementAge` / `grossSalary`, each pension/account `currentValue`/`balance`,
   each `expenseLines.*.amount`, the six `assumptionOverrides`, the `variant`. No structural
   add/remove. Confirm → child via `WhatIfWriter::create`. **This is the core ask and the safest
   slice.** Behind its own flag (`config('assistant.can_edit_scenarios')`, default off).
2. **Phase 2 — add / remove rows.** "Add a £7,200 rental income", "remove the DB pension". Uses the
   delta's add (whole row) + remove (sentinel) support. Needs required-field prompting + app-side row
   assembly (like `QuickWhatIf::letOutAndRent`); scoped to well-understood rows (income stream, one-off
   cost, spending line) — a DC pension with a withdrawal schedule stays "do that in the builder"
   (honest v1 boundary). Higher completeness risk → the confirm card names exactly what's added.
3. **Phase 3 — edit the base, gated.** `config('assistant.can_edit_base')`, default off. Applies the
   edits to the base's real `builder_state` (not an overrides delta), re-projects, **invalidates
   stored runs** (the base's forecast changed — stale-run rule), and **surfaces any child override the
   edit orphaned** (`BuilderStateDelta::orphans` on each child — completeness, no silent breakage). The
   confirm explicitly warns it changes the base plan itself and affects existing what-ifs.

## 6. Risks / gotchas — what could bite _here_

| # | Bite | Mitigation |
|---|------|------------|
| SE-1 | **Transposition** — reader says £410k, model writes £41,000 → a wrong but authoritative forecast (the A1 failure, on the input side) | C1 (value must be in the reader's turn) + C3 confirm card shows "£41,000" for the reader to catch + type coercion |
| SE-2 | **Ambiguous target** — "increase my pension" with two pensions | The menu makes both visible; the prompt requires a clarifying question, never a pick; C2 refuses an unresolved target |
| SE-3 | **Silent omission / completeness** — a base edit breaks a child's override, or an add is half-specified | Phase 3 runs `orphans` on every child and surfaces it; Phase 2 prompts required fields and validates the assembled row before persist ([[data-consistency-reconciliation]]) |
| SE-4 | **Menu drifts from the builder field surface** — a field editable in the builder but mis-typed on the menu → a bad override | Build the menu from the canonical effective builder_state + a test pinning it to `BuilderStateFixture::full`; a chat-uneditable field is fine (chat is a subset), a mis-typed one is not ([[new-builder-field-delta-gotcha]]) |
| SE-5 | **Invalid edit persisted** — the change fails `HouseholdAssembler` validation | Assemble (`projectFrom`) **before** save; a throw surfaces the reason and creates nothing (no broken child) |
| SE-6 | **Regulatory phrasing** in guidance-only mode ("you should model retiring later") | G2 still wraps conversational turns; the flow is reader-driven so exposure is low; fine in personal-use advice mode |
| SE-7 | **Base edit blast radius** — editing the base silently shifts every derived child + invalidates runs | C4 gating (explicit + separate flag + louder confirm) + Phase 3's orphan/stale-run surfacing |
| SE-8 | **Model won't emit clean structure** on a 14B | BacklogCapture's proven pattern: constrained JSON, tolerant parse, and on failure **ask again** rather than write a guess (creation, unlike capture, has no "save the raw text" fallback — an unclear edit must not become a scenario) |

## 7. Testing

- **Unit:** `ScenarioEditVocabulary::for` over `BuilderStateFixture::full` (every target resolves + is
  correctly typed); `ScenarioEditCapture::propose` maps NL → a validated proposal with a **fake**
  client (no running model); `ScenarioEditGrounding` rejects a value the reader never stated; a no-op
  proposal creates nothing (like QuickWhatIf); the proposal → `overrides` round-trips through
  `BuilderStateDelta::diff`/`merge`. Phase 2: add/remove. Phase 3: base-edit orphan surfacing + run
  invalidation.
- **Livewire (`ScenarioAssistant`):** the Change tab gathers → shows a `WhatIfChanges` confirm card →
  Create makes a child (`assertDatabaseHas`, owner-scoped) and opens it; an invalid edit surfaces and
  creates nothing; base editing is inert unless the flag is on.
- **E2E vs real `qwen3:14b`** (the bar every prior phase met): "bump my retirement age to 68 and
  essentials to £32k" → the right child; "increase my pension" (two pensions) → asks which; an invented
  figure never reaches an override.
- **Green invariant + Pint** as always.

## 8. Open questions for Rob

1. ~~**Widen the doctrine?**~~ **RESOLVED 2026-07-04 — full widen** (what-ifs + gated base editing).
   DECISIONS entry due at build/checkpoint.
2. ~~**Base editing now or defer?**~~ **RESOLVED 2026-07-04 — defer to Phase 3, `can_edit_base` default
   off.**
3. **Phase 1 editable-field menu** — is the list in §5.1 the right v1 breadth, or narrower/wider?
   (Default: as listed.)
4. **Adds/removes** in v1 or Phase 2? (Default: Phase 2.)
5. **Tab name** — "Change plan", "What-if", or fold into a relabelled panel? (Default: "Change plan".)

## 9. Docs to update at checkpoint (not now)

DECISIONS.md (the doctrine widening + the design); RESEARCH-local-assistant.md (§0/§3/A4 now have a
sanctioned exception — the model may assemble a reviewable what-if); [PLAN.md](PLAN.md) backlog +
HANDOVER; `config/assistant.php` header comment ("never builds" → the narrowed form). Cross-link this
doc from the Sibling-docs table.
