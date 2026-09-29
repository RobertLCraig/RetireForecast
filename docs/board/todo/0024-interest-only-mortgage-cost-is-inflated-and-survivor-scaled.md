# An interest-only mortgage payment rises with inflation and shrinks when someone dies

## Why
From the expert panel, 2026-08-19 (adviser finding 2). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

For any mortgage that is not a `RepaymentMortgageTerms` schedule - interest-only, RIO,
buy-to-let, an interest-serviced lifetime mortgage - the payment sits in
`ExpenseProfile::$mortgageCosts` inside essential spend. `PathProjector` then multiplies it by
`spendFactor` (cumulative CPI) and by the survivor factor.

Both are wrong. Interest on a fixed balance at a fixed rate is fixed in cash terms, so
CPI-indexing it holds it flat in real terms when its real cost should fall. And a lender does
not reduce the payment when a borrower dies.

The repayment instalment was fixed for exactly this in July 2026 - the comment at `PathProjector`
line 1068 states the correct treatment, and rent is correctly added after the survivor multiply -
but the fix was never carried to the other three product shapes.

This is not a neutral error. It removes the inflation hedge on a nominal debt, so every borrowing
route is penalised against selling. That is the choice card 0022 is waiting on.

## Links

**Relates to**
- `0022` - this fault penalises the interest-only route against selling, which is the exact choice
  that card is waiting on.

## Not this card
The repayment amortisation schedule, which is already correct and pinned to a lender illustration.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a mortgage payment is charged from `ExpenseProfile::$mortgageCosts`, THE APP SHALL hold it fixed in nominal terms rather than escalating it with CPI.
- [x] #2 WHEN a household member dies, THE APP SHALL charge the survivor the full mortgage payment, unreduced by the survivor factor.
- [x] #3 WHEN a let property's finance cost is computed for the Section 24 credit, THE APP SHALL use nominal interest rather than a CPI-inflated figure.
- [ ] #4 WHEN a `while_mortgaged` line is not the mortgage payment itself (a mortgage life or protection premium, a fee) or is discretionary, THE APP SHALL keep it indexed to CPI and in its own spend tier, so the essential floor never loses real essential spend to it, and the flat-payment treatment SHALL be stated on screen for every mortgage shape.
<!-- AC:END -->

## Tasks
- [x] Move `mortgageCosts` out of the CPI and survivor multiply in `PathProjector`
- [x] Fix `financeCost` (around line 924) the same way
- [x] Test: a fixed-rate interest-only payment is the same pence in year 1 and year 20
- [x] Test: the payment does not change at the first death
- [ ] Re-run every stored scenario, then `php artisan scenarios:audit`

## Comments

**2026-09-05**
RESULT: done
TESTS: +3 new, all green
TOUCHED:
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/ContingentCostsTest.php
packages/finance-engine/tests/Forecast/BuyToLetFinanceCostTest.php
app/Forecast/ScenarioForecaster.php
docs/spec/METHODOLOGY.md
docs/board/todo/0082-section-24-credit-outlives-the-mortgage-it-relieves.md
docs/HANDOVER.md
OUT-OF-SCOPE: 0082

The "Mortgage" expense line now comes out of the CPI-and-survivor multiply **always**, and is added
back after it as a fixed nominal cost while the debt is still owed. That merges the two payment
paths: the amortisation schedule and the expense line are now charged through one block, differing
only in where the figure comes from, so the treatment the repayment instalment got in July 2026
cannot be got right for one shape and wrong for the other three again. `$financeCost` behind the
Section 24 credit is nominal interest for the same reason.

Each test was watched failing first, for the fault its criterion names: #1 charged £15,171.34 in
2046 against £12,000 in 2026; #2 charged £8,400 to the survivor against £12,000 before the death;
#3 gave a credit of £3,927.14 against the correct £3,234.19. #2 runs on a flat (0% CPI) economy so
the survivor factor is the only thing that can move the charge across the death, rather than the
two faults masking one another.

Two existing tests moved with the fix, both legitimately: `ContingentCostsTest`'s
repay-from-capital and refinance tests compare REAL spend two years apart, which is now the right
answer minus two years of the payment's lost real value. Both now run flat, where real == nominal
and the £12,000 they assert is exact; that also makes them assert what they are about (does the
payment STOP) rather than how it is indexed.

`ScenarioForecaster::ENGINE_VERSION` is bumped to `finance-engine/nominal-mortgage-payment`: every
borrowing plan's spend was overstated under the old stamp, so its wealth, depletion year and
success odds were too pessimistic against selling. Stored runs are not comparable across the bump.

Not done: the stored-scenario re-run. It writes to the shared live Postgres database that Rob's own
tree serves, from a worktree whose code is not merged yet, so it belongs after the merge and not
here. `php artisan scenarios:audit` was run read-only and exits 1 on exactly the 120 pre-existing
"run N carries no integrity stamp" lines HANDOVER already records, and on nothing else: this change
introduced no new problem class.

Nothing here has been seen in a browser. Herd serves the app from `C:\Dev\RetireForecast`, not from
this worktree, so the results, compare and PDF surfaces that read `spendTarget` still need Rob's
sign-off (card 0001).

### 2026-09-05 review (v20260905043040-ad18)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 235s, run by this job rather than reported by the card.

**acceptance: sound**

Checked each criterion against real code.

