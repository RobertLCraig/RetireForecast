# The MPAA trigger year is not split at the draw

## Why
Found closing card 0073 #1. In the tax year a member first takes money flexibly, the law measures
only the money-purchase input paid AFTER the draw against the £10,000 MPAA; what went in before it
is measured against the ordinary allowance (the "alternative annual allowance" rules). The forecast
has no date inside a year, so `PathProjector::annualAllowanceCharges` measures the whole trigger
year against the MPAA. That can only overstate the charge, it is declared on
`PathProjector::applicableAllowance`, and the reader is told in that year's charge note. It is
still wrong for the commonest real case: a member who pays in while working, retires part-way
through the year and only then starts drawing is charged on money that in life carries none.

Splitting the year needs a point inside it. The model has one for pay (`workFraction`, the
retirement month) and for a planned draw (the birthday it is due at), and none for an ad-hoc draw
taken to meet a shortfall. Which point to assume for that draw is a figure the reader cannot see,
so it is Rob's call before it is a build.

## Links

**Relates to**
- `0073` - made the allowance a charge settled once at year end, and declared this simplification.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a member first flexibly accesses a pension part-way through a year, THE APP SHALL measure only the money-purchase input paid after the draw against the MPAA, and the rest against the ordinary allowance.
- [ ] #2 THE APP SHALL show the reader the point in the year it assumed the draw happened.
<!-- AC:END -->

## Tasks
- [ ] Decide which point in the year an ad-hoc shortfall draw is assumed to happen at.
- [ ] Research the alternative annual allowance rules (FA 2004 s227ZA onward) from HMRC's manual, with source and verified_on.
- [ ] Split `mpContributed` at the trigger and pass both parts to `AnnualAllowanceCalculator`.
