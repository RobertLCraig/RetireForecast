# Withdrawal sequencing #5 and #6: PCLS timing and the search optimiser

## Why
Slices 1 to 4 of [docs/build/PLAN-withdrawal-sequencing.md](../../build/PLAN-withdrawal-sequencing.md)
are shipped (the named "Fill the bands" strategy, the Compare tie-in, the results panel and the
advice-gated steer). #5 and #6 are specced and not built.

This card previously sat in `human-review/` saying the two slices were "gated on two modelling
judgements that are yours to make". **That was stale.** Re-reading the plan on 2026-08-01 found
both already answered in its own "Decisions (Rob, 2026-07-01)" section: item 5 rules that the
planner **may time the PCLS** rather than leaving it user-specified, and item 6 puts the
**search-optimiser in scope, sequenced last**. Item 4 settles the PA-taper band as in scope too.
Nothing is waiting on a person, so this is buildable work rather than a decision owed.

## Not this card
The optimiser (#6) is the second half and is explicitly sequenced last in the plan; do not start it
before #5 is green. Multi-property and Section 24 interactions are card 0019.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the "Fill the bands" strategy draws from a DC pot, THE APP SHALL take the draw
      UFPLS-style so the 25% tax-free element is applied, and lifetime tax SHALL fall against the
      pinned pre-#5 figure.
- [ ] #2 THE APP SHALL NOT draw from a pot before its owner reaches that pot's access age, under
      any ordering the planner chooses (the 2026-07-02 access-age gate must not regress).
- [ ] #3 THE APP SHALL NOT fill beyond the MPAA once flexible access has been triggered.
- [ ] #4 WHEN a household is on Guarantee Credit, THE APP SHALL prefer capital (ISA / PCLS,
      disregarded as income) over pension income that would claw the credit back pound for pound.
- [ ] #5 WHEN the optimiser runs, THE APP SHALL report the lifetime-tax delta as the difference
      between two of the engine's own runs, never a re-derivation.
<!-- AC:END -->

## Tasks
- [ ] #5 planner-timed PCLS: make FillBands draws UFPLS-style (plan section "#5")
- [ ] Pin the access-age gate with a test that an under-access-age pot is never UFPLS-drawn
- [ ] #6 bounded search over orderings (plan section "#6"), last
- [ ] Read the plan's "Coordination (READ before touching PathProjector)" note first
