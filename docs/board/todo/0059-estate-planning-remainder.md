# Estate planning remainder

## Why
From the expert panel, 2026-08-19 (estate planner findings 10, 13, 14, 15). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

**A park home probably gets no residence nil-rate band.** The band needs an interest in a
dwelling-house. A park-home owner holds a chattel plus a pitch agreement, not an interest in land,
so the band is at best unsafe and most likely unavailable. The engine passes home equity through
regardless of what the home is. The downsizing addition would be the only route back, and that is
card 0053. Separately, the site owner's commission of up to 10% bites on any resale, and the exit
always happens eventually, so a park home's terminal value is overstated and far less liquid than
it reads. A non-resident beneficiary cannot simply inherit it.

**NHS care funding is neither modelled nor mentioned.** Continuing Healthcare is fully NHS-funded
and **not means-tested**; where it applies it removes the entire care bill and with it the estate
risk the care overlay exists to show. Funded Nursing Care is paid direct to a nursing home for any
resident assessed as needing nursing care, **including self-funders**, and reduces the fee by a
meaningful amount every week. The care stress currently charges a gross nursing fee for years.

**The first-death estate hard-codes a 50/50 property split.** There is no double-count - the state
figure is already the household's share - but the split has no input behind it, and there is no
field anywhere for beneficial shares or for how the property is co-owned. It is immaterial while a
couple is married and material the moment they are not.

**Lifetime gifting is absent.** No gifts out, no seven-year taper, no annual exemption, no normal
expenditure out of income. The estate planner's recommendation is to **leave this out** for now: it
is honestly disclosed, and the other cards come first.

## Not this card
Gift with reservation of benefit and deprivation warnings, which are card 0049.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a home is a park home or similar chattel, THE APP SHALL NOT claim the residence nil-rate band against it.
- [ ] #2 WHEN a park home is valued at the end of a plan, THE APP SHALL deduct the site owner's commission and say it cannot be left to a non-resident.
- [ ] #3 WHEN nursing care is charged, THE APP SHALL deduct the NHS funded nursing care contribution, sourced and dated.
- [ ] #4 THE APP SHALL explain that Continuing Healthcare, if awarded, removes the care charge entirely.
- [ ] #5 THE APP SHALL record beneficial shares in a property, defaulting to equal shares and disclosing that default.
<!-- AC:END -->

## Tasks
- [ ] Add a `qualifiesForRnrb` flag to `Property`, false for a depreciating or park home
- [ ] Net the resale commission off a park home's terminal value in the estate and the care means test
- [ ] Add the funded nursing care deduction to `CareAssumptions`, sourced
- [ ] Add a Continuing Healthcare note to the care panel
- [ ] Add beneficial shares, or disclose the 50/50 assumption
