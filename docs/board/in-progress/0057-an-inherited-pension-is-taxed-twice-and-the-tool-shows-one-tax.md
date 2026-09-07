# An inherited pension pot is taxed twice and the tool shows one tax

## Why
From the expert panel, 2026-08-19 (estate planner finding 8). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

From April 2027 an unused pension pot enters the estate for inheritance tax. The calculator adds it
and charges 40%. It never shows the **beneficiary's income tax** on drawing the inherited fund
where the member died at or after 75, which is taxed as the beneficiary's own pension income. The
inheritance tax change does not displace that charge.

For most households modelled here both deaths fall after 75, so a pot passing to a working-age
child suffers inheritance tax and then income tax - an effective rate around two thirds.

The methodology says the inheritance tax toggle exists to compare spending a pension down against
preserving an estate. Showing only one of the two taxes makes preserving the pot look roughly twice
as attractive as it is, in the direction that discourages spending it. That is the exact comparison
the toggle was built for.

**The nomination is missing too.** Pension death benefits pass under the scheme's discretion
following the member's expression of wish, not the will - and from April 2027 that form is an
inheritance tax document. There is no nomination input anywhere in the builder, so a pot nominated
to a child rather than to a spouse is still modelled as spouse-exempt at the first death.

## Not this card
Whether to spend the pot. That is a decumulation question, cards 0060 and 0063.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN an unused pension passes on a death at or after 75, THE APP SHALL show the beneficiary's income tax alongside the inheritance tax.
- [ ] #2 THE APP SHALL let the assumed beneficiary tax rate be edited, defaulting to the adverse rate and disclosed as an assumed figure.
- [ ] #3 THE APP SHALL record who each pension is nominated to, and use that rather than marital status when applying the spouse exemption.
<!-- AC:END -->

## Tasks
- [ ] Add the beneficiary income-tax line to the IHT panel, gated on death at or after 75
- [ ] Add a `nominatedBeneficiary` field per pension; route the exemption off it
- [ ] Source the treatment with `source` and `verified_on`
