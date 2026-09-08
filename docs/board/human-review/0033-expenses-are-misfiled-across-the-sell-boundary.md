# Selling a flat deletes utilities the household still has to pay

## Why
From the expert panel, 2026-08-19 (property findings 11 and 12). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Three categorisation errors, all of which flatter a result.

**Utilities hidden inside a service charge.** Where a service charge includes water and
electricity, `withoutPropertyCosts()` strips the whole charge on every sell variant while the
household keeps only whatever separate energy line was entered. A house or a park home still needs
energy and water, so every sell and buy plan is optimistic by that amount. A park home on bottled
gas is worse.

**Home insurance categorised as discretionary.** Buildings cover on a leasehold flat sits inside
the service charge, so a separate insurance line is contents plus something else. Whatever it is,
it is not discretionary while a lender requires cover. Being outside the essential floor flatters
the "essentials always met" probability on every scenario.

**Running costs as a percentage of value.** A blank `buyRunningCosts` derives 1% of value. A roof,
a boiler and a rewire cost the same in a cheap area as an expensive one, so a percentage of value
is a proxy for stock quality, not a cost driver, and it understates at the low end.

## Links

**Relates to**
- `0028` - major works and the service-charge escalator are that card, and its escalator now fires
  on whatever this card decides is a property expense.

## Not this card
Major works and the service-charge escalator, which is card 0028.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a home whose service charge includes utilities is sold, THE APP SHALL add a replacement utilities cost to the new housing situation.
- [x] #2 THE APP SHALL treat buildings and contents insurance as essential spend wherever cover is required.
- [x] #3 WHEN a purchase running cost is derived rather than entered, THE APP SHALL disclose it as a computed figure with the rule that produced it.
<!-- AC:END -->

## Tasks
- [x] Add a "service charge includes utilities" flag, and carry a replacement cost across a sale
- [x] Reclassify insurance as essential; check `AssumedFiguresDisclosureTest` still passes
- [ ] Review `HOME_MAINTENANCE_RATE_BPS` against a flat-rate alternative, sourced
- [ ] Re-run every stored scenario, then `php artisan scenarios:audit`

## Comments
**2026-09-05**
RESULT: done
TESTS: +16 new, all green
TOUCHED:
packages/finance-engine/src/Dto/ExpenseProfile.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/src/Sweep/Lever/EssentialSpendLever.php
packages/finance-engine/src/Sweep/Lever/DiscretionarySpendLever.php
packages/finance-engine/tests/Forecast/ServiceChargeUtilitiesTest.php
app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
tests/Unit/Forecast/ExpenseLineMisfilingTest.php
tests/Unit/Forecast/ComputedRunningCostsDisclosureTest.php
tests/Feature/Livewire/ScenarioBuilderTest.php
docs/DATA-MODEL.md
docs/spec/ASSUMPTIONS.md
docs/board/todo/0094-the-bought-homes-upkeep-is-a-percentage-of-its-value.md
OUT-OF-SCOPE: 0094

**#1, the utilities.** `ExpenseProfile::$propertyCostsUtilities` is a marked subset of
`propertyCosts`, in the same shape as `mortgageCosts` beside it. `withoutPropertyCosts()` now removes
`propertyCosts + mortgageCosts` LESS the utilities, and the projector's mid-projection forced sale
does the same arithmetic, so both routes out of the home leave the water and the electricity in the
essential floor as ordinary always-charged spend. The replacement keeps no marker afterwards: nothing
may strip it twice, and the CPI+3% service-charge escalator has no business on an energy bill. The
guard at the top of `withoutPropertyCosts()` reads the GROSS buckets, because a charge that is
entirely utilities nets to nothing to remove and the old guard would have returned the profile
untouched, leaving a sold home's marker on a plan with no home.

The figure is the reader's, never the engine's, so nothing is assumed and no disclosure is owed for
it. It is entered per spend line (`expenseLines.*.utilities`, stored sparsely) and the input is shown
only on a while-owning-home line, which is the only place the assembler reads it.

**#2, insurance.** `HouseholdAssembler::tierOf()` is the one rule: a DISCRETIONARY line whose label
names insurance and the home (buildings / contents / home / house / property) counts in the essential
floor. Three surfaces read it, because a split would let a screen and the projection disagree about
the same pounds: the forecast, the builder's live tier totals and `ResultPresenter::expenseBreakdown()`.
Nothing else moves, so pet, travel and car cover stay where the reader put them, and a switched-off
line is still excluded from everything.

I did NOT change the reader's stored `category`, and I did not force the tier at input time. The
assembler is the one gate every forecast passes through, including stored scenarios and imports that
never reopen the builder, so the rule belongs there. The cost is that the Tier dropdown still shows
what the reader chose while the totals beside it show Essential.

**#3, the computed running cost.** The gap was the OTHER branch. The 1%-of-value fallback was already
disclosed; the branch that scales the current home's running costs by the two prices said nothing, and
`AssumedFiguresDisclosureTest::test_a_derived_upkeep_figure_is_not_reported_as_assumed` pinned that
silence deliberately. It is right that it is not an ASSUMED figure, so it now carries a
`computed_figure` note of its own instead and that test is untouched and still green. The note states
the rule, not just the pounds, and reads the engine's own answer through
`HousingComparison::newHomeRunningCosts()`, which is now public and static for exactly that reason.

**Test-first.** The engine test and the assembler test were watched failing on the arithmetic, not on
a missing class: the sale deleted the whole £4,000 charge (essential floor £26,000 where it should be
£27,500, forced-sale spend £16,000 where it should be £17,500), the utilities figure arrived as zero,
and the insurance line left the floor at £18,000 instead of £18,600. The computed-figure test was
watched returning an empty list. The two ScenarioBuilderTest cases were written AFTER the blade and
the round-trip plumbing, so they record what it does rather than catching it doing the wrong thing.

