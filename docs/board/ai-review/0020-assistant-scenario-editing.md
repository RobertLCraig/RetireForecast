# Assistant scenario editing

## Why
docs/build/PLAN-assistant-scenario-editing.md has approved scope and is not built. The assistant
can currently answer about a scenario but not change one.

## Not this card
Anything outside the approved scope in that plan.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the assistant proposes a scenario edit, THE APP SHALL show the change before
      applying it, never mutating a stored scenario without confirmation.
- [x] #2 THE APP SHALL keep every assistant-driven edit inside the same builder-state model the
      UI writes, so an assistant edit and a manual edit are indistinguishable afterwards.
<!-- AC:END -->

## Tasks
- [x] Re-read the approved scope
- [x] Edit proposal + confirmation step
- [x] Write through the existing builder-state path

## Comments

**2026-08-29** Built Phase 1 of the approved plan and nothing wider: value edits to fields that
already exist, no add/remove rows (Phase 2), no base editing (Phase 3, and I added no
`can_edit_base` key for behaviour that is not built).

What is there. A "Change plan" tab on the assistant panel, behind its own switch
`config('assistant.can_edit_scenarios')` (env `ASSISTANT_CAN_EDIT_SCENARIOS`), **off by default**, so
nothing changes for anyone until it is deliberately turned on. The reader says what they want in
their own words; the panel shows the change as a diff and writes nothing; a Create click stores it.

- **#1** is two steps in the component that cannot be collapsed into one: `proposeChange()` only
  fills `$proposedEdits` (component state), `confirmChange()` is the only path that writes. The
  confirm card is `WhatIfChanges::compute`, the same base-value → new-value list a saved what-if is
  already described by, so the reader checks the figures on a surface they know. The base plan is
  never edited at all: a confirmed change creates a **new delta-child**, so "without confirmation"
  is not merely unlikely, there is no code path to it.
- **#2** goes through a new `App\Forecast\WhatIfWriter`, extracted from `QuickWhatIfController`,
  which now uses it too. So the quick presets and the assistant write a what-if the same way: a
  sparse `overrides` dot-path delta over the base, `builder_state` empty, columns projected from the
  effective state. The test asserts the stored shape, not just that a row exists.

Guardrails, all named in the plan and each with a test: **C1** a figure the reader never said is
refused (`ScenarioEditGrounding`, the input-side twin of G1: it catches the £410k → £41k
transposition); **C2** the model may only pick from a menu the app builds out of the base's own
form-state (`ScenarioEditVocabulary`), so it cannot name a path that does not exist; **C3** the
confirm step above; **C4** child only. A no-op, an unreadable value and an unusable model reply all
come back as a question, never as a guess, and never as a scenario.

Assumed, and worth a second opinion:
- **The what-if is named with the reader's own sentence**, truncated ("what if I retire at 68?"),
  rather than a generated label. It is their words, so it needs no grounding of its own, and it
  reads well in Compare. Easy to change if it looks untidy in the list.
- **A retired person is offered no salary or retirement age.** Both are blank in the form for them,
  so proposing a figure would move nothing the reader can see. A person going *back* to work is a
  change of employment status, which is not a Phase 1 value edit.

Two things I could not settle from the repository:
- **The E2E pass against a real `qwen3:14b`** that every earlier assistant phase met. This session
  has no local Ollama, so the guardrails are proved against a fake client instead. The prompt has
  never met the real model, so the shape of its JSON is untested in life. Worth one manual run.
- **No browser check** is possible from this worktree (Herd serves the main checkout), so the panel
  has been proved by tests only. It also needs `npm run build` after the view change.

One defect found on the way, outside this card's scope and now noted in HANDOVER:
`BuilderStateDelta::merge` cannot create a map the base does not have, so an
`assumptionOverrides.<key>` override on a base that overrides no assumption is dropped and reported
as an orphan. That hits hand-built what-ifs too, not just this feature. I worked around it rather
than widening the card: the menu offers an assumption only once the base already carries one, which
is also the plan's own "every target is a real, resolvable path" rule.
