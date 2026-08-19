# A house sale happens instantly and costs nothing to bridge

## Why
From the expert panel, 2026-08-19 (property finding 3, adviser finding 9). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`HousingComparison` sells at year 0 and `withoutPropertyCosts()` strips the mortgage and the
service charge from year 0 entirely. The in-projection forced sale does the same from the sale
year. There is no time-to-sell, no chain, no void, no bridging and no dual running anywhere in the
engine.

A realistic instruction-to-completion on a leasehold flat is months, not days, and the seller pays
the mortgage, the service charge and the council tax throughout. A gap between sale and purchase
adds storage, a short let and a second council tax bill. On a sell plan that is a meaningful
fraction of the modelled net proceeds, and every sell plan carries it.

A forced sale is also not a willing sale. Repossession and deadline sales clear below open market,
and the engine sells at full modelled value.

## Not this card
Modelling a failed sale as its own branch. That is card 0069.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a home is sold, THE APP SHALL charge a disclosed transition cost covering the months between instruction and completion.
- [ ] #2 THE APP SHALL expose the assumed months to sell as an editable input with a sourced default.
- [ ] #3 WHEN a sale is forced by a mortgage maturity, THE APP SHALL apply a sourced, editable forced-sale discount to the price achieved.
<!-- AC:END -->

## Tasks
- [ ] Add a transition-cost one-off in the sale year, derived from the months-to-sell input
- [ ] Add a `forcedSaleDiscount` to `MortgageMaturityAction::ForcedSale`, sourced and dated
- [ ] Disclose both through `ResultPresenter::assumedFigures()`
- [ ] Tests for both, then re-run every stored scenario
