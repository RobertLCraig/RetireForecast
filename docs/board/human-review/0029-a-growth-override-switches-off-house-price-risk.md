# A per-property growth override switches off house-price risk

## Why
From the expert panel, 2026-08-19 (property finding 13, adviser finding 9). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

In `PathProjector::growState` (around line 2362), if `propertyGrowthReal` is set the home grows at
that fixed real rate. Otherwise it takes the stochastic draw. So any overridden property becomes
**deterministic** in the Monte Carlo.

That is backwards. The properties people override are the ones whose value is least certain - a
park home, a specific flat in a slow block - and overriding is exactly how they say so. The
scenario that most needs a fan of outcomes gets a straight line instead. The salary override
already has the right design: it sets the trend, not the risk.

Separately, the modelled house volatility is an **index** figure. An index diversifies away the
property-specific component; a single property carries roughly double the dispersion. A household
whose whole net worth is one flat is not exposed to index risk.

## Not this card
The value of any particular growth rate. That is a scenario input.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a property carries a growth override, THE APP SHALL apply it as the mean and keep drawing year-to-year variation around it. proves: `test_a_growth_override_sets_the_mean_and_keeps_the_year_to_year_variation`
- [x] #2 THE APP SHALL apply a sourced single-property volatility uplift to a primary residence, disclosed as an assumed figure and editable. proves: `test_the_single_property_volatility_uplift_is_disclosed_with_its_value`
- [x] #3 WHEN a depreciating home such as a park home is modelled, THE APP SHALL use a wider volatility than the index default. proves: `test_a_depreciating_park_home_is_modelled_over_a_wider_spread_than_the_index`
<!-- AC:END -->

## Tasks
- [x] Make the override set the mean, not replace the draw, in `growState`
- [x] Add a sourced single-property volatility multiplier to `AssumptionSet`
- [x] Disclose it through `assumedFigures()`
- [ ] Re-run the park-home scenarios and report the range, not a point estimate

## Comments

**2026-09-05**
RESULT: done
TESTS: +9 new, all green
TOUCHED:
  packages/finance-engine/src/Dto/AssumptionSet.php
  packages/finance-engine/src/Forecast/PathDraws.php
  packages/finance-engine/src/Forecast/DeterministicPathDraws.php
  packages/finance-engine/src/Forecast/HistoricalSequenceDraws.php
  packages/finance-engine/src/Forecast/PathProjector.php
  packages/finance-engine/src/MonteCarlo/SampledPathDraws.php
  packages/finance-engine/tests/MonteCarlo/SinglePropertyVolatilityTest.php (new)
  packages/finance-engine/tests/Forecast/CareCostInflationTest.php
  packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php
  app/Forecast/ResultPresenter.php
  app/Forecast/AssumptionOverrides.php
  app/Forecast/ScenarioForecaster.php
  app/Finance/Mapping/AssumptionSetMapper.php
  app/Livewire/ScenarioBuilder.php
  app/Livewire/ScenarioResults.php
  app/Export/ScenarioReport.php
  app/Console/Commands/AuditScenarios.php
  tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
  tests/Unit/Forecast/AssumptionOverridesTest.php
  tests/Unit/Finance/MappingRoundTripTest.php
  tests/Feature/Console/AuditScenariosTest.php
  docs/spec/ASSUMPTIONS.md
  docs/DECISIONS.md
  docs/HANDOVER.md
  docs/board/todo/0086-source-the-single-property-volatility-multiple.md (new)
OUT-OF-SCOPE: 0086

**What was built.** `PathDraws::houseGrowthReal($yearIndex)` is replaced by
`propertyGrowthReal($yearIndex, ?float $meanReal)`. The sampled house path is an index path, so
`SampledPathDraws` splits each draw into its centre and its shock, re-centres the shock on the
property's own mean (its growth override, or the set mean where there is none) and scales it by
`AssumptionSet::singlePropertyVolatilityMultiple()`. `ReturnModel` is untouched, so the RNG stream
is byte-identical and a seed still lines up draw for draw; only what the home does with each draw
changed. The deterministic and historical drivers have no shock, so they return the mean and the
central projection is unchanged, which is why every worked example and reconciliation test still
passes. `ENGINE_VERSION` is bumped to `finance-engine/single-property-house-risk`.

`AssumptionSet` gains one nullable field, `singlePropertyVolatility`, and the constant
`SINGLE_PROPERTY_VOLATILITY_MULTIPLE = 2.0`. Null derives the figure as index times the multiple,
which is the shape `ExpenseProfile::propertyCostsRealGrowth()` already uses: an explicit figure is
the reader's own and wins, and only the derived case reports as assumed. It is editable as the
`propertyVolatility` assumption (builder step 1, validated 0 to 60) and disclosed by
`assumedFigures()` on any plan that keeps or buys a home, reading the constant and both figures out
of the set rather than restating them.

**Watched red before the code, and what each red said.** The three simulator tests were written
against the unchanged engine with only the DTO field added, so each failed on behaviour and not on
a missing symbol: the overridden home's band was NARROWER than the same home under a set with no
house volatility at all (16,342,494 against 17,029,124 pence, the override having switched the risk
off); the uplifted and index-only bands were the identical 79,093,397; the park home carried no
house risk whatever. `test_a_property_volatility_edit_reaches_the_set` failed on 1800 where 1200 was
typed, and `test_the_single_property_volatility_uplift_is_disclosed_with_its_value` on nought
disclosures where one was owed.

`test_the_uplift_scales_the_index_figure_rather_than_replacing_it` is the exception and was green
the first time it ran. It is arithmetic over accessors written in the same step, so it watched
nothing catch anything; it earns its place as the drift guard that fails if the constant and the
derived figure ever stop agreeing, not as evidence of the defect.

