# A let home's running costs are charged as the household's own, council tax and all

## Why
A property carries a `runningCosts` figure covering maintenance, insurance and council tax, and the
projector charges it as the household's essential spend for as long as they own the home. That is
right for a home they live in. It is wrong twice over for one they let out and live somewhere else:

1. **Council tax on a let home is the tenant's bill, not the landlord's.** The forecast keeps
   charging it, so a let-to-let plan is charged council tax on two homes while paying it on one.
2. **The rest of it (repairs, landlord insurance) is a deductible letting expense**, and the
   forecast taxes the rental profit as though it had not been spent. This is exactly the fault
   card 0030 fixed for the service charge, on the sibling figure it did not touch.

Both errors run in opposite directions, so the net effect on a particular plan cannot be guessed:
the council tax overstates the cost of letting, and the missing deduction overstates the tax on it.
Neither is visible to the reader; the results page states the council-tax overcharge as a caveat
(card 0030's `letting_caveats` note) precisely because it is not modelled.

There is also a possible double count with card 0030's new 5% maintenance rate, which covers
repairs, the inventory and the safety certificates. Where a reader's `runningCosts` figure already
includes repairs, those repairs are now charged twice. That errs on the cautious side, so it is a
defect rather than a hazard, but it means the two figures need settling together.

It came to be this way because `runningCosts` predates letting being modelled at all: it was built
for the buy-versus-rent comparison, where every home in the comparison is lived in.

## Links

**Relates to**
- `0030` - built the same fix for the service charge (the `while_owning_home` spend bucket) and left
  this sibling figure alone because it was outside that card's acceptance.

## Not this card
The service charge, the three letting-cost rates, and the letting caveats note. All three are
card 0030's and are built.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a property is let, THE APP SHALL not charge the household council tax on it, or SHALL state on the result why it still does. proves: `test_a_let_home_is_not_charged_the_tenants_council_tax`
- [x] WHEN a property is let, THE APP SHALL deduct its landlord-borne running costs from taxable rental profit. proves: `test_a_let_homes_running_costs_are_deducted_from_rental_profit`
- [x] WHEN a let property carries both a maintenance rate and a running-costs figure, THE APP SHALL charge repairs once, not twice. proves: `test_repairs_are_not_charged_by_both_the_rate_and_the_running_costs`
<!-- AC:END -->

## Tasks
- [ ] Decide how council tax is separated from the rest of `runningCosts`: a split field, or a
      let-only proportion. A split field is the honest one and costs a builder input.
- [ ] Route the landlord-borne remainder through the same deduction path card 0030 built for the
      service charge (`PathProjector::lettingCostsPerOwner`).
- [ ] Settle the overlap with `Property::DEFAULT_LETTING_MAINTENANCE_BPS` so repairs are charged once.
- [ ] Update the `letting_caveats` note, which currently tells the reader the council tax is still
      charged.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and note that stored let scenarios need re-running.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The charge is in
`packages/finance-engine/src/Forecast/PathProjector.php`, in the "Property running costs" block of
`projectYear` (search for `runningCosts` inside that method). The deduction path card 0030 built is
`lettingCostsPerOwner` in the same file, and
`packages/finance-engine/tests/Forecast/LettingCostsTest.php` is the fixture to extend. Run
`php artisan test --testsuite=Engine` after, then the full suite.

## Comments

**2026-10-04** RESULT: done
TESTS: +4 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/src/Dto/Property.php, packages/finance-engine/tests/Forecast/LettingCostsTest.php, app/Forecast/ResultPresenter.php, app/Forecast/ScenarioForecaster.php, resources/views/livewire/scenario-builder.blade.php, tests/Unit/Forecast/InputNotesTest.php, tests/Unit/Forecast/HouseholdAssemblerTest.php, docs/DECISIONS.md, docs/HANDOVER.md, docs/board/todo/0155-a-let-and-rent-plan-has-no-council-tax-of-its-own.md
OUT-OF-SCOPE: 0155

Rebuilt on master from superseded/0088-2026-10-04 (commit 3beba9d); the code it touched had not moved, so its three decisions carry over unchanged. Each proves: test was run first and watched fail for the criterion's reason: the running-costs deduction was 0 instead of 200000 pence, the repairs were charged twice (3800000 vs 3720000), and the letting note named no council tax figure and no reason.

1. Takes the criterion's second branch: the let home's council tax stays charged, and the letting_caveats note says why. Since card 0047 it is the only council tax a let plan pays (the home the household moves to has no figure), so dropping it would flatter letting. The card's 'council tax on two homes' premise does not hold on current code. The real fix is carded as 0155 (was 0153 on the old branch; that number is now taken).
2. PathProjector::runningCostsNominal() is the one home of the figure; lettingCostsPerOwner deducts it beside the service charge, but only where annualCouncilTax is held apart. Running costs with the council tax still inside are not deducted (adverse), and the note tells the reader to split it out. Extra test: test_running_costs_that_still_hold_the_council_tax_are_not_deducted.
3. Property::repairsInRunningCosts() zeroes the repairs rate for a let home with positive running costs, stated or default, and stops disclosing the default as applied.

Re-examined, not loosened: HouseholdAssemblerTest::test_the_letting_cost_rates_a_reader_enters_reach_the_property drifted because its fixture carries running costs, so the default repairs rate now yields; the test blanks runningCosts to keep checking what it was written for.

ENGINE_VERSION is finance-engine/let-home-running-costs-are-a-letting-expense; stored let plans with running costs owe a re-run. Reasons in DECISIONS 2026-10-04. Built in a worktree, so the reworded letting note and the builder hint under 'Repairs and checks' still need a browser check.
