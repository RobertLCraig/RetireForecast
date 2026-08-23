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
- [ ] #1 WHEN a member flexibly accesses a pension, THE APP SHALL cap their money-purchase contributions at the MPAA from that point in the same year, not from the year after. proves: `test_the_mpaa_binds_in_the_year_of_the_trigger`
- [ ] #2 WHEN total pension input exceeds the allowance that applies, THE APP SHALL charge the excess as tax rather than refusing the contribution. proves: `test_a_contribution_above_the_allowance_is_charged_not_blocked`
- [ ] #3 THE APP SHALL show the reader the allowance that applied and the charge, if any. proves: `test_the_allowance_and_any_charge_are_shown`
<!-- AC:END -->

## Tasks
- [ ] Reorder the year loop, or re-run the contribution step, so the trigger binds the year it happens
- [ ] Call `AnnualAllowanceCalculator` from the projector instead of dropping the excess
- [ ] Surface the applied allowance and any charge on the results page
