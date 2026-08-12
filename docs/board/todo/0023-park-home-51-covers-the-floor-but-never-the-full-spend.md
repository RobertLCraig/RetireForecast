# Scenario 51 covers the essentials 76% of the time and the full spend 0% of the time

## Why
The full Monte Carlo on 2026-08-12 (10,000 paths, seed 20260812) put scenario 51 "Park home Tring
GBP 150k" at **76.4% on essentials and 0.0% on full spend**. Every other plan in the set has the two
figures within a few points of each other; a 76-point gap is not a gradient, it is a cliff, and
0.0% means the discretionary budget was never once met in ten thousand futures.

That is either a true and important finding (the plan is affordable only if they give up all
discretionary spending, permanently) or an artefact. It cannot be left ambiguous, because 51 sits
sixth on the ranked chart and reads as a plan that works.

There is already a standing doubt about this scenario: SCENARIO-V2.local.md carries a warning that
the "51 cannot complete, the GBP 46,412 gap exceeds their savings" note predates the 2026-07-29
rebuild, and that `scenarios:audit` now reports 51 as never running short. Two unexplained things
about the same scenario is one too many.

## Not this card
Do not re-price the park homes, do not touch the other three park-home scenarios unless the same
defect is found in them, and do not change the ranked report. This card establishes whether the
figure is real. Acting on it is a separate card.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN scenario 51 is projected, THE APP SHALL show why the full-spend probability is 0.0%,
      traced to a named input (the pitch fee plus upkeep, the depreciation, the purchase gap, or the
      discretionary line itself) rather than to an unexplained interaction.
- [ ] #2 IF the 0.0% is an artefact of the GBP 46,412 completion gap being modelled as unfunded,
      THEN THE APP SHALL either charge that gap visibly or the scenario SHALL be corrected, so no
      stored scenario reports a probability that rests on an unseeable figure.
- [ ] #3 WHEN the check is complete, THE APP SHALL have the stale "51 cannot complete" warning in
      SCENARIO-V2.local.md either confirmed and restated with current figures, or removed.
- [ ] #4 WHEN `php artisan scenarios:audit` is run afterwards, THE APP SHALL exit 0.
<!-- AC:END -->

## Tasks
- [ ] Project 51 and 53 side by side (both park homes without the art sale) and diff the year rows;
      53 scores 76.1% on full spend, so the pair isolates what 51 does differently.
- [ ] Check whether the purchase gap on 51 is funded, charged, or silently absorbed.
- [ ] Confirm the essentials/full-spend split is reading the same expense profile the builder stored.
- [ ] Record the finding in SCENARIO-V2.local.md and correct or delete the stale warning.
