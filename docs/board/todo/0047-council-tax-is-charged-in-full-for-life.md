# Council tax is charged at full rate for the whole projection

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 9). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Council tax is bundled into `Property::runningCosts` with maintenance and insurance, and charged in
full every year. Three things follow.

**No single-person discount.** Property running costs are added after the survivor spend factor is
applied, so a survivor is charged a couple's council tax for the rest of their life. The discount
is 25% and it is automatic.

**No Council Tax Reduction.** Pension-age support is prescribed by regulation, so councils cannot
cut it. A survivor near the Pension Credit line qualifies for close to full reduction, which on a
typical bill is well over a thousand pounds a year the model charges and they would not pay.

**No disabled band reduction.** It is **not means-tested**. It needs only a qualifying feature - an
extra bathroom, a room used for the disabled person's needs, or space to use a wheelchair indoors -
and it drops the bill a whole band. It is claimable now, not at some future point in the plan.

The Council Tax Reduction calculation can reuse the Pension Credit applicable amount the engine
already computes.

## Not this card
Pension Credit itself, which is card 0046.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL hold council tax as its own cost line, separate from maintenance and insurance.
- [ ] #2 WHEN only one person remains in a household, THE APP SHALL apply the single-person discount.
- [ ] #3 WHEN income and capital qualify, THE APP SHALL award Council Tax Reduction on the pension-age basis.
- [ ] #4 THE APP SHALL let a user record a disabled band reduction and apply it.
<!-- AC:END -->

## Tasks
- [ ] Split council tax out of `runningCosts`, with a band input
- [ ] Apply the single-person discount from the first death
- [ ] Compute Council Tax Reduction from the existing applicable amount, sourced and dated
- [ ] Add the disabled band reduction as an input, and prompt for it
