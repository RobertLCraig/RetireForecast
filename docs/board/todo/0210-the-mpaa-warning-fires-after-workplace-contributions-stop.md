# The MPAA warning fires after workplace contributions have stopped

## Why
`PathProjector::mpaaWarnings()` says the Money Purchase Annual Allowance now caps what can be paid
in. Its own docblock says it is not emitted for someone paying nothing in. But it tests the pot's
SET contribution (`$pot['contribution']`, `$pot['employerContribution']`), not what is still paid.

Employer and net-pay contributions are scaled by the year's work fraction, so they stop at
retirement. A person whose only contributions are of those kinds pays nothing in once retired. If
they first draw flexibly after that, they are still told the MPAA caps their contributions.

Seen while building card 0156: an employed person born 1965, retiring at 67, with a DC pot taking
only an employer contribution (net pay) and an earliest access age of 68, gets the warning "In this
plan that starts in 2033". Confirm the work fraction is nil in 2033 before building.

## Links

**Relates to**
- `0156` - its fixture showed the warning.

## Not this card
Member contributions paid from surplus by relief-at-source or non-earner. Those are not scaled by the
work fraction, so they can continue after retirement and the warning is right for them.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a member first triggers the MPAA in a year in which none of their DC contributions is paid, THE APP SHALL NOT emit the MPAA warning. proves: `test_the_mpaa_warning_is_not_raised_once_workplace_contributions_have_stopped`
- [ ] #2 WHEN a member first triggers the MPAA in a year in which a DC contribution is still paid, THE APP SHALL still emit it. proves: `test_the_mpaa_warning_is_raised_while_contributions_are_paid`
<!-- AC:END -->

## Tasks
- [ ] Make `mpaaWarnings()` ask whether a contribution is paid that year, not whether one is set.
