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

### 2026-09-08 review (v20260908224229-aa0f)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 427s, run by this job rather than reported by the card.

**acceptance: sound**

I checked both boxes against real code.

**Criterion 1 ÔÇö no credit after the mortgage or home has gone.**
`PathProjector::projectYear` (the `$financeCost` computation) now sits *after* both places that set the flags: the redemption block and the forced-sale block (both set `$state['mortgageRepaid']`, the sale also sets `$state['homeSold']`). The value is `0` when either flag is set, for both loan shapes ÔÇö the "Mortgage" expense line and the amortisation schedule's `interestIn()`. So all three routes in the card close with the one guard. Verified by `BuyToLetFinanceCostTest::test_a_let_home_redeemed_from_capital_claims_no_credit_from_that_year_on` and `::test_a_force_sold_let_home_on_an_amortising_loan_claims_no_credit_after_the_sale`, which compare a let twin to a residential twin in the event year and in later years.

**Criterion 2 ÔÇö zero finance cost leaves tax alone.**
The credit block in the same function is gated on `$financeCost > 0`, so with zero cost nothing touches `$totalTaxNominal` or `$netCashNominal`. The same two tests assert a zero tax difference, which is that criterion.

I tried to break it by looking for another place that ends the loan, and there is none: only those two sites set the flags, both above the guard.

VERDICT: sound

**scope: sound**

**What I checked**

I read only the card's own commit (`5dea1dd`), not the whole branch diff. It touches five files: the engine fix, the stamp, one test file, the card, and the handover.

**Did it grow past the card?**

- `PathProjector::projectYear` ÔÇö the finance-cost block moved and gained one guard. Nothing else in that function changed. I checked every line between the old and the new position: none of them reads `$totalTaxNominal` or `$netCashNominal`, so the move is behaviour-neutral apart from the guard. The agent's claim holds.
- `ScenarioForecaster::ENGINE_VERSION` ÔÇö a stamp bump. Required by the project rule, not scope creep.
- Test changes are confined to `BuyToLetFinanceCostTest`.
- The fence held. Nothing touches whether a `Rental` stream stops on sale; `rentalIncomePerOwner` is called exactly as before.

**What is half done**

Task 4 (re-run stored let scenarios, then `scenarios:audit`) is open. It is left unticked and explained: the re-run writes to the shared database, which an unattended worktree must not do. That is declared, not hidden, so it is owed work rather than a false claim of done.

**What you do now**

Nothing here. The re-run is somebody's next job in the main checkout.

VERDICT: sound

**breakage: sound**

I tried to break the guard and could not.

What I checked:

- `PathProjector::projectYear` ÔÇö the `$financeCost` guard now sits after the redemption block and the forced-sale block, and carries the same `! mortgageRepaid && ! homeSold` test as the mortgage payment further down. Both flags are set by those two blocks, so all three routes in the card close.
- Nothing between the old and new position writes `$totalTaxNominal` or `$netCashNominal` (the only writers are the income pass above and the benefit pass below), so no other figure moves.
- `$lettingCosts` (used for the reducer base) is computed before the sale block, so the sale year still deducts real letting costs. Consistent with income, which is unchanged by design.
- Sibling callers: `AmortisationSchedule::interestIn` returns 0 outside the term, `ExpenseProfile::withoutPropertyCosts` nulls `mortgageCosts`, and the year-0 sell variants build no schedule. So the year-0 sell path was never leaking a credit.
- No second place computes the Section 24 credit (`QuickWhatIf` and `ScenarioForecaster` only mention it in prose).
- `ENGINE_VERSION` bump is present in `ScenarioForecaster` and logged at the top of the stamp docblock, not just in the constant.

The unticked fourth task (re-run stored scenarios) is stated in the card with its reason, not hidden.

VERDICT: sound

