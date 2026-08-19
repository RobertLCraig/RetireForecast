# The care means test can charge a bill the model has no way to pay

## Why
From the expert panel, 2026-08-19 (estate planner finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**The property disregard is too narrow.** The home is disregarded only where one person remains.
The statutory disregard is **mandatory** where the property is occupied by the resident's spouse or
civil partner, a **relative aged 60 or over**, an incapacitated relative, or a child under 18, and
discretionary for a carer who gave up their own home. Any household with a resident older relative
gets the wrong answer.

**A charge with no funding route becomes a false plan failure.** The twelve-week disregard and
deferred payment agreements are dismissed in a comment as the same outcome. They are not.
`fundShortfall()` draws from cash, general investments, ISAs and pensions - **never the home**. So
an assessable home produces a care charge the projector cannot fund, the year is marked as failing
essentials, and the plan is penalised for keeping a property.

Under a deferred payment agreement the fee becomes a debt secured on the home, accruing interest,
and is deductible from the estate at death. That is a completely different estate and solvency
outcome from a forced sale. Modelling it turns a false failure into a correct estate reduction.

## Not this card
Disability benefits in the assessment, which is card 0050.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a home is occupied by a qualifying relative, THE APP SHALL disregard it from the care means test.
- [ ] #2 WHEN a care charge cannot be met from liquid assets and the home is assessable, THE APP SHALL model a deferred payment secured on the home rather than reporting an unmet essential.
- [ ] #3 WHEN a deferred payment is in place, THE APP SHALL accrue interest and deduct the balance from the estate at death.
<!-- AC:END -->

## Tasks
- [ ] Widen the disregard to the statutory list; add a "who else lives here" input
- [ ] Model a deferred payment agreement as the default funding route, with the statutory interest rate sourced
- [ ] Deduct the accrued balance from the estate
- [ ] Test that a keeping-the-home plan no longer fails essentials for an unfundable charge
