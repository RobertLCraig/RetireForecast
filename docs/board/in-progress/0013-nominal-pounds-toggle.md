# Nominal-pounds toggle

## Why
Deferred from slice #3 of PLAN-output-inflation-and-charts.md. It needs the engine's internal
pre-deflation figures exposed rather than a presenter re-inflation, because re-inflating in the
presenter would drift from the engine's own numbers.

## Not this card
The wealth chart's terminal p25/p75, also still open from slice #3.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL offer a nominal-versus-real toggle on the results figures.
- [x] #2 THE APP SHALL take nominal figures from the engine's own pre-deflation values, never
      by re-inflating a deflated figure in the presenter.
<!-- AC:END -->

## Tasks
- [x] Expose pre-deflation figures on the engine DTOs
- [x] Toggle on the results page
- [x] Assert the two paths agree on a known case

## Direction
**2026-08-22** Built the toggle. `YearResult` gained `$nominal`: the same year in the projector's
own pre-deflation pounds. `PathProjector::projectYear` now assembles the year once through a money
mapper and calls it twice, so real and nominal come from the identical nominal integers; the
growth and charges attached in `project()` are carried to the twin undivided (they are year N to
N+1 flows, deflated by next year's price level, which the test accounts for). Nothing re-inflates.

`ResultPresenter::timeSeriesCharts()` takes a `$nominal` flag and reads the twin; `ScenarioResults`
gained a `$nominalPounds` property and the "Money over time" section a checkbox, with the basis
named in the prose, the axis titles, the sr-only table captions and the chart `wire:key`.

Scope: the toggle covers the three time-series charts, which is the scope slice #3 of
PLAN-output-inflation-and-charts.md specified. The ladder, fan, headline cards and PDF stay in real
terms, and the prose beside the charts says which basis is in force so the two are never confused.
Widening it to the whole page is a separate decision, not this card's.

Assumed: that a "known case" means the cases where the two bases must coincide exactly. With zero
inflation they must be identical field for field; with inflation they must be identical in the base
year (price level 1.0) and must deflate back to one another within a penny thereafter, with a
guard that the nominal figures are visibly larger so the test cannot pass on a twin that is
secretly the real year. Engine test `NominalTwinTest`, presenter tests in `TimeSeriesChartsTest`.

Cost: the twin is one extra `YearResult` per projected year, on Monte Carlo paths too. Measured at
about 4% of a projected year's work (1.8µs against 46.7µs), so it is unconditional rather than
gated behind a flag. If a full run ever feels slower, gating it is a one-line change.

Not settled from the repository: nothing blocking. Two things a person still has to do. The suite
runs here from `php artisan test` (this project has no `vendor/bin/pest.bat`; the runner is
PHPUnit 12, as CLAUDE.md documents) and `vendor/bin/pint.bat` is clean, and `scenarios:audit` exits
0 over all 25 stored scenarios. But this is a ProgressBoard worktree and Herd serves the site from
`C:\Dev\RetireForecast`, so **the toggle has never been looked at in a browser** and `npm run
a11y:auth` (which needs the app served) has not been run against the new checkbox. Both still need
a pass on the merged tree.
