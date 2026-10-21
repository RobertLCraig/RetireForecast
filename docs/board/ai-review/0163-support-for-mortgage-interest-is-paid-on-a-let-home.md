# Support for Mortgage Interest is paid on a let home

## Why
Found building card 0030 on 2026-10-05. `PathProjector::supportForMortgageInterestNominal()` returns
an award whenever the household is on Guarantee Credit and still owns its primary residence. It
never asks whether that home is let. SMI helps with the home a claimant lives in, so a let home
should get none.

Since card 0030, a let home's service charge that the rent paid has already left the spend. SMI
then takes the same charge off the spend a second time, and adds it to the loan secured on the
home. The spend is understated and the SMI debt grows for a bill nobody was charged.

## Links

**Relates to**
- `0030` - moved a let home's service charge out of the spend.
- `0045` - Support for Mortgage Interest itself.

## Not this card
- SMI on a home the household lives in.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the primary residence is let, THE APP SHALL meet none of its mortgage interest or service charge through Support for Mortgage Interest. proves: `test_a_let_home_gets_no_support_for_mortgage_interest`
<!-- AC:END -->

## Plan
Return zero from `supportForMortgageInterestNominal()` when `primaryResidence->isLet`. Confirm the
rule against the DWP SMI guidance before building, and record the source.

## Comments

**2026-10-21** RESULT: done
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/tests/Forecast/SupportForMortgageInterestTest.php
OUT-OF-SCOPE: none

The engine fix was already in master before this card: card 0045 #1 (commit 613d9c2f, 2026-10-07) made `PathProjector::supportForMortgageInterestNominal()` return zero when `primaryResidence->isLet`. So no engine code changed and no ENGINE_VERSION bump or re-run is owed by this card.

What I added: `test_a_let_home_gets_no_support_for_mortgage_interest`. A let-home claimant on Guarantee Credit gets an SMI balance of zero in every year, and its spend target equals the same let household off the credit in every year. So neither the interest nor the service charge is taken off the spend a second time. The older 0045 test only checked year 0 and the loan, not the spend.

Watched red: with the `isLet` guard removed, the test failed at year 0 with an SMI balance of 449000 pence (the interest and service charge being met). With the guard back, the test passes.

Not settled: the Plan asks to confirm the rule against the DWP SMI guidance and record the source. Web access is refused in this unattended session, so I did not verify or cite it. The occupation rule is in the Loans for Mortgage Interest Regulations 2017 (SI 2017/725), but I have not checked the exact paragraph. The source is still owed in the guard's comment at PathProjector.php:2497. No browser check is needed, because no screen changed.
