# Figures the engine supplies for itself, that the user cannot see or change

## Why
From the expert panel, 2026-08-19 (engineer finding F5, adviser finding 12). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

The project's own hard rule is that no figure reaches a projection without being disclosed. These
do.

**The State Pension triple-lock floor.** `PathProjector::growState` multiplies the State Pension
factor by the greater of inflation and 2.5%. No source, no `verified_on`, no `AssumptionSet` field,
no control, no disclosure. Drawing inflation around 2%, the floor binds in most years, so the State
Pension grows in **real** terms for ever. It also uprates the Pension Credit guarantee, so it moves
the benefit floor too. Assuming the triple lock survives four decades is the optimistic branch of
contested policy, chosen silently - which is the opposite of the standing "adverse default,
user-editable" rule.

**The portfolio allocation.** `ForecastSettings::allocation()` falls back to a cautious 40/60 and
nothing ever passes anything else. It is the largest single determinant of the answer and the user
cannot touch it. See card 0062 for the risk half of this.

**The care assumptions.** `CareAssumptions::default()` supplies care probabilities, a mean duration
and weekly fees to every projection, none of them disclosed.

## Not this card
Exposing allocation as an input, which is card 0062.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL disclose the State Pension uprating floor, the portfolio allocation and every care assumption as assumed figures, each reading the constant that owns it.
- [ ] #2 THE APP SHALL let a user choose between full triple lock, triple lock to a stated year then a lower basis, and inflation only.
- [ ] #3 WHEN any of these defaults is used, THE APP SHALL show its value and why it applies.
<!-- AC:END -->

## Tasks
- [ ] Add `statePensionUpratingFloor` to `AssumptionSet`, sourced and dated
- [ ] Read it in `growState`; add the three-way UI control
- [ ] Add the allocation and care assumptions to `ResultPresenter::assumedFigures()`
- [ ] Extend `AssumedFiguresDisclosureTest` to require all of them
