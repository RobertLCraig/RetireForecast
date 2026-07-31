# Assistant scenario editing

## Why
docs/build/PLAN-assistant-scenario-editing.md has approved scope and is not built. The assistant
can currently answer about a scenario but not change one.

## Not this card
Anything outside the approved scope in that plan.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the assistant proposes a scenario edit, THE APP SHALL show the change before
      applying it, never mutating a stored scenario without confirmation.
- [ ] #2 THE APP SHALL keep every assistant-driven edit inside the same builder-state model the
      UI writes, so an assistant edit and a manual edit are indistinguishable afterwards.
<!-- AC:END -->

## Tasks
- [ ] Re-read the approved scope
- [ ] Edit proposal + confirmation step
- [ ] Write through the existing builder-state path