**AC #1 ÔÇö fixed nominal.** `PathProjector::projectYear` reads `$mortgagePay` from `ExpenseProfile::mortgageCosts()`, subtracts it from `$targetPence` and `$essentialPence` before the `* $state['spendFactor']` multiply, then adds it back after. So CPI never touches it. `ContingentCostsTest::test_an_interest_only_mortgage_payment_is_fixed_nominal_and_does_not_rise_with_cpi` charges ┬ú12,000 in 2026 and ┬ú12,000 in 2046 at 3% CPI.

**AC #2 ÔÇö no survivor cut.** The same add-back sits after `* $survivor`, so the survivor pays the whole payment. `ContingentCostsTest::test_the_mortgage_payment_does_not_shrink_when_one_of_the_couple_dies` runs flat and proves the food bill falls at the death while the payment does not.

**AC #3 ÔÇö nominal finance cost.** `PathProjector::projectYear` sets `$financeCost` from `mortgageCosts()->pence` with no `spendFactor`. `BuyToLetFinanceCostTest::test_the_relievable_finance_cost_is_nominal_interest_not_a_cpi_inflated_figure` holds the credit at 20% ├ù ┬ú16,170.96 in 2036.

I tried to break the new `! $state['mortgageRepaid'] && ! $state['homeSold']` gate. Both flags start false in `PathProjector` state init, and only a forced sale sets them, so a buy variant's new mortgage from `ExpenseProfile::withMortgageCosts` is still charged.

VERDICT: sound

**scope: defect**

Reviewed the diff at `3f48f5a` against the card.

**1. The fix grew from "the mortgage payment" to "every `while_mortgaged` line."**
`HouseholdAssembler::autoCondition` puts any label containing the word "mortgage" into that bucket ÔÇö "Mortgage life insurance", "Mortgage protection", a broker fee. `HouseholdAssembler::assemble` sums them all into `ExpenseProfile::$mortgageCosts`, and `PathProjector::projectYear` now takes that whole sum out of the CPI and survivor multiply and adds it back flat. An insurance premium is not interest on a fixed balance. It does rise with prices. It was indexed correctly before and is frozen now. The card asked for the payment, not the bucket.

**2. A user-facing meaning changed and its own text did not.**
`ScenarioBuilder::conditionHints` still describes "Only while the mortgage runs" by when it *stops*. It does not say the line no longer rises with inflation and no longer shrinks at a death. That is an invisible figure by this project's own rule.

**3. Left undone (declared).** Task 5, the stored-scenario re-run plus `scenarios:audit`, is unticked and recorded in `docs/HANDOVER.md`.

The "Not this card" fence holds: the schedule path was restructured but behaves the same.

VERDICT: defect

**breakage: defect**

**1. `PathProjector::projectYear` now assumes every `while_mortgaged` pound sits in essential spend.** The line `essentialPence = max(0, essentialPence ÔêÆ mortgagePay)` used to run only for a redeemed or amortising mortgage; it now runs for every household, every year. But `HouseholdAssembler::expenseProfile` sums `mortgageCosts` by *condition* alone, and `ScenarioBuilder::rules` lets a **discretionary** line carry `while_mortgaged` ÔÇö or auto-classify there on the word "mortgage" (a voluntary overpayment line, DATA-MODEL 2026-07-19). Real essential spend is then stripped, and once the clamp bites, deleted: essential ┬ú10,000 plus a discretionary ┬ú12,000 mortgage line reports an essential floor of ┬ú12,000 with ┬ú10,000 of food and heat gone. `ResultPresenter::incomeFloor`, the safety-buffer months and `successProbabilityEssentials` all read that figure. `ExpenseProfile`'s docblock asserts the subset is essential; nothing enforces it, and no test builds it.

**2. The new treatment is invisible to the reader.** `ResultPresenter::inputNotes` states "fixed in cash termsÔÇª does not fall if one of you dies" only in the `repayment_mortgage` note, gated on `repaymentTerms`. METHODOLOGY.md now claims it for every shape, so an interest-only payment is held flat with nothing on screen saying so.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #4 because the scope and breakage findings still hold on master. `PathProjector::projectYear` subtracts the whole `mortgageCosts()` bucket from `$essentialPence` for every household, and `HouseholdAssembler` fills that bucket by condition alone, so a discretionary line or an insurance premium labelled "mortgage" is frozen in cash and strips essential spend (essential GBP 10,000 plus a discretionary GBP 12,000 mortgage line reports a GBP 12,000 floor). `ResultPresenter::inputNotes` still says "fixed in cash terms" only for a repayment mortgage.

**2026-09-28** The loop moved this card from todo/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 4 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** Attended unblock pass: no session has taken this card since #4 was reopened, so the "2 takes" that parked it were from before that. The reflog shows the manager pass commit `human-review to todo` (65e8412) and then `todo to human-review, no progress in 2 takes` (88a0cc6) 17 minutes later, with no `todo to in-progress` between them. #4 is still owed on master: `PathProjector::projectYear` still takes the whole `$household->expenseProfile->mortgageCosts()` bucket off both `$targetPence` and `$essentialPence` for every household, and `ResultPresenter::inputNotes` still states the fixed-payment treatment only for a repayment mortgage. Moved to `todo/` so a session actually builds #4.

**2026-09-29** The loop moved this card from todo/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 4 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** Returned to todo/ by an attended session. The park above was not this card's: `Get-NoProgressCounts` in ProgressBoard's `bin/work-card.ps1` read another board's holds for the same card number out of the shared log, so it parked this card with no take of its own since it was sent back. Fixed in ProgressBoard `6d49915`, which reads each log line's board from its run id.
