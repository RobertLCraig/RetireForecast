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
- [ ] #1 WHEN the primary residence is let, THE APP SHALL meet none of its mortgage interest or service charge through Support for Mortgage Interest. proves: `test_a_let_home_gets_no_support_for_mortgage_interest`
<!-- AC:END -->

## Plan
Return zero from `supportForMortgageInterestNominal()` when `primaryResidence->isLet`. Confirm the
rule against the DWP SMI guidance before building, and record the source.

## Comments
