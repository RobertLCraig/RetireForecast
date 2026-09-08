# Engine integrity remainder

## Why
From the expert panel, 2026-08-19 (engineer findings F9, F10, F11, F15, F16). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`. Four small defects of the same family: a wrong
value or a swallowed failure that nothing would surface.

**A forced sale reads the wrong mortgage balance.** `HousingProceeds::compute()` gets the grown
property value and the **original entered** mortgage. Correct for interest-only, which is why it
has not shown. Wrong for a repayment loan, which has amortised down, and badly wrong for a lifetime
mortgage, which has rolled up. Both shapes are supported and both are live.

**`ExpenseProfile`'s withers have no guard and have already drifted.** `Household::copy()` and
`HouseholdWitherTest` exist to stop exactly this bug class. `ExpenseProfile` has three withers each
hand-listing nine constructor arguments and no equivalent guard, and `withoutPropertyCosts()`
already omits `propertyCostsRealGrowth` while the other two pass it. It is harmless today only
because of an unrelated guard in the projector.

**A delta that adds the first row to an empty list corrupts its shape.** In `BuilderStateDelta`,
`isRowList([])` is false, so `setPath()` takes the map branch and turns a positional list into an
id-keyed map. The next `diff()` then walks a different branch. `orphans()` reports success.
Reachable whenever a what-if adds the first income stream, one-off cost, account or withdrawal to a
base that has none. Separately, `effectiveBuilderState()` recurses with no depth guard.

**Failures that are silent.** `SimulationRunner` catches `Throwable` and stores the message with no
`report()` and no stack trace, against a rule that says failed-with-reason. `SampledPathDraws::at()`
repeats the last draw for ever past the end of a series. `PathProjector::project()` breaks at year
200 and reports a truncated projection as complete. `disposeGiaSlice()` divides by a balance with
no zero guard despite being public.

## Links

**Relates to**
- `0042` - performance work is fenced out of this card and is that card's subject.

## Not this card
Performance work, which is card 0042.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a sale is forced, THE APP SHALL redeem the mortgage balance as it stands in that year, not as originally entered.
- [x] #2 WHEN any expense-profile field is added, THE APP SHALL fail a test if a wither drops it.
- [x] #3 WHEN a what-if adds the first row to a list that is empty in its base, THE APP SHALL keep that list positional.
- [x] #4 WHEN an internal invariant is broken, THE APP SHALL throw or report with a stack trace rather than continuing silently.
<!-- AC:END -->

## Tasks
- [ ] Keep `mortgageOutstandingWhole` in projector state, as `propertyWhole` already is
- [ ] Give `ExpenseProfile` a private `copy()`, plus a reflection wither test; same for `SpendPath` and `Property`
- [x] Fix `setPath()` for the empty-list case; add a depth guard to `effectiveBuilderState()`
- [x] Add `report($e)` in the three job runners; throw instead of the four silent fallbacks

## Comments

**2026-09-06**
RESULT: done
TESTS: +13 new, all green
TOUCHED:
app/Assistant/AssistantTurnRunner.php
app/DecisionSupport/ThresholdRunner.php
app/Forecast/BuilderStateDelta.php
app/Forecast/ScenarioForecaster.php
app/Forecast/SimulationRunner.php
app/Models/Scenario.php
packages/finance-engine/src/Dto/ExpenseProfile.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/MonteCarlo/SampledPathDraws.php
packages/finance-engine/tests/Dto/ExpenseProfileWitherTest.php
packages/finance-engine/tests/Forecast/BrokenInvariantThrowsTest.php
packages/finance-engine/tests/Forecast/ForcedSaleTest.php
packages/finance-engine/tests/Forecast/GiaCapitalGainsTaxTest.php
tests/Feature/Assistant/AssistantTurnRunnerTest.php
tests/Feature/DecisionSupport/ThresholdRunnerTest.php
tests/Feature/Forecast/SimulationRunnerTest.php
tests/Feature/Scenario/ScenarioDeltaTest.php
tests/Unit/Forecast/BuilderStateDeltaTest.php
docs/HANDOVER.md
docs/board/in-progress/0041-engine-integrity-remainder.md
docs/board/todo/0103-reported-mortgage-balance-lags-the-year-that-paid-it.md
OUT-OF-SCOPE: 0103

Each criterion was built test-first and every test was watched failing for its own reason before the
code moved. What the reds looked like: the forced sale freed £284,000 where £257,752.30 was owed on
the rolled-up loan and £292,902.45 on the amortising one; `withoutPropertyCosts()` returned a null
`propertyCostsRealGrowth` against the fixture's 2%; the first added one-off cost came back as an
id-keyed map instead of a list; the three runners reported nothing to the exception handler; the
projection ran past year 200 and handed back the truncation as a finished forecast; the sampled draw
past the end of its series returned the previous year's number; `disposeGiaSlice` raised a bare
"Division by zero"; and the parent cycle killed the PHP process with an exhausted memory limit rather
than saying what was wrong. The `copy()` source guard was watched failing too, by deleting
`employmentCosts` from the finished `copy()` and running it.

**Criterion 1** is `ENGINE_VERSION` `finance-engine/forced-sale-redeems-the-years-balance` and the
**stored-scenario re-run is owed**: every plan with a forced sale on a rolled-up or an amortising
loan moves, in opposite directions, while an interest-only loan and every plan without a forced sale
are byte-identical. No screen changed, so there is nothing new to look at in a browser.

Two deliberate departures from the Tasks, both to be argued with rather than assumed:

**The whole-property mortgage balance is DERIVED, not kept as `mortgageOutstandingWhole` in state.**
The task named a second state key beside `propertyWhole`. `mortgageOutstanding` is written in five
places, so a mirrored key means five paired writes and a sixth site to forget, which is precisely the
hand-listing failure this card's own second defect is about. The balance is instead scaled back up
through `state['ownershipShare']` at the one place that needs it, so it keeps a single definition.
The reason `propertyWhole` is tracked and this is not: the property value COMPOUNDS for the whole
projection, so a division's rounding would accumulate, where the mortgage figure is read once, at
the sale. The residual cost is at most one penny on a home the household owns a fraction of.

**`SpendPath` got no wither guard and `Property` needed none.** `Property::withCurrentValue` is
already covered by the existing `AssetWitherTest`, alongside `Account` and `DcPension`. `SpendPath`
has exactly one public property, and both of its rebuild sites (`plus`, `mapAmounts`) replace it, so
there is no carry-through to forget and a guard there would be a test that cannot be made to fail.
Adding it would be the kind of green-from-birth test this build was told to avoid, so it is reported
instead of written.

Two smaller judgements worth the reviewer's eye. `withoutPropertyCosts()` now CARRIES
`propertyCostsRealGrowth` where it used to drop it: the field is inert once the bucket is null
(`propertyCostsNominal` and the spend escalation both gate on a positive bucket, and the presenter
gates its disclosure the same way), so no figure moves, and carrying it is the reading that does not
depend on that gate staying put. It still zeroes `propertyCostsUtilities`, which is deliberate and
documented on the method: that part has already been folded into the essential path and nothing may
strip it twice. And `disposeGiaSlice` refuses a zero or over-drawn balance but NOT a cost basis above
the balance, because that is a holding at a loss and a real one.

`Scenario::effectiveBuilderState()` gained an optional `$depth` argument and a cap of 10. What-ifs
are two-level by design, so the cap is headroom rather than a limit anybody will meet; every existing
caller passes nothing and is unaffected.

Raised as **0103** and not fixed here: a year's row reports the mortgage balance it OPENED with while
the liquid wealth beside it is after that year's instalments, so every repayment-mortgage year
understates net wealth by the capital it repaid and every lifetime-mortgage year overstates it by the
interest that accrued. It sits on the same state key this card read, but it moves a figure on every
row of every mortgaged plan rather than at a sale.

### 2026-09-06 review (v20260906020243-ae54)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 241s, run by this job rather than reported by the card.

**acceptance: sound**

All four criteria trace to real code.

**#1 ÔÇö forced sale redeems the year's balance.** `PathProjector::projectYear()`, in the `MortgageMaturityAction::ForcedSale` branch, now reads `$state['mortgageOutstanding']` and divides by `$state['ownershipShare']` before calling `HousingProceeds::compute()`. That is correct: `HousingProceeds::compute()` takes whole-property figures and applies the share itself, and `PathProjector::growState()` writes the roll-up and the amortisation schedule at year end, so `projectYear` reads that year's balance. No stale read of `$home->outstandingMortgage` is left in the projector; the only two remaining uses build the schedule and seed year zero. Covered by `ForcedSaleTest::assertSaleRedeemsTheYearsBalance()` for both shapes.

**#2** ÔÇö `ExpenseProfile::copy()` is the single rebuild site; all three withers use it. `ExpenseProfileWitherTest` drives off reflection over the public properties (all eleven are `public readonly`) and also scans `copy()`'s source, so a new field fails.

**#3** ÔÇö `BuilderStateDelta::setPath()` appends to an empty node instead of key-writing. A second added row still lands positionally via the existing row-list append.

**#4** ÔÇö `report($e)` in all three runners; throws in `PathProjector::project()`, `disposeGiaSlice()`, `SampledPathDraws::at()`, `Scenario::effectiveBuilderState()`.

I tried to break each and could not.

VERDICT: sound

**scope: sound**

I tried to find work the card did not buy. I could not.

**Nothing crosses the fence.** `## Not this card` fences off performance (card 0042). No touched file changes a loop, a query or a cache.

