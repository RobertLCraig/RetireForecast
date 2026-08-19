# The model assumes a marriage and a will that nobody was asked about

## Why
From the expert panel, 2026-08-19 (estate planner findings 2 and 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**Marital status defaults to married.** It is the default in the DTO, in the builder and in the
assembler fallback, and it is not disclosed as an assumed figure. It is the one input where the
wrong value is catastrophic: no spouse exemption, no transferable nil-rate band, no transferable
residence band, no State Pension inheritance, and most defined-benefit schemes pay a survivor's
pension only to a spouse or civil partner. The engine handles cohabitation correctly once told. The
defect is that it is never asked, and the default flatters the result - the opposite of the
standing "adverse default, user-editable" rule.

**A will is assumed too.** The first death is granted full spouse exemption and the home is assumed
to pass to descendants. There is no "is there a will?" input, no intestacy path, and no mention of
wills, intestacy or probate anywhere in the codebase. Under intestacy a spouse does **not** take
everything: they take the chattels, a statutory legacy and half the residue, and the children take
the rest.

**Spouse exemption is unlimited.** There is no domicile or long-term-residence input, and the
exemption is capped where the recipient spouse is not UK long-term resident unless an election is
made.

The date of marriage matters too, because it drives the State Pension inheritance rules.

## Not this card
The relationship-status mechanics, which the panel found correct and well tested.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL require marital status to be chosen, with no default, and disclose it as an assumed figure if one is ever supplied.
- [ ] #2 THE APP SHALL ask whether each person has a current will, defaulting to no will.
- [ ] #3 WHEN no will exists, THE APP SHALL apply the intestacy rules rather than granting full spouse exemption.
- [ ] #4 THE APP SHALL capture the date of marriage or civil partnership, and the residence position of the recipient spouse.
<!-- AC:END -->

## Tasks
- [ ] Remove the marital-status default; add it to `assumedFigures()`
- [ ] Add a will input and an intestacy path in `InheritanceTaxCalculator`
- [ ] Source the statutory legacy figure, with `source` and `verified_on`
- [ ] Add the marriage date and a long-term-residence flag
