---
needs: 0067
---
# A leasehold flat has no lease term

## Why
From the expert panel, 2026-08-19. Three reviewers found this separately - property, adviser and
estate planner. Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`Property` has fifteen fields and none is lease length. A leasehold flat is modelled as a
perpetual asset growing at the house rate. Three consequences:

- A lease shortening below about 80 years steps the extension premium up sharply, and the crossing
  can fall inside a projection.
- Below roughly 75 years unexpired a flat becomes hard to mortgage and hard to sell, which
  undermines both a term-end forced sale and the no-negative-equity backstop on a lifetime
  mortgage.
- Several equity-release lenders set a minimum unexpired term, some assessed at expected
  redemption rather than at outset, so an extension can be a precondition of the product rather
  than an option.

## Links

**Blocked by**
- `0067` - the lease extension premium has to be a real valuation before the model can charge it,
  or the engine gains a new invisible figure instead of losing one.

**Relates to**
- `0056` - a shortening lease and a compounding roll-up both eat the same no-negative-equity
  headroom, so the two need testing together.
## Not this card
Statutory lease-extension valuation. The premium is an input, not something the engine computes.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a property is entered as leasehold, THE APP SHALL accept its unexpired lease term and carry it forward year by year.
- [ ] #2 WHEN a modelled lease falls below 80 years inside a projection, THE APP SHALL warn, naming the year.
- [ ] #3 WHEN an equity-release or lifetime-mortgage plan would redeem with fewer than 75 years unexpired, THE APP SHALL flag the plan as at risk.
- [ ] #4 WHEN a leasehold property is sold with a short lease, THE APP SHALL apply a disclosed value haircut rather than selling at full modelled value.
<!-- AC:END -->

## Tasks
- [ ] Add `leaseYearsRemaining` to `Property`, and a dated lease-extension one-off cost
- [ ] Decrement the term in `PathProjector::growState`
- [ ] Warnings for the 80-year crossing and the lender minimum
- [ ] A sourced, editable terminal haircut below 80 years, disclosed via `assumedFigures()`
- [ ] Builder input plus the DATA-MODEL entry