**Two tests carry AC#2 beyond the one named above:**
`test_a_single_property_is_modelled_over_a_wider_spread_than_the_index` (applied) and
`test_a_property_volatility_edit_reaches_the_set` (editable).

**A check this change falsified, and fixed.** `scenarios:audit` reports a shipped assumption-set key
absent from a stored payload as a figure "NOT reaching any forecast". `singlePropertyVolatility` is
null on every shipped set by design, and the mapper hydrates missing and null identically, so the
audit started raising three false alarms on the live sets. `staleAssumptionSets()` now checks only
keys carrying a value. Guarded by `test_a_shipped_figure_that_is_null_by_design_is_not_reported_as_missing`,
watched failing first. With it, `php artisan scenarios:audit` is back to its known state: nought
problems other than the 120 pre-existing "no integrity stamp" lines.

**On "sourced" in AC#2, and read this before accepting the tick.** The multiple carries a source and
a verified_on date in the sense CLAUDE.md requires, and the constant's docblock and ASSUMPTIONS.md
§13 both name it: the property reviewer's judgement in the 2026-08-19 expert review. It is NOT a
published series. This session has no web access, so no primary source could be fetched or checked,
and §13 flags it as the second sourcing gap in that document beside card 0085's. Card **0086**
carries the citation. That is the same footing card 0028 shipped its CPI + 3% on; if you would
rather this criterion stayed open until a published figure lands, untick it and 0086 closes it.

**What I could not settle here.** The last task is not done. Re-running the stored park-home
scenarios writes results stamped with an engine version that exists only on this branch, into Rob's
live database, from a worktree, so I left it; the re-run is owed and is in HANDOVER.md. Reporting
their range on this card would also put the couple's own figures on a tracked file, which the data
hygiene rule forbids. What I could check read-only is that `scenarios:audit` still finds nothing
wrong with #51 to #54, which is the expected result: the deterministic path a park home is audited
on does not move, only the fan around it does. The range itself needs the re-run, and no browser has
seen the new builder input or the new results row.

### 2026-09-05 review (v20260905081707-6513)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 198s, run by this job rather than reported by the card.

**acceptance: sound**

I traced all three.

**AC#1 ÔÇö override sets the mean, risk stays.** `PathProjector::growState` now calls `$draws->propertyGrowthReal($yearIndex, $state['propertyGrowthReal'])`. `SampledPathDraws::propertyGrowthReal` takes the sampled index draw, removes the set centre, re-centres on the override, and keeps the shock. The shock maths matches how `ReturnModel::path` builds the house series (`houseMean + houseVol * z`), so the split is exact. Test `test_a_growth_override_sets_the_mean_and_keeps_the_year_to_year_variation` exists in `SinglePropertyVolatilityTest`.

**AC#2 ÔÇö uplift applied, disclosed, editable.** Applied: `AssumptionSet::singlePropertyVolatilityMultiple` feeds `SampledPathDraws`. Disclosed: `ResultPresenter::assumedFigures` gates on `singlePropertyVolatilityIsAssumed` and reads both figures and the constant. Editable: `AssumptionOverrides::apply` maps `propertyVolatility`, `ScenarioBuilder::rules` validates it, `ScenarioBuilder::render` lists the field, and the blade binds it. Named test exists.

**AC#3 ÔÇö park home.** Same primary-residence path; the negative override is the mean and the widened shock still applies. Named test exists and also checks the home still falls.

"Sourced" is a reviewer judgement, not a published series. That is the same footing `careCostRealGrowth` shipped on, it is flagged in `docs/spec/ASSUMPTIONS.md`, and card 0086 is raised.

VERDICT: sound

**scope: sound**

I read the commit `04a30fe`, both drivers, the DTO, the app plumbing and the callers.

**Did it cross the fence?** No. The card said "not the value of any growth rate". `AssumptionSet::houseGrowth` is untouched. The new constant is a volatility multiple, which AC#2 asked for by name.

**Did anything grow quietly?** One thing grew, but not quietly. `AuditScenarios::staleAssumptionSets()` now skips every shipped key whose value is null, not only the new one. I checked `AssumptionSetMapper::hydrate()`: every nullable key uses `isset()`, so a missing key and a null key load the same. The check loses nothing real, it has a test, and the card comments declare it.

**What is half done?** Card task 4, the park-home re-run and range. It is unticked and written up as owed in `docs/HANDOVER.md`. It needs Rob's live database, so a worktree session cannot do it.

**Small edge:** `AssumptionSet::singlePropertyVolatilityMultiple()` returns 1.0 when a set has no index volatility, so the new builder field would do nothing on such a set. No shipped set is like that.

VERDICT: sound

**breakage: defect**

**Breakage lens ÔÇö card 0029**

**1. The widened shock has no floor, so a home can be worth less than nothing.**
`SampledPathDraws::propertyGrowthReal` multiplies the index shock by the multiple. It does not bound the result at -100%. `PathProjector::growState` then does `state['property'] * (1.0 + $propertyNominal)` with no floor.

Shipped set "DMS historical" has an 11% index, so one home is 22%. A draw of z <= -4.6 makes real growth <= -100%. That is about one path-year in a 10,000-path run. Before this change the same event needed z <= -11, which never happens. The new `propertyVolatility` field is validated to 60% (`ScenarioBuilder::rules`), and card AC#3 invites a wide figure for a park home. At 30% it happens in roughly 1 year in 2,600.

A negative value never recovers, it compounds. It reaches `propertyWealth`, and at the no-negative-equity cap `min($rolled, $state['property'])` it clears a lifetime mortgage for free. No test builds a tail draw.

**2. Stale docblock.** The `SampledPathDraws` class docblock still says house growth "follow[s] their sampled per-year path". It no longer does.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
