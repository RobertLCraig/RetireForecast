# A lifetime mortgage is only ever repaid at the end of the plan

## Why
From the expert panel, 2026-08-19 (estate planner finding 5). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`Property::$mortgageRollUpRate`'s own docblock says the balance is repaid from the estate on death,
sale **or care**. The projector only ever settles it at the end of the path.

Permanent entry into residential care by the last surviving borrower is a redemption event under
every standard equity-release contract. When it happens, the home is sold and the lender is paid
first.

That is the scenario a reader most needs to see before signing an equity-release deed, and it is
invisible. A survivor who enters permanent care after years of roll-up loses the home to the
lender, is assessed onto local-authority funding immediately, has no top-up and no choice of home,
and no home to return to if the placement turns out to be temporary. The twelve-week disregard does
not rescue them, because the equity is already charged, and a deferred payment is not normally
available on an encumbered home.

The tool currently shows that household living in the property to the end of the plan with an
estate.

## Not this card
The care means test itself, which is card 0055.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the last surviving borrower enters permanent residential care and the home carries a roll-up balance, THE APP SHALL sell the home and repay the balance in that year.
- [ ] #2 WHEN that happens, THE APP SHALL reassess the household on its new capital position with no home.
- [ ] #3 THE APP SHALL state this redemption trigger wherever an equity-release plan is displayed.
<!-- AC:END -->

## Tasks
- [ ] Add the care redemption branch to the projector's care handling
- [ ] Repay with the no-negative-equity cap; credit any residue to liquid assets
- [ ] Re-run the means test on the new position
- [ ] Add the trigger to the equity-release copy in `ResultPresenter`
