# The growth assumption can be raised without raising the risk

## Why
From the expert panel, 2026-08-19 (adviser finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. The adviser called this the finding an investment
committee would stop on.

**Allocation is hardcoded.** `ForecastSettings::allocation()` falls back to a cautious 40/60 and
`ScenarioBuilder` passes that in explicitly. It is not among the editable assumptions. It is the
largest single determinant of the answer and the user cannot touch it.

**The growth override moves return without moving risk.** `AssumptionOverrides::apply` handles an
investment-growth override with a uniform parallel shift of every asset class's **mean**, leaving
volatilities and correlations untouched. So a user who raises growth because they hold equities gets
an equity return at a cautious portfolio's volatility. That manufactures a free lunch inside a Monte
Carlo whose entire job is to price risk.

**There is no glidepath.** The portfolio is 40/60 at 66 and 40/60 at 99. No de-risking option
exists.

**The bond assumption may be stale.** A zero real return on a 60% bond weight is a large part of the
blended figure, and it is stale in the cautious direction against recent index-linked gilt yields -
which also suppresses the modelled value of the de-risked, secured-income strategies a cautious
household most needs.

## Links

**Relates to**
- `0038` - disclosing the current default mix is that card, and this one makes the mix an input
  that disclosure then has to describe.

## Not this card
Disclosing the current default, which is card 0038.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL expose portfolio allocation as an editable input, defaulting to the current cautious mix.
- [x] #2 WHEN a user changes the expected return, THE APP SHALL change the volatility with it, or refuse the change.
- [x] #3 THE APP SHALL offer a de-risking glidepath over the projection.
- [x] #4 THE APP SHALL carry a source and a verified-on date for each asset class return and volatility.
<!-- AC:END -->

## Tasks
- [x] Add allocation to the builder and to `ForecastSettings`
- [x] Make the growth override re-weight the mix rather than shift the means
- [x] Add a glidepath option
- [ ] Re-source the bond real return against current index-linked gilt yields, dated

## Comments

**2026-09-08**
RESULT: done
TESTS: +12 new, all green
TOUCHED:
packages/finance-engine/src/Forecast/AllocationProfile.php (new)
packages/finance-engine/src/Forecast/PortfolioAllocation.php
packages/finance-engine/src/Forecast/DeterministicPathDraws.php
packages/finance-engine/src/Forecast/HistoricalSequenceDraws.php
packages/finance-engine/src/MonteCarlo/ReturnModel.php
packages/finance-engine/src/Dto/AssetClassAssumption.php
packages/finance-engine/src/Dto/AssumptionSet.php
packages/finance-engine/src/Assumptions/AssumptionSetLibrary.php
packages/finance-engine/tests/Forecast/PortfolioAllocationTest.php (new)
packages/finance-engine/tests/Assumptions/AssetClassSourcingTest.php (new)
packages/finance-engine/tests/Dto/AssumptionSetOverrideTest.php
packages/finance-engine/tests/Forecast/BuyToLetFinanceCostTest.php
app/Forecast/AssumptionOverrides.php
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
app/Finance/Mapping/AssumptionSetMapper.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Unit/Forecast/AssumptionOverridesTest.php
docs/DECISIONS.md
docs/spec/ASSUMPTIONS.md
docs/board/todo/0137-re-source-the-asset-class-returns-and-volatilities.md (new)
OUT-OF-SCOPE: 0137

The mix is four named profiles on one axis, how much is in shares (`AllocationProfile`:
defensive 20/80, cautious 40/60, balanced 60/40, growth 80/20), because that axis sets the
return and the risk together. Cautious is the default and is byte-identical to the hardcoded
40/60, so nothing stored moves until a reader chooses, and it keeps disclosing itself as ours.
It rides the sparse `assumptionOverrides` choice-key route the planning horizon already uses,
so a scenario stored earlier carries no key and reads as the default.

Criterion 2 is met by re-weighting, which is what the card's Task asked for:
`PortfolioAllocation::forBlendedRealReturn` moves weight between equities and bonds until the
blend is the target, so the volatility rises with it, and `AssumptionSet::withRealReturnShift`
is DELETED rather than left for somebody to call. A target outside what any mix of the set's
asset classes can earn is refused in the builder with the range that is reachable. A scenario
stored before this card can still hold such a figure, so the forecast clamps it to the closest
mix and the assumptions panel names both figures; that clamp is a call I made rather than
throwing on a stored plan, and DECISIONS 2026-09-08 records it.

The glidepath is a second profile plus a number of years, straight-line, then flat. It is read
PER YEAR by all three draw sources (deterministic, Monte Carlo, historical backtest), so no
surface can show a de-risking plan the projection did not run. **A glidepath has no default
length**: the reader must state the years, because every lifestyling convention gives a
different one and a length we picked would move their money invisibly.

`ENGINE_VERSION` is `finance-engine/growth-is-bought-with-risk` and the **stored-scenario re-run
is owed**: a plan carrying a growth edit keeps its central projection (the blended mean is
unchanged by construction) and moves its whole Monte Carlo, so its success odds and bands are
not comparable across the stamp. A plan with no growth edit and no chosen mix is byte-identical,
which is why `GoldenMasterTest` did not redden and needs no re-pin.

**Criterion 4 is met as CARRIED, not as re-verified.** Each asset class now holds a source and a
verified-on date for its return and for its volatility separately (they come from different
places: FCA for the returns, the long-run record for the volatilities), surfaced on the results
page and in the PDF. The URLs are the ones docs/spec/ASSUMPTIONS.md already carried and the date
is the 2026-06-24 sign-off: **this session has no web**, so nothing was fetched. That, and the
card's fourth Task (re-source the gilt real return against current index-linked gilt yields),
are card **0137**. The task is left unticked for the same reason.

Built in a worktree, so the three new builder controls, the new portfolio-spread row, the
sourcing list and the rewritten allocation disclosure **have not been seen in a browser**.

### 2026-09-08 review (v20260908112821-6fac)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 292s, run by this job rather than reported by the card.

**acceptance: sound**

I checked every criterion against real code.

**#1 ÔÇö allocation is an editable input.** `ScenarioBuilder::rules()` validates `assumptionOverrides.allocation` against `AllocationProfile` cases, the view renders the select, and `ScenarioBuilder::selectedAllocation()` falls back to `AllocationProfile::DEFAULT` (the old 40/60). Real.

**#2 ÔÇö return moves risk, or is refused.** `AssumptionOverrides::allocation()` re-weights via `PortfolioAllocation::forBlendedRealReturn()`, which moves equity/bond weight, so `PortfolioAllocation::blendedVolatility()` moves with it. Out-of-range targets are refused by the closure rule on `assumptionOverrides.investmentGrowth` in `ScenarioBuilder::rules()`, backed by `AssumptionOverrides::unreachableGrowthTarget()`. `withRealReturnShift` is gone. Real.

**#3 ÔÇö glidepath.** `PortfolioAllocation::glidingTo()` and `::at()` hold the arithmetic; all three draw sources read it per year: `DeterministicPathDraws::investmentReturn()`, `HistoricalSequenceDraws::investmentReturn()`, and `ReturnModel::path()`. Real.

**#4 ÔÇö source and verified-on per figure.** `AssetClassAssumption` carries `returnSource`/`returnVerifiedOn` and `volatilitySource`/`volatilityVerifiedOn`; `AssumptionSetLibrary::fcaSourced()` and `::dmsSourced()` set them for every shipped class, guarded by `AssetClassSourcingTest`. The card says "carried, not re-verified" and hands re-sourcing to card 0137 ÔÇö that matches the wording of #4.

Nothing broke. The unticked Task is honestly out of scope.

VERDICT: sound

**scope: sound**

**Scope check on commit `9f8c9f2` (card 0062).**

What I looked for and did not find:

- **Fence break.** "Not this card" is card 0038's disclosure. `ResultPresenter::assumedFigures` only *reworded* the existing allocation note so it stops saying "not something you can change on this screen", which the card made false. That is maintaining 0038's text, not doing 0038.
- **Creep into other systems.** `ScenarioForecaster::assumptions` / `buildSettings` gained `presetAssumptions` and an `$overrides` local; both are needed to solve the mix. `AssumptionSetLibrary::fcaSourced` / `dmsSourced` and `ResultPresenter::assumptionsPanel`'s `assetSourcing` are AC #4, not extra.
- **Collateral edits.** `BuyToLetFinanceCostTest::yearsByCalendar` changed only because `AssumptionSet::withRealReturnShift` was deleted; no caller of it survives anywhere.
- **Half-done engine wiring.** The glidepath is read per year in all three draw sources, including `MonteCarlo\ReturnModel::generatePath`, so no surface shows a de-risking plan the projection did not run.

Left open, and said out loud rather than hidden: Task 4 (re-source the gilt real return) is unticked and carded as 0137, and the new builder controls were built in a worktree and not opened in a browser.

VERDICT: sound

**breakage: defect**

Two things break quietly.

**1. The assumptions panel calls a reader's own mix "the preset".**
`AssumptionOverrides::changedKeys` filters only `KEYS`; the new mix keys live in `CHOICE_KEYS`. So when a reader picks Balanced or Growth (and no `investmentGrowth`), `ResultPresenter::assumptionsPanel` sets `customised => false` and marks the `investmentGrowth` and `portfolioVolatility` rows `edited => false` ÔÇö while both VALUES have moved off the preset, because they are read from `$allocation->blendedRealReturn()` / `blendedVolatility()`. The panel then shows the reader their own figures wearing the named preset's label. The other `CHOICE_KEYS` (uprating, horizon) do not sit in `$economic`, so this card is what made the gap bite. `AssumptionsPanelTest` never builds a state with `allocation` set and no rate override.

**2. A glidepath silently switches off the "this mix is ours" disclosure.**
`AssumptionOverrides::allocation` returns non-null as soon as `allocationGlideTo` is filled, substituting `AllocationProfile::DEFAULT` for the START mix. `ForecastSettings::allocationIsAssumed` is `$this->allocation === null`, so `ResultPresenter::inputNotes` drops the cautious-mix disclosure even though the starting mix is still the engine's. That is an engine default reaching a projection undisclosed ÔÇö the exact rule card 0038 exists for.

VERDICT: defect


**2026-09-08** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
