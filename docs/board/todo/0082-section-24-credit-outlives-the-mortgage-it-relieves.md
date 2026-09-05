# A let property keeps its Section 24 tax credit after the mortgage has gone

## Why
Found while building card 0024, which touched the same lines but not this fault.

`PathProjector` computes the relievable finance cost (around line 968) from the "Mortgage" expense
line, or from the amortisation schedule's interest, with **no check that the loan still exists**.
The spend side is guarded - the payment stops once `mortgageRepaid` or `homeSold` is set - so the
two disagree from that year on: the household pays no interest and still collects a basic-rate tax
reducer on it.

Three reachable ways in, all on a let primary residence:
- the mortgage is redeemed from capital at its maturity year (`RepayFromCapital`),
- the mortgage is called and the home is force-sold in place (`ForcedSale`),
- an amortising loan on a home that was force-sold: `interestIn()` keeps returning the schedule's
  interest, because the schedule does not know the property is gone.

The error is optimistic: it understates the tax on a let plan for every remaining year of the
projection, so it flatters exactly the borrowing routes the ranking in card 0022 turns on.

## Not this card
Whether a `Rental` income stream should stop when the property is sold. A stream carries its own
`endAge`, so that is a scenario-input question, not an engine fault.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a let property's mortgage has been redeemed or the home has been sold, THE APP SHALL grant no buy-to-let finance-cost credit from that year on.
- [ ] #2 WHEN the finance cost is zero, THE APP SHALL leave the year's tax unchanged.
<!-- AC:END -->

## Tasks
- [ ] Guard the `$financeCost` computation on `mortgageRepaid` / `homeSold`, the same condition the payment already carries
- [ ] Test: a let home redeemed from capital pays the full tax from the redemption year
- [ ] Test: a force-sold let home on an amortising loan claims no credit after the sale
- [ ] Re-run every stored let scenario, then `php artisan scenarios:audit`

## Comments
