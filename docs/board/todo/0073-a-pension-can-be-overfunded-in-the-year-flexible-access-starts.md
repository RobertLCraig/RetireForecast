# A pension can be overfunded in the year flexible access starts

## Why
Once somebody takes taxable money out of a money-purchase pension, the amount that may be paid back
in that year drops from £60,000 to £10,000. That is the Money Purchase Annual Allowance, and it
exists to stop a plan drawing a pot down in the free tax bands and paying the cash straight back in
for relief.

The forecast pays every contribution at the top of the year and takes every withdrawal after it, so
the trigger is always recorded too late to bind the year it happened in. A member who starts drawing
in April is credited a full £60,000 of allowance for the following twelve months. In life they would
have had £10,000 from the day they drew. The forecast shows a bigger pot than the rules allow, and
every plan that draws early while still being paid into is flattered by it.

Separately, the cap is modelled as a wall rather than a bill. Real contributions above the allowance
are allowed and taxed: the excess is charged at the member's marginal rate. The engine already
prices that in `AnnualAllowanceCalculator`, and the projector does not call it, so an overpayment
silently disappears instead of appearing as tax.

Neither is an oversight anybody argued for. The contribution routes were written before the MPAA
existed in the projector at all, and the cap was added on 2026-08-19 at the only place it could
reach without reordering the year.

## Not this card
The high-income taper and carry-forward of unused allowance from earlier years, both still absent
and both flagged in `PathProjector::contributionHeadroom`.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a member flexibly accesses a pension, THE APP SHALL cap their money-purchase contributions at the MPAA from that point in the same year, not from the year after. proves: `test_the_mpaa_binds_in_the_year_of_the_trigger`
- [x] #2 WHEN total pension input exceeds the allowance that applies, THE APP SHALL charge the excess as tax rather than refusing the contribution. proves: `test_a_contribution_above_the_allowance_is_charged_not_blocked`
- [x] #3 THE APP SHALL show the reader the allowance that applied and the charge, if any. proves: `test_the_allowance_and_any_charge_are_shown`
<!-- AC:END -->

## Tasks
- [x] Reorder the year loop, or re-run the contribution step, so the trigger binds the year it happens
- [x] Call `AnnualAllowanceCalculator` from the projector instead of dropping the excess
- [x] Surface the applied allowance and any charge on the results page

## Comments

**2026-09-08**
RESULT: done
TESTS: +3 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: app/Forecast/ResultPresenter.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: packages/finance-engine/tests/Forecast/PathProjectorTest.php
TOUCHED: packages/finance-engine/tests/Forecast/ContributionAllowanceTest.php
TOUCHED: tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

Both faults had one cause, so one change closes both. The allowance is now settled ONCE, in
`PathProjector::annualAllowanceCharges()`, after every contribution route and after every withdrawal
that can set the MPAA trigger. That is the reorder the first Task asked for, without moving anything
in the year loop: nothing needs to be paid later, because the answer is no longer needed until the
year ends. `payIntoPot` refuses nothing at all now, so the three routes cannot get three different
answers in the trigger year. What still bounds a contribution at source is the member's pay, the
household's surplus, and the basic amount on the non-earner relief route, each applied by its own
caller. `AnnualAllowanceCalculator` is called for the excess, so which of the two allowances applies
keeps one home; carry-forward and the taper are passed as nil, both being outside this card. The
charge is `marginalTax` on the excess, the same income-tax pass the rest of the year uses. It comes
out of the member's cash, and what their cash cannot meet comes off the year's net income so it
lands in unmet spend rather than being forgiven.

Watched fail: all three tests red before any code, with the charge missing entirely. To see #1 fail
on the TIMING alone rather than on the missing charge, the settlement was first wired to the trigger
state as it stood at the year's OPEN. With that, #2 and #3 went green and #1 stayed red for exactly
the reason the criterion names: the trigger year was measured against the GBP 60,000 allowance.
Moving one argument to the year's closing state turned it green.

Six tests legitimately drifted and were rewritten, none weakened. Three pinned the hard cap as
behaviour, which this card removes by decision: `test_a_contribution_above_the_annual_allowance_is_capped_at_it`
(now the criterion-2 test), `test_the_employers_contribution_counts_against_the_same_allowance` (now
reads the charge instead of the refused pot) and `test_what_the_allowance_blocks_stays_with_the_household_rather_than_vanishing`
(now `test_the_charge_is_the_whole_cost_of_paying_above_the_allowance`). One was written specifically
to pin the artefact this card removes, `test_the_mpaa_caps_a_surplus_funded_contribution_in_the_trigger_year_itself`,
and its subject no longer exists, so it is folded into the criterion-1 test. Two more were rewritten
because the pot alone no longer distinguishes the two allowances now that nothing is refused:
`test_an_employer_contribution_the_mpaa_blocks_is_not_paid_anywhere_else` became
`test_an_employer_contribution_over_the_mpaa_is_paid_in_and_charged`, and
`test_drawing_an_inherited_pot_does_not_cap_the_heirs_own_contributions` gained a per-year assertion
that no charge arises, or it would have passed whatever the rule did.

