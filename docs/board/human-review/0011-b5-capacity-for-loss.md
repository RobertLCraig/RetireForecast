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
