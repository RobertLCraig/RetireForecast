# A rent plan is never checked against whether a landlord would accept the tenant

## Why
From the expert panel, 2026-08-19 (property finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The engine asks only whether the money lasts. It never asks whether the tenancy would be granted.

Standard referencing requires annual gross income of at least 30 times the monthly rent. That is
an **income** test, and it does not care how much capital a household holds. A retired household
with a large pot and a small pension fails it, which is a wall no amount of proceeds gets them
over.

The two ways round it are both costly and neither is modelled: a UK homeowner guarantor at 36
times, which a household that has just sold no longer has, or six to twelve months' rent in
advance at every renewal, which locks up capital permanently.

The tool already flags an unaffordable purchase rather than modelling it away. This is the same
idea on the rent side, and it is the mirror of the survivor-affordability wall the model found on
the mortgage side - which is the most valuable finding the tool has produced.

## Not this card
Housing Benefit for a pension-age renter, which is card 0048.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a rent plan is modelled, THE APP SHALL flag any year in which household gross income falls below the standard referencing multiple of the rent.
- [ ] #2 THE APP SHALL state the two normal alternatives to a failed reference, and the capital that rent in advance would tie up.
- [ ] #3 THE APP SHALL include the deposit and first month up front as a cost at the start of a rent plan.
<!-- AC:END -->

## Tasks
- [ ] Add a referencing feasibility flag using the existing `WarningCode` pattern
- [ ] Source the referencing multiple, with `source` and `verified_on`
- [ ] Charge deposit plus first month as a one-off at the start of a rent plan
- [ ] Copy on the rent result explaining what a failed reference means
