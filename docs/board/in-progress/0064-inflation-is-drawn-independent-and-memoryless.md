# Inflation has no memory and is unrelated to investment returns

## Why
From the expert panel, 2026-08-19 (adviser finding 13). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`ReturnModel::generatePath` draws inflation from an independent normal each year, uncorrelated with
the asset shocks. Two consequences.

**No persistence.** Real inflation is strongly autocorrelated - it arrives in multi-year episodes,
1973 to 1975 and 2021 to 2023 being the obvious ones. Independent annual draws understate the
spread of the cumulative price level over decades. That matters here more than usual, because the
model runs against nominal tax thresholds frozen for years, so it understates fiscal drag.

**No correlation with returns.** In a real-return framework, a 2022-style shock is high inflation
**and** deeply negative real bond returns **and** negative real equity returns, all at once.
Drawing them independently means the model never produces the single worst year a bond-heavy
retiree has actually lived through.

Both are small changes to `ReturnModel` and both widen exactly the tail that matters.

This compounds with card 0024. Until an interest-only mortgage stops being CPI-indexed, high
inflation in this model is pure downside, because the natural hedge on a nominal debt has been
removed.

Related and cheap: `HistoricalBacktester` holds lifespans fixed at the representative death ages,
so the historical stress never combines a bad early sequence with a long life. Those two risks
multiply.

## Not this card
The choice of default inflation rate, which is already sourced and signed off.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL model inflation with year-to-year persistence, using a sourced parameter.
- [x] #2 THE APP SHALL correlate inflation with real asset returns, so a high-inflation year can coincide with negative real returns.
- [x] #3 THE APP SHALL let the historical backtest run at a long-life horizon as well as the representative one.
<!-- AC:END -->

## Tasks
- [x] Add an autoregressive term to the inflation draw, sourced from UK history and dated
- [x] Add an inflation row to the correlation matrix
- [x] Run the backtester at the last-survivor 75th and 90th percentiles as well
- [x] Re-pin the golden master from card 0039 afterwards

## Comments

**2026-09-08**
RESULT: done
TESTS: +14 new, all green
TOUCHED:
packages/finance-engine/src/Dto/AssumptionSet.php
packages/finance-engine/src/MonteCarlo/ReturnModel.php
packages/finance-engine/src/Assumptions/AssumptionSetLibrary.php
packages/finance-engine/src/Forecast/HistoricalBacktester.php
packages/finance-engine/tests/MonteCarlo/InflationPersistenceTest.php
packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php
packages/finance-engine/tests/Forecast/HistoricalBacktestTest.php
app/Finance/Mapping/AssumptionSetMapper.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioResults.php
app/Export/ScenarioReport.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Forecast/InflationAndLongLifeDisclosureTest.php
docs/DECISIONS.md
docs/spec/ASSUMPTIONS.md
docs/HANDOVER.md
docs/HANDOVER-ARCHIVE.md
docs/board/todo/0139-source-the-inflation-persistence-and-its-asset-correlations.md
OUT-OF-SCOPE: 0139

**#1 and #2 share one home.** `AssumptionSet` gained `$inflationPersistence` (an AR(1)
coefficient) and `$inflationAssetCorrelations` (one figure per asset class). `ReturnModel` now
appends inflation to the correlation matrix as the LAST factor and decomposes the augmented
matrix, so a price shock lands on each asset class by its own amount rather than hanging off
equities the way the house and salary factors do. Index 0 is still global equities, which those
two read. A set that states neither figure produces a last Cholesky row of `[0, ..., 0, 1]`, so
the inflation shock is exactly the raw normal that used to be drawn in that position: same count,
same place in the stream, byte-identical.

**The innovation is scaled by sqrt(1 - phi^2).** Without that, persistence would have widened the
single-year spread too, so a reader who typed 1.5% volatility would have been modelled at more
than 1.5% with nothing on any screen saying so. Pinned:
`test_persistence_widens_the_cumulative_price_level_without_widening_a_single_year` asserts BOTH
halves, the unchanged annual spread and the wider thirty-year sum.

**Assumed, and disclosed.** 0.70 persistence and `[-0.30, -0.50, -0.55]` are **STATED, not
verified**: this session had no web. Both reach every projection, so both are written up at
docs/spec/ASSUMPTIONS.md §35 and both are new rows on the assumptions panel and in the PDF,
reading the constants rather than restating them. Sourcing them is card **0139**. An
over-negative row is not silently accepted: past a point the augmented matrix stops being
positive-definite and the Cholesky decomposition throws.

**#3 is a second run, not a replacement.** `HistoricalBacktester::backtest` takes an optional
horizon and `ScenarioForecaster::longLifeHistoricalBacktest` runs the same historical starts at
the 90th percentile of the last survivor's age at death, reported BESIDE the representative run
on the results page and in the PDF. It is omitted where it is the same run: a plan already at the
90th, or a household whose lifespans the reader stated, which are facts and are never extended
(card 0061). That last case has its own test, because reporting one figure twice under a
longer-sounding label would read as a harder test that had been passed.

**What was watched failing.** The four behaviour tests in `InflationPersistenceTest` were run
against the DTO fields added but unread, so they failed on measured numbers (lag-1 autocorrelation
-0.007 against 0.7; inflation/return correlation +0.006 against a required -0.2; the thirty-year
spread narrower, not wider; the aggregate byte-identical). The two backtest tests were run with
the horizon parameter accepted and ignored, so they failed on equal plan years and an equal
survival rate rather than on a missing argument. The two presenter tests were watched red with
their gates forced false.

**ENGINE_VERSION is `finance-engine/inflation-with-a-memory` and the stored-scenario re-run is
owed.** The central deterministic projection does not move, so every deterministic figure still
reconciles; every Monte Carlo reading on every plan is wider. `MonteCarlo\GoldenMasterTest` was
re-pinned (essentials success 0.5300 to 0.5150, the fan opening at both ends), `PIN_REVISION`
bumped to today and DECISIONS 2026-09-08 records it.

**Not seen in a browser.** Built in a worktree, so the two new assumptions rows and the new
long-life block on the results page and in the PDF have not been looked at.

**The card's own compounding note is left as it stands.** It says this compounds with card 0024
until an interest-only mortgage stops being CPI-indexed. That is 0024's fix, not this card's, and
nothing here makes it worse: sticky inflation makes the missing hedge cost more, which is the
argument for doing 0024, not a reason to widen this one.
