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

## Links

**Relates to**
- `0024` - found while building that card, which touched the same lines but not this fault.
- `0022` - the credit flatters exactly the borrowing routes that ranking turns on.

## Not this card
Whether a `Rental` income stream should stop when the property is sold. A stream carries its own
`endAge`, so that is a scenario-input question, not an engine fault.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a let property's mortgage has been redeemed or the home has been sold, THE APP SHALL grant no buy-to-let finance-cost credit from that year on.
- [x] #2 WHEN the finance cost is zero, THE APP SHALL leave the year's tax unchanged.
<!-- AC:END -->

## Tasks
- [x] Guard the `$financeCost` computation on `mortgageRepaid` / `homeSold`, the same condition the payment already carries
- [x] Test: a let home redeemed from capital pays the full tax from the redemption year
- [x] Test: a force-sold let home on an amortising loan claims no credit after the sale
- [ ] Re-run every stored let scenario, then `php artisan scenarios:audit`

## Comments

**2026-09-08**
RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/tests/Forecast/BuyToLetFinanceCostTest.php, app/Forecast/ScenarioForecaster.php, docs/HANDOVER.md, docs/board/in-progress/0082-section-24-credit-outlives-the-mortgage-it-relieves.md
OUT-OF-SCOPE: none

The fix is a MOVE, not a second condition. The finance-cost block used to sit above the
redemption and forced-sale blocks, so at that point in the year `mortgageRepaid` and `homeSold`
still held last year's answer and no guard written there could have seen the redemption happening
in the same year. It now runs immediately after the forced-sale block and carries exactly the
condition the payment below carries (`! mortgageRepaid && ! homeSold`), which is what makes one
guard close all three routes the Why names: the two flags are where all three end up. Nothing
between the old and the new position reads `$totalTaxNominal` or `$netCashNominal`, and the
Pension Credit fixed point starts below both, so the move changes no other figure.

Both tests were watched failing for the criterion's own reason, not for a missing class: the let
twin's tax was 323,419 pence below the residential twin's in the redemption year (the whole
interest-only credit) and 177,726 pence below it in the forced-sale year (the amortising
schedule's own interest for that year). Green after the guard, in the sale year and in every year
after it.

Assumed: the credit stops in the redemption or sale YEAR ITSELF, not the year after. That is what
the criterion says ("from that year on") and it is what the payment already does, so the two sides
now agree year for year. In life a loan redeemed part way through a year has borne some interest;
this engine charges the payment in whole years and always has, so relieving it in whole years is
the reading that reconciles.

`ENGINE_VERSION` is `finance-engine/section-24-credit-dies-with-the-mortgage` and the
**stored-scenario re-run is owed** for any let plan that redeems or sells: its tax was too low, so
its wealth, depletion year, estate and odds are all too favourable. `GoldenMasterTest` did not
redden (its fixture lets nothing) and needed no re-pin. The fourth Task is left open: the re-run
writes to the shared application database that the main checkout serves from, which is not a write
an unattended worktree session should make. `php artisan scenarios:audit` was run read-only and
reports **no new problem class**: every problem it prints is the pre-existing "carries no integrity
stamp (it predates the column)" class, one per stored run, which the owed re-run is what clears.

No screen changed, so nothing here needs a browser check.
