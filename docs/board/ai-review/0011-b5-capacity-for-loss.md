# B5: capacity for loss

## Why
Next after B1 in the adviser-parity plan. Mostly framing over stress machinery that already
exists: how far can wealth fall before the essential floor breaks?

## Not this card
A3 ISA rules, A4 salary sacrifice, B3 estate checklist, B4 annual review.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL state how far wealth can fall before the essential spending floor is
      breached, for a given scenario.
- [x] #2 THE APP SHALL express that as a percentage fall and a cash figure, both net of the
      mortgage, consistent with the project's one definition of total wealth.
<!-- AC:END -->

## Tasks
- [x] Derive the breach point from the existing stress machinery
- [x] Surface it on the results page
- [x] PDF twin

## Direction
**2026-08-22** Built as `WealthFallLever` (engine) plus `App\DecisionSupport\CapacityForLoss`
(app), surfaced as a "How much could you afford to lose?" panel on the results page and its PDF
twin, pinned to the housing strategy on display like the protection panel.

What it does: marks every part of wealth down by the same fraction on the base date, then binary
searches integer percentages for the largest fall at which `essentialsAlwaysMet` still holds. The
search is over whole percents on purpose, so the figure reported is one the projection was actually
run at and passed, and the cash figure is derived from that same integer against
`WealthFallLever::baseWealth` (liquid + money-purchase pots + home equity net of the mortgage,
NNEG-floored), so the two figures cannot disagree.

Modelling calls I made, none of which the repository settled:
- **The fall is across the board, not investments only.** AC #2 pins the denominator to total
  wealth including the home, so a fall that only touched investable assets would report a
  percentage of a total it could never reach. Marking everything down together is also the more
  adverse reading, per the standing "adverse default, user-editable" preference. It is explicitly
  not a market model, and the panel says so.
- **The fall hits home EQUITY, not gross value**, so the mortgage stays put and a geared household
  correctly shows less capacity. A home already worth less than its loan is left alone (NNEG floor).
- **DB and State pensions are untouched** (income, not a pot). An unrealised GIA gain falls with the
  balance and is floored at zero, because the engine carries no loss forward.
- **The bar is the essential floor only**, not "the money lasts": that is the card's wording, and it
  is the bar that makes the answer sensitive to the lever.

One change came out of a smell-check against the real stored scenarios rather than the synthetic
fixtures: the first cut returned null when a plan already fails its essentials, which hid the panel
on twelve of the twenty-five stored scenarios. That is the most important thing the panel can say,
so it now reports `alreadyBreached` as its own state with its own copy. `scenarios:audit` is clean.

Not settled here, for whoever reviews: the reported capacity assumes the household carries on
spending exactly as entered after the loss. A household that cut back would have more room, and
nothing on the panel prices that; the "How far can we go?" explorer is the nearest existing answer.

Two housekeeping notes. `ScenarioForecaster::variantInputs()` is new: the "resolve the plan on
display before stressing it" logic existed privately in `ProtectionGap` and inline in
`SustainableSpend`, and this needed a third copy, so it now lives once on the forecaster and
`ProtectionGap` reads it. `SustainableSpend` still has its own single-variant version, left alone
as out of scope. And the session brief asked for `.\vendor\bin\pest.bat`, which this project does
not have: it runs PHPUnit, so the suite was run with `php artisan test` (all green) and
`.\vendor\bin\pint.bat --dirty` for style.

**This has not been looked at in a browser.** The worktree is not what Herd serves, so both new
panels still need Rob's eyes on the real page, and that check belongs with card 0001.

### 2026-08-29 review (v20260829152014-2345)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 306s, run by this job rather than reported by the card.

**acceptance: defect**

**AC #1 ÔÇö traced.** `App\DecisionSupport\CapacityForLoss::forScenario` searches integer falls with `WealthFallLever::apply` and `essentialsAlwaysMet`. Shown in `resources/views/livewire/scenario-results.blade.php` (`sec-capacity`) and `resources/views/pdf/partials/report.blade.php`.

