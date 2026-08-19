# A pension-age renter never receives Housing Benefit

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The engine awards Guarantee Credit and nothing else. In a sell-and-rent plan the household spends
its proceeds down over ten to fifteen years, capital falls below the limit, and a pension-age
renter is then squarely in Housing Benefit territory. The model shows nil for ever.

That is not a neutral simplification. It makes renting look worse than it is in exactly the tail
where a plan is judged to run short, while the buy-outright plans have no equivalent omission. The
tool is currently ranking rent against buy with a thumb on the scale.

Two related gaps in the capital treatment:

- Proceeds from a former home intended to buy another are disregarded for a period. The model
  applies the tariff from day one, so it overstates the hit in the year of a sell-and-rebuy.
- Capital held in a second property is valued at property less mortgage, with no deduction for the
  costs of sale that the rules allow.

## Not this card
Universal Credit. Out of scope for a pension-age tool; the mixed-age warning is card 0051.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a pension-age household rents and its income and capital qualify, THE APP SHALL award Housing Benefit on the pension-age basis.
- [ ] #2 WHEN proceeds of a former home are held with the intention of buying another, THE APP SHALL disregard them for the statutory period.
- [ ] #3 WHEN capital is held in property, THE APP SHALL deduct the allowed notional costs of sale before assessing it.
- [ ] #4 IF Housing Benefit is not modelled for a given plan, THE APP SHALL state on that plan that it is excluded and the plan is therefore understated.
<!-- AC:END -->

## Tasks
- [ ] Implement a pension-age Housing Benefit calculation, sourced and dated
- [ ] Add the sale-proceeds disregard and the notional sale-costs deduction
- [ ] Failing that, add the exclusion notice to every rent plan