Assumed, both flagged in the docblock rather than hidden. The charge is priced on the year's income
BEFORE any ad-hoc draw taken to fund a shortfall, so a member pushed into a higher band by that draw
is charged at the band they were in without it; pricing it earlier is the only alternative and it
cannot see the MPAA trigger the draw itself sets. And the two allowances stay the frozen statutory
figures, unindexed, which is the position before this card.

`ENGINE_VERSION` is `finance-engine/annual-allowance-is-a-bill-not-a-wall`, so the stored-scenario
re-run is owed for any plan paying into a money-purchase pension. `MonteCarlo\GoldenMasterTest` did
not redden and needs no re-pin: its fixture pays nothing in. Built in a worktree, so the new results
note has not been seen in a browser.

One thing this session could not settle from the repository: the orient hook asks for a stale block
to be folded out of `docs/HANDOVER.md` before other work, and that is a doc decision no acceptance
criterion here covers, so the file grew by one bullet instead.

### 2026-09-08 review (v20260908154332-314d)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 272s, run by this job rather than reported by the card.

**acceptance: sound**

All three criteria trace to real code.

**#1 ÔÇö MPAA binds in the trigger year.** `PathProjector::applicableAllowance()` reads `$state['mpaaTriggered']`, and `PathProjector::annualAllowanceCharges()` is called at the end of the year loop, after the contribution routes and after `triggerFlexibleAccess()`. The test in `PathProjectorTest::test_the_mpaa_binds_in_the_year_of_the_trigger` reads the allowance named in the year-2 warning, and asserts the untriggered twin is silent, so it fails if the answer slips a year.

**#2 ÔÇö charged, not blocked.** `PathProjector::annualAllowanceCharges()` calls `AnnualAllowanceCalculator::assess` and turns the excess into `marginalTax`, then the year loop takes it from the member's cash and pushes the rest onto net income (so it shows as unmet spend, not forgiven). `ContributionAllowanceTest::test_a_contribution_above_the_allowance_is_charged_not_blocked` asserts the whole ┬ú80k reaches the pot and the tax equals the at-cap household's.

**#3 ÔÇö shown.** `PathProjector::allowanceChargeWarnings()` states the applied allowance (read from the config constant, not restated) and the charge, and is added to the year's warnings. `AssumedFiguresDisclosureTest::test_the_allowance_and_any_charge_are_shown` reads it through the app's disclosure path and rejects a ┬ú0.00 charge.

I tried to break each one and could not.

VERDICT: sound

**scope: defect**

**Scope over the fence:** nothing. CarryÔÇæforward and the taper stay unmodelled and are still flagged in `PathProjector::applicableAllowance`. `ENGINE_VERSION` bump, the reworded `PathProjector::mpaaWarnings`, and the new note in `ResultPresenter::assumedFigures` all serve AC3. No unrelated file rides along in the commit.

**Left half done ÔÇö one thing.** Criterion 1 asks for the MPAA "from that point in the same year". `PathProjector::annualAllowanceCharges` does not do that. It reads one flag, `$state['mpaaTriggered']`, at year end, and measures the WHOLE year's input against ┬ú10,000 ÔÇö including money paid in before the trigger. In life preÔÇætrigger moneyÔÇæpurchase input is tested against the ordinary allowance, and only postÔÇætrigger input against the MPAA. So a member who pays ┬ú30,000 in April and first draws in March is charged on ┬ú20,000 of excess that in life carries none.

That is the adverse direction, so it is a defensible simplification ÔÇö but it is undeclared. `PathProjector::applicableAllowance` says the answer "turns on the trigger DATE", which it does not, and the class flags its other two simplifications by name while this one is not flagged anywhere the reader or the next session can see.

VERDICT: defect

**breakage: defect**

Findings (breakage lens):

**1. Five dead docblock links in `packages/finance-engine/src/Forecast/PathProjector.php`.** The method `contributionHeadroom` no longer exists; it was split into `applicableAllowance` + `annualAllowanceCharges`. `{@see contributionHeadroom}` still stands in the state builder (`initialState`, beside `'mpaaTriggered'`), in the inherited-pot block of the death/inheritance step, in `triggerFlexibleAccess`'s neighbours in the two draw closures (`$drawPension`, `$drawPensionUfpls`), and in `applicableAllowance` itself. This is exactly the `mpaaHeadroom` dead-link defect card 0007 already caught once.

**2. The rule is asserted the old way in the docs.** `docs/spec/METHODOLOGY.md`, pension-contributions bullet: "We model those as a limit on what can go in rather than as a tax charge on the excess." That is now false and it is reader-facing. `docs/DECISIONS.md` decisions 2 and 3 still state the hard cap as current behaviour ("An EMPLOYER contribution the cap blocks is not paid anywhere else", "Modelled as a hard cap ... both are flagged on `contributionHeadroom`"), naming card 0073 only as future work. One rule, three homes, two of them wrong.

No incorrect arithmetic found; the charge path itself holds.

VERDICT: defect

