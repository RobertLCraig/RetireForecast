# Benefits rule corrections

## Why
From the expert panel, 2026-08-19 (Citizens Advice findings 8, 10, 12, 13, 14 and the smaller
points). Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`. Each is small on its own
and several are wrong in a way that would not survive a tribunal.

**The severe disability addition is tested on one boolean.** The real conditions are narrower: the
qualifying benefit must be the middle or highest rate care component, Attendance Allowance at
either rate, or the daily living component of Personal Independence Payment. Mobility-only and
lowest-rate care do **not** qualify, so the boolean will award the addition to someone who is not
entitled. Also missing: the test that no other adult normally lives there, the registered-blind
route, and the effect of Carer's Allowance actually being paid.

**Only one carer addition is added.** The projector breaks on the first carer found. A couple where
each cares for the other gets two.

**Undrawn pension pots are excluded from the means test.** For a claimant over State Pension age
that is not the rule - an untaken money-purchase pot is treated as notional income. Excluding it
over-awards Guarantee Credit for the common case, and it is not in the Known divergences list.

**Assessable income is gross.** Pension Credit assesses earnings net of tax, National Insurance and
half of any pension contribution, and applies a small earnings disregard. The engine does neither.

**A mixed-age couple gets a silent nil.** Correct that they cannot claim Pension Credit, but the
engine says nothing about it and models no alternative. The loss to a real household is large and
the trap is well known.

**Savings Credit is dismissed on wrong reasoning.** The docblock says it is closed to those
reaching State Pension age after April 2016. For a couple it remains open if **either** member
reached it before then. The award is usually nil anyway, but the stated reason would lead a reader
to skip the question.

**A let property may be double-counted** - its equity into capital and its rent into income, where
the rules generally treat income derived from capital as capital.

**Sourcing.** The benefits source URL points at an eligibility page rather than the rate tables, and
the verified-on dates disagree between the registry and the parameters.

## Not this card
Housing Benefit and Council Tax Reduction, which are cards 0048 and 0047.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a person receives only a mobility component or a lowest-rate care component, THE APP SHALL NOT award the severe disability addition.
- [ ] #2 WHEN each member of a couple cares for the other, THE APP SHALL award two carer additions.
- [ ] #3 WHEN a claimant over State Pension age holds an undrawn money-purchase pot, THE APP SHALL treat it as notional income, or list the divergence.
- [ ] #4 WHEN a couple is mixed-age, THE APP SHALL explain that Pension Credit is unavailable and what replaces it.
<!-- AC:END -->

## Tasks
- [ ] Replace the disability boolean with the qualifying benefit and its rate
- [ ] Add the non-dependant test and the registered-blind route
- [ ] Allow two carer additions
- [ ] Assess earnings net, with the earnings disregard
- [ ] Notional income on undrawn pots, or a Known divergences entry
- [ ] Mixed-age warning; correct the Savings Credit docblock; resolve the let double-count
- [ ] Point the benefits source at the rate tables and align the verified-on dates
