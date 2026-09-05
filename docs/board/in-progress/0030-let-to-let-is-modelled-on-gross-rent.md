# Letting a property is modelled on gross rent with no costs

## Why
From the expert panel, 2026-08-19 (property finding 5). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

A let-to-let scenario takes rent in gross. There is no agent fee, no void, no repairs, no
compliance and no licensing. The caveat exists only in a docblock, not on the result.

Realistic deductions on a fully managed single let are roughly 12% management including VAT, 8%
void, and about 5% for repairs, inventory, gas safety and electrical checks - around a quarter of
gross rent before tax.

The error also runs the other way: the service charge on a let flat is a deductible letting
expense, and the model treats it as household spend while taxing the full gross rent as profit.
The property reviewer's arithmetic turns a modelled positive contribution into a real cash loss.

Card 0021 is chasing a buy-to-let interest rate. The rate is not what is wrong with the plan.

Two further items that are real and unmodelled: minimum energy efficiency standards, where a
proposed EPC C requirement by 2030 puts a five-figure retrofit or an exemption application in
scope; and the lease itself, which usually requires freeholder consent to sublet and may prohibit
it outright.

## Not this card
Section 24 finance-cost relief, which the projector already models correctly.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a property is let, THE APP SHALL deduct management, void and maintenance costs from gross rent, each a disclosed sourced default and each editable. proves: `test_letting_costs_come_off_the_gross_rent`, `test_the_readers_own_letting_rates_win_over_the_defaults`, `test_the_assumed_letting_costs_are_disclosed_with_their_values`, `test_the_letting_cost_rates_a_reader_enters_reach_the_property`
- [x] #2 WHEN a let property carries a service charge, THE APP SHALL treat it as a letting expense rather than household spend. proves: `test_a_let_homes_service_charge_is_a_letting_expense_not_taxed_as_profit`
- [x] #3 WHEN a let plan is displayed, THE APP SHALL show the letting caveats on the result, not only in code comments. proves: `test_a_let_plan_shows_the_letting_caveats_on_the_result`
<!-- AC:END -->

## Tasks
- [x] Add management, void and maintenance rates to the let-property inputs
- [x] Reclassify a let property's service charge as a letting expense
- [x] Surface the caveats through `ResultPresenter`
- [ ] Re-run the let scenarios, then `php artisan scenarios:audit`

## Comments

**2026-09-05** RESULT: done
TESTS: +14 new, all green
TOUCHED:
  packages/finance-engine/src/Dto/Property.php
  packages/finance-engine/src/Forecast/PathProjector.php
  packages/finance-engine/tests/Forecast/LettingCostsTest.php (new)
  packages/finance-engine/tests/Forecast/BuyToLetFinanceCostTest.php
  app/Forecast/HouseholdAssembler.php
  app/Forecast/ResultPresenter.php
  app/Forecast/ScenarioForecaster.php
  app/Forecast/QuickWhatIf.php
  app/Livewire/ScenarioBuilder.php
  resources/views/livewire/scenario-builder.blade.php
  tests/Support/BuilderStateFixture.php
  tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
  tests/Unit/Forecast/InputNotesTest.php
  tests/Unit/Forecast/HouseholdAssemblerTest.php
  docs/spec/ASSUMPTIONS.md
  docs/DECISIONS.md
  docs/HANDOVER.md
  docs/board/todo/0087-source-the-letting-cost-rates.md (new)
  docs/board/todo/0088-a-let-homes-running-costs-are-charged-as-household-spend.md (new)
OUT-OF-SCOPE: 0087, 0088

Three rates on `Property` (`lettingManagementRate` / `lettingVoidRate` /
`lettingMaintenanceRate`), each nullable, each applying only when `isLet`, each taking a public
constant as its default (12% / 8% / 5%) when blank and the reader's own figure otherwise, an
explicit zero included. `PathProjector::lettingCostsPerOwner()` takes them off each owner's gross
rent in the income pass, so the cash the household banks and the profit it is taxed on are the same
arithmetic and cannot disagree. The Section 24 reducer base is now that profit.

**On #2, and read this before accepting it.** The service charge is deducted from taxable rental
profit and is STILL charged as household spend. That is deliberate: they really do pay the bill, so
the cash outflow is unchanged, and the defect the card describes is the tax one ("taxing the full
gross rent as profit"). Moving it out of the essential floor as well would have lowered the
"essentials met" bar, which flatters. If the intent was that it should leave the spend line
entirely, this criterion is not met as you meant it and the fix is small.

**Assumed.** That the deduction should mirror the spend charge exactly, survivor factor included:
`propertyCostsNominal()` recomputes the same escalation the spend path applies a few dozen lines
below rather than sharing one call site, because refactoring the spend path to hoist it would change
the rounding on every stored scenario for no gain. Both read the same two figures off
`ExpenseProfile`, so a re-sourced bucket or rate moves them together; the formula is duplicated.

**Could not settle from the repository.** (1) The 12/8/5 have no primary source. An unattended
session has no web access, so they are shipped as the reviewer's judgement, flagged as the third
sourcing gap in ASSUMPTIONS.md §14 and carded as **0087**. (2) `isLet` had NO builder control before
this: it was reachable only through the "Let out & rent elsewhere" quick what-if. The card's own task
says "add the rates to the let-property inputs", and there were none, so a checkbox was added
alongside them. Say if that should have been its own card. (3) A rental LOSS is floored at nil profit
rather than carried forward, which is what the law allows; carry-forward is unmodelled and the
deduction is capped per owner so it can never shelter a pension.

**Not done.** The stored-scenario re-run and `php artisan scenarios:audit`. This is a worktree, and
the live database is Rob's; the handover already records that re-run as owed from cards 0024, 0025,
0028 and 0029, and this bump joins it. No browser check either, since Herd serves the main checkout
and not this tree: the new builder block and the two result notes have never been looked at.

**Raised, not fixed.** Card **0088**: a let home's `runningCosts` are still charged as household
spend including the council tax a tenant normally pays, are not deducted from rental profit, and may
now double-count repairs against the new 5% maintenance rate. All three are one figure's problem and
sit outside this card's acceptance. The council-tax half is stated on the result in the meantime.

**One existing test was updated rather than left green.**
`BuyToLetFinanceCostTest::landlord()` now sets all three rates to an explicit zero. Two of its three
tests went red on the deduction, which is them working. The third stayed green for the wrong reason:
at a basic-rate marginal rate the tax saved by deducting an expense happens to equal the reducer on
the same amount, so a reducer read off the wrong base still landed on the expected number. Pinning
the rates to zero keeps those tests about the reducer alone.
