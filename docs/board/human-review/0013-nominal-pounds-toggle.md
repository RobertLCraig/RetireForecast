# Nominal-pounds toggle

## Why
Every figure the results page reports is in today's money, and the reader cannot get at any other
view of it. A pension statement, a mortgage balance and a bank balance are all quoted in the pounds
of the year they are printed in, so a reader who wants to check the forecast against the paper they
already hold has nothing to check it against.

Nobody decided to leave it out. It was slice #3 of PLAN-output-inflation-and-charts.md and the
slice shipped without it.

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

### 2026-08-29 review (v20260829164606-c6c3)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 292s, run by this job rather than reported by the card.

**acceptance: sound**

I traced both criteria to real code and tried to break them.

**AC #1 ÔÇö a nominal-versus-real toggle.** `resources/views/livewire/partials/time-series-charts.blade.php` renders a checkbox bound `wire:model.live="nominalPounds"`, guarded by `$timeSeries['nominalAvailable']`. The property is `App\Livewire\ScenarioResults::$nominalPounds`, passed in `ScenarioResults::render()` to `ResultPresenter::timeSeriesCharts()`. The partial is included by `resources/views/livewire/scenario-results.blade.php`. The basis is named in the prose, the axis titles, the sr-only captions and the chart `wire:key`.

**AC #2 ÔÇö figures come from the engine, not re-inflated.** `PathProjector::projectYear` builds the year through one closure and calls it twice: once with the deflating map, once with `Money::fromPence(...)` (identity), so the twin is the pre-deflation integers. `PathProjector::project` attaches growth and charges to the twin undivided via `YearResult::withInvestmentGrowth`. `ResultPresenter::timeSeriesCharts` reads `YearResult::$nominal` and never multiplies a real figure back up; with no twin it returns real figures and `nominalAvailable` false, so the toggle hides rather than mislabels.

The forecast is projected live (`ScenarioForecaster::deterministicVariants`), so twins are always present on this page.

Gap, not an acceptance failure: `ScenarioResultsTest` has no test for the checkbox itself.

VERDICT: sound

**scope: sound**

I read the card, the commit `5507a0f`, and the code it touched.

**Fence:** the card said "not the wealth chart's terminal p25/p75". The commit does not touch the fan or its percentiles. The fence holds.

**Growth beyond the ask:** the only engine change is one construction site, `PathProjector::projectYear` (plus the flow carry in `PathProjector::project`), and one nullable field, `YearResult::$nominal`. `ResultPresenter::sourceOccurs` changed signature only because `incomeStaircase` now takes a year list. Nothing else grew. The twin is built on Monte Carlo paths too, but `Simulator` discards years per path and nothing is stored, so the cost is the measured ~4%, not a hidden one.

**Half done:** the toggle covers three charts, not the whole page. That is what the card's own source, slice #3 of `docs/build/PLAN-output-inflation-and-charts.md`, asked for, and `ScenarioResults::render` leaves the PDF (`App\Export\ScenarioReport`) on real pounds, which the on-screen prose states. The checkbox follows the existing `includeHome` pattern in the same component.

The browser look and the a11y run are named in the card as open.

VERDICT: sound

**breakage: defect**

Four things this breaks or leaves unproven.

**1. Stale docblock.** `ResultPresenter::timeSeriesCharts()` still opens "Built from the SAME `ForecastResult::$years` the cashflow `ladder()` reads, so a chart can never drift from the ladder". With `$nominal` true the charts read `$year->nominal` and the ladder does not. The claim is now false, and the reconciliation test it cites does not cover that path.

**2. Stale docblock.** `YearResult::mortgageBalance()` says "(real money, zero if none)". On a twin it is nominal pence, and `ResultPresenter::wealthComposition()` calls `homeEquity()` on twins.

**3. Rule asserted in one place, not the other.** The earlier "Money over time" entry in `docs/DECISIONS.md` still states item 3: "**Real (today's-money) terms only**" and "**The nominal-pounds toggle ÔÇª is deferred**". It was never superseded.

**4. Edge case not built.** `tests/Feature/Livewire/ScenarioResultsTest.php` never sets `nominalPounds`; it only asserts the heading renders. The presenter is proven, the component wiring (checkbox ÔåÆ `wire:model.live` ÔåÆ the new chart `wire:key` swap) is not, and the card says it has never been opened in a browser.

Also untested: `incomeStaircase()` ranks the >8-source fold by horizon total, which nominal skews to late years, so the "Other" band can differ per basis.

VERDICT: defect


## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 2 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 2 of 2 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