**AC #2 ÔÇö traced.** `WealthFallLever::baseWealth` sums accounts + DC pots + home equity net of the mortgage, matching the legs `YearResult::__construct` uses for `totalWealth`. `CapacityForLoss::result` derives cash from that same percent and wealth.

**Defect: the search assumes something the engine contradicts.**

`WealthFallLever`'s docblock claims "losing more can only make the floor harder to meet". `PathProjector::meansTestedBenefitNominal` breaks that: `CapitalAssessment::assess` deems ┬ú1/week per ┬ú500 of capital over ┬ú10,000 ÔÇö 10.4% a year, above any real growth. So less capital can mean more Pension Credit and a floor that holds.

Failure: single pensioner, State Pension ┬ú11,000, essentials ┬ú11,500, ISA ┬ú30,000, 40-year horizon. At 0% fall the capital covers the ┬ú500 gap. At 100% Pension Credit lifts income above the floor. So `forScenario` returns early at `holds(100)` and both panels say **no fall would breach the floor** ÔÇö but a 40% fall (┬ú18,000 left: tariff still kills the credit, capital runs out at year 36) does breach it.

VERDICT: defect

**scope: defect**

Two scope leftovers.

**1. An unasked refactor that stopped halfway.** The card's tasks are derive, surface, PDF twin. It also extracted `ScenarioForecaster::variantInputs()` and moved `ProtectionGap::forScenario()` onto it. But `AdviceCostComparison::variantInputs()` is the same logic again, and `SustainableSpend::forScenario()` inlines it a third time. So the extraction did not make the one home it claims ÔÇö there are now four copies where the card says there were three. The Direction note admits only `SustainableSpend` and never names `AdviceCostComparison::variantInputs()`, whose own doc-comment says it is "the same resolution `ProtectionGap` and `SustainableSpend` use". Migrate both, or drop the refactor.

**2. The plan of record still says B5 is unbuilt.** `docs/build/PLAN-adviser-parity.md` marks it "**B5 ÔÇö build the objective half**" in the parity table, item 5 of the build order, and in the status banner. Siblings A1, A2, B1, B2 and A3 all carry "Ô£à BUILT" there. `docs/build/PLAN.md` still says of the capacity-for-loss panel "Do not build these." `docs/HANDOVER.md` "Current state" now lists capacity for loss as both done and outstanding.

Nothing crossed the "Not this card" fence.

VERDICT: defect

**breakage: defect**

I traced the lever, the search, both panels, the callers of the new `variantInputs`, and the tests.

**Finding ÔÇö the two-point proof is not safe, because the engine is not monotone in wealth.**

`CapacityForLoss::forScenario` reports `survivesTotalLoss` (percent 100) from just two probes: `holds(0)` and `holds(100)`. That only works if the floor gets harder to meet as wealth falls.

The engine breaks that rule. `CapitalAssessment::tariffIncomeWeekly` deems ┬ú1/week of income per ┬ú500 of capital above the disregard, **with no upper limit**, and `PensionCreditCalculator::award` subtracts it from Guarantee Credit. `PathProjector::pensionCreditFor` (the `award` call site) uses it live. So capital in that band is charged about 10% a year while a total loss unlocks the full award. A household whose applicable amount is above its assessable income (severe-disability addition, or part State Pensions) can pass at 0%, pass at 100%, and **fail in the middle**.

That makes three things wrong:
- Panel copy "there is no fall ... that would breach that floor" in `scenario-results.blade.php` and `report.blade.php` (the `survivesTotalLoss` branch).
- The docblock claims of monotonicity on `WealthFallLever` (class) and `CapacityForLoss` (class).
- `CapacityForLossTest` builds no Pension-Credit household, so the tariff never bites in any test.

`alreadyBreached` short-circuits the same way.

VERDICT: defect


## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 2 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 2 of 2 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #1 because the figure it states can be wrong. `CapacityForLoss::forScenario` still decides from two probes, `holds(0)` and `holds(100)`, and the engine is not monotone in wealth: `CapitalAssessment` tariff income cuts Pension Credit on capital, so a Pension Credit household can pass at 0%, pass at 100% and fail in between, and the panel then says no fall would breach the floor. Build a Pension Credit test household that fails in the middle, and make the search find it. The scope review's leftovers (`AdviceCostComparison::variantInputs()` is a fourth copy of the variant resolution; PLAN-adviser-parity.md still says B5 is unbuilt) belong on the same pass.

**2026-10-04** RESULT: done
TESTS: +1 new, all green (1 changed: WealthFallLeverTest direction assertion, renamed to test_the_lever_declares_itself_not_monotone_and_clamps)
TOUCHED: app/DecisionSupport/CapacityForLoss.php
app/DecisionSupport/AdviceCostComparison.php
app/DecisionSupport/SustainableSpend.php
packages/finance-engine/src/Sweep/Lever/WealthFallLever.php
packages/finance-engine/tests/Sweep/WealthFallLeverTest.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/DecisionSupport/CapacityForLossTest.php
docs/build/PLAN-adviser-parity.md
docs/build/PLAN.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

AC #1, the reopened defect. New test test_a_pension_credit_household_that_fails_in_the_middle_reports_the_first_breach builds a single pensioner renting on Pension Credit (State Pension 200/wk, essentials 12,000, rent 30,000 flat real, ISA 900,000). It asserts as preconditions that the floor holds at 0% and at 100%, then that the result is not survivesTotalLoss, that every whole percent up to the reported one holds, and that one more fails. Watched it fail on the old code for the right reason: the preconditions passed and the result said survivesTotalLoss. The reviewer's own example (State Pension 11,000, essentials 11,500, ISA 30,000) does NOT fail in the middle in this engine; I probed it and a grid of owner households at 1% steps and found no non-monotone owner. Once capital drops under the disregard the full guarantee comes back, so tariff income alone cannot break the floor. What breaks it is the Housing Benefit capital cliff behind it: tariff income removes the credit, that ends the passport, capital over the upper limit ends Housing Benefit, and a year's rent is more than the capital left. So the test household is a renter. Its failing falls are jagged (66, 75, 82, 87, 92 at the time of writing), so the test pins properties, not a number.

Fix: CapacityForLoss walks up from 1% and stops at the first fall that breaks the floor, instead of bisecting between two probes. The cost is up to 101 deterministic forecasts instead of about 8. I measured about 3 to 5 ms each on the test household, so about 0.5 s at worst. The real couple's household is heavier and I did not time it (marked with a ponytail: comment). WealthFallLever::direction() is now LeverDirection::Unknown, not Decreasing, because the enum's own contract says Decreasing means provably monotone; nothing in the app sweeps this lever except CapacityForLoss. The docblocks no longer claim monotonicity. The panel and PDF copy said 'Lose more and it is not', which is false when a bigger fall holds again. It now says 'at every whole percent up to that. Lose one point more and it is not.'

AC #2 is unchanged and its existing tests still pass: cash is still derived from the reported percent against WealthFallLever::baseWealth.

Scope leftovers named in the 2026-09-28 comment: AdviceCostComparison's private variantInputs() is deleted and SustainableSpend's inline copy is replaced; both now call ScenarioForecaster::variantInputs (same household, settings, assumptions and housing action, memoised). One behaviour difference: SustainableSpend used to index the variant directly with no fallback, and now falls back to stay_put like the others. PLAN-adviser-parity.md marks B5 built in the banner, the table, the section heading and the build order. PLAN.md's 'Do not build these' now notes that capacity for loss came back as B5. HANDOVER Current state no longer lists B5 as outstanding.

Not settled here: the panel's integer resolution means a failure between two whole percents is not searched; the copy already says the figure is rounded to whole percents. Still needs a browser check: Herd serves C:\Dev\RetireForecast, not this worktree, so neither the panel nor the PDF copy change has been seen (belongs with card 0001). php artisan scenarios:audit was not run, because it reads the shared Postgres database.
