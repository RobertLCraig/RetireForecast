# An annuity can only be bought with pension money, so the obvious fix cannot be tested

## Why
From the expert panel, 2026-08-19 (adviser finding 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`AnnuityPurchase` can only be funded from a defined-contribution pot -
`PathProjector::processAnnuityPurchases` walks the pots and nothing else. So a household whose
money sits in cash, a general investment account or an ISA **cannot buy an annuity in this model at
all**, however much of it there is.

That rules out the textbook case for annuitising: a large essential spending floor, almost no
flexible spend, a much younger spouse facing a long period alone, no capacity for loss, and a
failure mode that is longevity plus an income cliff rather than sequence risk. Buying secured
income for the survivor is the one intervention that removes that risk permanently, and the tool
cannot express it.

Buying an annuity with money that is not pension money is a **purchased life annuity**, and its tax
treatment is different and favourable: only the interest element is taxable, with the exempt
proportion set by age at purchase. That treatment is a material part of why it suits a basic-rate
survivor, and the engine has no representation of it.

The adviser's conclusion: a decision between two plans, taken without a partially-annuitised
variant on the table, is a decision taken without the third option.

## Not this card
Annuity rate sourcing and the tax-free lump sum interaction, which are in card 0065.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL let an annuity be bought from a named non-pension account.
- [ ] #2 WHEN an annuity is bought with non-pension money, THE APP SHALL tax only the interest element, using the exempt proportion for the buyer's age.
- [ ] #3 THE APP SHALL let an annuity purchase be deferred to a chosen age, with income starting later.
- [ ] #4 THE APP SHALL let an annuity be marked as enhanced for impaired health, at a disclosed uplift.
<!-- AC:END -->

## Tasks
- [ ] Allow a non-pension source account on `AnnuityPurchase`
- [ ] Implement the purchased life annuity tax split, sourced and dated
- [ ] Add a deferred start age and an enhanced-rate flag
- [ ] Add a partially-annuitised what-if variant so it can be compared
