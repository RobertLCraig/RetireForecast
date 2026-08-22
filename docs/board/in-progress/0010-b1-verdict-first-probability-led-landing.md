# B1: verdict-first, probability-led landing

## Why
Next by the build order in PLAN-output-inflation-and-charts.md. The results page currently leads
with detail rather than an answer, so the first thing a reader sees is not the thing they came
for.

## Not this card
The B2 to B4 results-page restructure (tabs, tables into `<details>`, banners demoted), A3 fat
tails, A4 State-Pension uprating.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL lead the landing with the Monte-Carlo probability and its word band,
      reusing the `/afford` screen rather than a new surface.
- [ ] #2 WHEN a scenario has no completed Monte Carlo run, THE APP SHALL say so plainly rather
      than showing a deterministic figure in the probability's place.
<!-- AC:END -->

## Tasks
- [ ] Reuse `/afford` as the landing
- [ ] Probability + word band above the fold
- [ ] Empty state for a scenario with no MC run

## Plan
Presenter-level change; the probability and bands already exist. No engine work.