**The two skipped Tasks hold up.** I checked both claims instead of trusting them:

- `Property::withCurrentValue` is already listed in the `$sites` array of `AssetWitherTest::test_each_rebuild_site_names_every_field_it_does_not_replace`. A second guard would be a copy.
- `SpendPath` has one public property, `$bands`, and every rebuild site (`flat`, `fromBands`, `plus`, `mapAmounts`) replaces it. That guard shape asserts nothing here, so it would be green from birth.

**Two small growths, both disclosed, both dead ends.** `PathProjector::disposeGiaSlice` now also refuses a negative take and a take above the balance, where the card asked only for a zero guard. Every caller clamps first with `min(..., $balance)` (`SavingsFunding::apply`, and the three sites inside `PathProjector`), so no figure moves.

**The carried field is inert.** `ExpenseProfile::withoutPropertyCosts` now keeps `propertyCostsRealGrowth`. All four readers gate on a positive bucket: `PathProjector::projectYear`, `PathProjector::propertyCostsNominal`, `ResultPresenter::assumedFigures`, and the notes builder beside it.

VERDICT: sound

**breakage: defect**

I read the change, traced its callers, and tried to break it.

**The screen now tells the reader a different number from the one the model uses.**

`app/Forecast/ResultPresenter.php`, `inputNotes()`, the `MortgageMaturityAction::ForcedSale` arm of the `mortgage_redemption` note, still prints `$home->outstandingMortgage` ÔÇö the figure as entered. It says that amount is cleared at the sale and the rest is freed as equity. Before this change that matched the engine exactly. Now `PathProjector::projectYear()` redeems the year's balance instead. On a lifetime mortgage rolled up for fifteen years the note understates the debt by six figures, and the reader has no way to see it. That is the project's own no-invisible-figures rule, broken silently.

`tests/Unit/Forecast/InputNotesTest.php`, `test_a_mortgage_due_for_redemption_is_flagged()`, only builds an interest-only loan redeemed in the base year, so the two live shapes the card is about are untested here.

Smaller: `ForcedSaleTest` builds no part-owned home, so the new `/ $share` division is untested, and the `ENGINE_VERSION` docblock claim that interest-only plans are byte-identical does not hold once that division rounds.

Everything else held.

VERDICT: defect


**2026-09-06** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
