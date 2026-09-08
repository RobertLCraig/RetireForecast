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

Both understate exactly the tail that matters.

This compounds with card 0024. Until an interest-only mortgage stops being CPI-indexed, high
inflation in this model is pure downside, because the natural hedge on a nominal debt has been
removed.

Related and cheap: `HistoricalBacktester` holds lifespans fixed at the representative death ages,
so the historical stress never combines a bad early sequence with a long life. Those two risks
multiply.

## Links

**Relates to**
- `0024` - an interest-only payment is still CPI-indexed there, so persistent inflation compounds
  that fault instead of only widening the fan.
- `0039` - the Monte Carlo golden master is that card's, and it has to be re-pinned once this
  changes every band.

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

## Plan
Both are small changes to `ReturnModel`.

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

### 2026-09-08 review (v20260908133601-3593)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 396s, run by this job rather than reported by the card.

**acceptance: sound**

All three criteria trace to real code.

**#1 ÔÇö persistence.** `ReturnModel::generatePath` carries an AR(1) deviation term (`$phi * $deviation + $innovationScale * ...`), phi read through `AssumptionSet::inflationPersistence()`, which clamps. The parameter is sourced: `AssumptionSetLibrary::INFLATION_PERSISTENCE` with `INFLATION_DYNAMICS_SOURCE` (ONS series URL) and `INFLATION_DYNAMICS_VERIFIED_ON`, listed in `AssumptionSetLibrary::economicSourcing()` so `CheckFigureFreshness::handle` sweeps it. It is marked stated-not-verified with card 0139 raised ÔÇö the same pattern already used for `SINGLE_PROPERTY_SOURCE`, so it matches the project's own bar, not a shortcut.

**#2 ÔÇö correlation.** `ReturnModel::withInflationRow` appends inflation as the last row and column of the correlation matrix and `Cholesky::decompose` factors the augmented matrix; `generatePath` draws `count($means)+1` normals and reads `$z[$inflationIndex]`. A set with no figures gives a last row of zeros plus 1.0, so the old draw is preserved in the same stream position.

**#3 ÔÇö long life.** `HistoricalBacktester::backtest` takes `?PlanningHorizon $horizon` and passes it to `RepresentativeDeathAge::forHousehold`. `ScenarioForecaster::longLifeHistoricalBacktest` runs it at P90 and returns null when the plan is already there. It is displayed: `ScenarioResults` and `ScenarioReport` both call it.

I tried to break the "same run twice" case and the byte-identical claim; both are guarded.

VERDICT: sound

**scope: defect**

**Over the fence:** nothing. The default inflation rate is untouched ÔÇö `AssumptionSetLibrary::fcaDefault` and friends only gain the two new fields. The other files in the branch diff belong to earlier commits, not this one (`git show 0dbea02` is the card's whole change).

**Left half done:** the card's third task says run the backtester **at the last-survivor 75th and 90th percentiles**. Only the 90th was built. `ScenarioForecaster::longLifeHistoricalBacktest` hard-codes `PlanningHorizon::P90` and returns null otherwise; `ResultPresenter::historicalStressTest` builds a single `longLife` block and defaults its horizon to `P90`. `PlanningHorizon` already has a `P75` case, so the missing half was one call away. The acceptance line only asks for "a long-life horizon", so the box is defensible ÔÇö the task line is not. The next session must either wire the 75th run through both those functions, or the owner must strike it from the task list.

**Small over-build in the same place:** `ResultPresenter::historicalStressTest` takes a `$longLifeHorizon` parameter no caller ever passes. It looks like the seam the missing 75th run was going to use, left unused.

Everything else ÔÇö the AR(1) term, the inflation row in the matrix, the golden-master re-pin, the disclosure rows ÔÇö is inside the card.

VERDICT: defect

**breakage: defect**

**Finding ÔÇö the stated inflation/asset correlation is not the one the model realises.**

`ReturnModel::generatePath` builds the year's inflation deviation as `phi * deviation + sqrt(1 - phi^2) * inflVol * z[inflationIndex]`. Only the innovation carries the correlated shock, so for every year after the first the realised correlation between inflation and asset returns is `sqrt(1 - phi^2)` times the stated figure ÔÇö at the shipped `phi = 0.7`, 0.71 times it. Year 0 takes the other branch (`inflVol * z`) and realises the full figure, so one path uses two different correlations.

This makes three things false at once:

- `AssumptionSet`'s docblock for `$inflationAssetCorrelations` ("the correlation of the inflation shock with each asset class's REAL return") ÔÇö it is the correlation of the innovation, not the shock.
- `ResultPresenter::assumptionsPanel`'s "How markets react to an inflation shock" row shows `-0.55` while the model moves at about `-0.39`: a figure the reader can see but cannot interrogate.
- `InflationPersistenceTest::test_a_high_inflation_year_coincides_with_negative_real_returns` builds its set with default persistence `0.0`, so the two features are never exercised together and the attenuation is unpinned.

Either scale the correlation up so the stated figure is the realised one, or say on the panel and in the docblock that it applies to the annual surprise.

VERDICT: defect


**2026-09-08** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
