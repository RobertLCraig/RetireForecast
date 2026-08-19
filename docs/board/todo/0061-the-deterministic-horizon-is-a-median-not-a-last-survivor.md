# The central case plans to a median lifespan, and that is what everyone reads

## Why
From the expert panel, 2026-08-19 (adviser finding 5). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`RepresentativeDeathAge::forHousehold` gives each person their **own independent median** death
age. For a couple the relevant statistic is the **last survivor**, whose median is materially later
and whose tail is far fatter. On the cohort tables a woman of 66 has roughly a one-in-four chance of
reaching her mid-nineties and one-in-ten of reaching about 99. The deterministic path stops her
around 89.

The decision-support plan already records this bias and correctly forces crossings into the Monte
Carlo. But the whole comparison table, the affordability limit tests and the per-month analysis are
all **deterministic**, and the results page still leads with detail rather than the probability.

So every plan is ranked on a depletion year taken from a coin-flip lifespan. For a household with a
long expected survivor period that leaves roughly even odds of a decade of unfunded life.

The adviser's sharpest point: printing that a plan "lasts for life" against a median lifespan is
the single most misleading string the tool can produce.

## Not this card
Leading the page with the probability, which is card 0010.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL set the deterministic planning horizon on a household last-survivor basis, not on each person's own median.
- [ ] #2 THE APP SHALL default that horizon to a stated high percentile, and offer 50th, 75th and 90th as named settings on the age-of-death lever.
- [ ] #3 WHEN a median-lifespan figure is shown, THE APP SHALL label it as roughly even odds rather than as a plan lasting for life.
<!-- AC:END -->

## Tasks
- [ ] Add `CohortLifeTable::percentileDeathAge()` and a last-survivor derivation
- [ ] Switch the deterministic horizon; ship the 75th percentile as the default
- [ ] Name the settings on the age-of-death lever
- [ ] Relabel the median output; re-run every stored scenario
