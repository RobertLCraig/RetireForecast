# The cheapest borrowing available to a pensioner is not in the engine

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Support for Mortgage Interest appears nowhere in the engine, the config or the board. It is the
first instrument a benefits caseworker reaches for when the problem is an unaffordable secured debt
in later life, which is precisely the problem the tool is modelling.

A Pension Credit Guarantee Credit claimant gets it with **no waiting period**, as a loan covering
interest on eligible capital up to a cap at the DWP standard rate, secured by a charge and repaid
on sale or death. For a pension-age claimant, eligible housing costs also include service charges
and ground rent, which matters on a leasehold flat.

The rate is roughly a third of a commercial lifetime-mortgage roll-up rate on the same security. It
is being compared against those products and it is not in the comparison.

The mechanic already exists: a rolled-up balance with a no-negative-equity cap, as
`Property::mortgageRollUpRate` and `growState` already do.

## Not this card
Whether the household qualifies. That depends on Pension Credit, which is a separate question.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a household receives Guarantee Credit and carries an eligible mortgage, THE APP SHALL meet the interest at the DWP standard rate up to the capital cap.
- [ ] #2 THE APP SHALL accrue what is met as a separate charge against the property, repaid on sale or death.
- [ ] #3 THE APP SHALL include eligible service charges and ground rent in the pension-age housing costs it covers.
<!-- AC:END -->

## Tasks
- [ ] Model the loan as a second roll-up balance at the sourced DWP rate, capped
- [ ] Gate it on a Guarantee Credit year
- [ ] Source the rate and the capital cap, with `source` and `verified_on`
- [ ] Deduct the balance from the estate at death
- [ ] Show it alongside the equity-release comparison
