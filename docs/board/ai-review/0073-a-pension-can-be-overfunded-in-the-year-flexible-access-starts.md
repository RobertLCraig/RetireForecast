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
