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

## Not this card
Performance work, which is card 0042.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a sale is forced, THE APP SHALL redeem the mortgage balance as it stands in that year, not as originally entered.
- [ ] #2 WHEN any expense-profile field is added, THE APP SHALL fail a test if a wither drops it.
- [ ] #3 WHEN a what-if adds the first row to a list that is empty in its base, THE APP SHALL keep that list positional.
- [ ] #4 WHEN an internal invariant is broken, THE APP SHALL throw or report with a stack trace rather than continuing silently.
<!-- AC:END -->

## Tasks
- [ ] Keep `mortgageOutstandingWhole` in projector state, as `propertyWhole` already is
- [ ] Give `ExpenseProfile` a private `copy()`, plus a reflection wither test; same for `SpendPath` and `Property`
- [ ] Fix `setPath()` for the empty-list case; add a depth guard to `effectiveBuilderState()`
- [ ] Add `report($e)` in the three job runners; throw instead of the four silent fallbacks