**ENGINE_VERSION is bumped** to `finance-engine/expenses-across-the-sell-boundary`. The insurance
reclassification is what moves stored figures: any plan carrying such a line had an essential floor
that was too low, so its "essentials always met" probability and its capacity-for-loss reading are too
favourable. The utilities figure is new input, so no stored scenario carries one and no sell plan
moves until somebody enters it.

**Task 3 is open and could not be settled here.** `HOME_MAINTENANCE_RATE_BPS` was reviewed: the 1% is
the Checkatrade rule of thumb and is sourced, but the card's objection is to the BASIS, and choosing
between a percentage of value and a flat annual figure needs a published maintenance-cost series that
an unattended session cannot fetch (no web access). Raised as card **0094**, and the figure is now
documented in ASSUMPTIONS.md §17 rather than living only in a docblock. AC#3 asked only for the
disclosure, which is done.

**Task 4 is open.** Re-running the stored scenarios writes to the live database, and this was built in
a worktree that must not touch it. `scenarios:audit` cannot come back clean until that happens, and it
already exits non-zero on the pre-existing missing integrity stamps. **Nothing here has been seen in a
browser either**, which matters more than usual: the new "of that, how much buys water, gas or
electricity?" input on step 4 has only been proved by a render assertion.

**The sweep levers.** `EssentialSpendLever` and `DiscretionarySpendLever` rebuild `ExpenseProfile`
positionally, so I passed the new field through both rather than ship a fresh hole. They still drop
`propertyCostsRealGrowth`, which I left alone: that is not this card's, and it is already written up as
finding 2 of the breakage review on card 0028.

### 2026-09-05 review (v20260905141658-eae6)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 285s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to code.

**#1** `ExpenseProfile::withoutPropertyCosts()` subtracts the charge **less** `propertyCostsUtilities()`, so the water and power stay in the essential floor. Its only caller, `HousingComparison::withHousing()`, builds both sell variants. The mid-plan forced sale repeats the same sum in `PathProjector::ÔÇª` (the `homeSold` branch). `HouseholdAssembler::expenseProfile()` sums the figure from while-owning-home lines only, and `ScenarioBuilder::spendKeepsUtilities()` shows the input on exactly those lines (both use the same condition rule). The value saves and reloads.

**#2** `HouseholdAssembler::tierOf()` is one rule, read by all three totals: `HouseholdAssembler::essentialAndDiscretionary()`, `ScenarioBuilder::expenseTotals()`, `ResultPresenter::expenseBreakdown()`. I searched for another place that groups spend lines by the raw stored category and found none.

**#3** `ResultPresenter::inputNotes()` adds a `computed_figure` note for the scale-by-price branch, and reads the pounds from `HousingComparison::newHomeRunningCosts()` instead of repeating the sum. Its four conditions match that method's branch exactly. The 1% branch is disclosed by `ResultPresenter::assumedFigures()`. Both views print notes by text, so nothing is filtered by kind.

I tried to break it and could not.

VERDICT: sound

**scope: defect**

**Fence: clean.** Nothing crossed into 0028. Major works still drop whole (`ExpenseProfile::withoutPropertyCosts()` one-off filter) and the escalator in `PathProjector::projectYear()` is untouched. The extra surfaces (`ScenarioBuilder::expenseTotals()`, `ResultPresenter::expenseBreakdown()`) are one-rule consistency, not creep.

**Left half done:**

1. Task 4 has no owner. `ScenarioForecaster::ENGINE_VERSION` is bumped, so every stored result is now stale against its stamp, and `scenarios:audit` is the release gate in CLAUDE.md. No card on the board carries that re-run, and the card is marked done with the task open. Task 3 at least got card 0094; this got a paragraph.

2. The new sub-figure is not carried by `ScenarioResults::applySliders()`. It scales each line's `amount` and leaves `utilities` fixed, so a negative spend slider drives the clamp in `ExpenseProfile::propertyCostsUtilities()` until the whole charge reads as utilities and a sell variant keeps a service charge it should drop.

3. `ResultPresenter::plsaBenchmark()` still subtracts the entire `propertyCosts()`, including the part now known to buy water and energy, which the PLSA basis includes. The one figure this card created is ignored by a surface that needs it.

VERDICT: defect

**breakage: defect**

I read the commit, the engine, the assembler, the presenter and the callers.

**1. The energy bill escalates while you own it, then drops back when you sell.**
`PathProjector::projectYear` grows the whole `propertyCosts` bucket at the default CPI+3% (`ExpenseProfile::propertyCostsRealGrowth`), utilities included. When `homeSold` is true it switches that growth off completely and keeps only base-level utilities. `ExpenseProfile::withoutPropertyCosts` does the same for year-0 sells. So the same water and electricity rises above inflation on "stay put" and stays flat on every sell and buy plan. Over 20 years that is roughly 1.8x. It makes selling look cheaper, which is the bias this card exists to remove. The `withoutPropertyCosts` docblock says no service-charge escalator belongs on an energy bill; the owning branch still puts one there.

**2. A comment the change made false.** In `PathProjector::projectYear`, "a forced sale subtracts it above, so the escalation follows the bucket for free". It does not any more: part of the bucket now stays and loses its escalation.

**3. The spend slider drifts the subset.** `ScenarioResults::applySliders` scales each line's `amount` and never its `utilities`. Slide spend +20% and the charge grows while the utilities inside it do not. Silent.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
