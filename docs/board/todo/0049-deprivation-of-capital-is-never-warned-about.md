# Nothing warns that spending or giving away capital can cost entitlement

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 7, estate planner finding 12). Detail in
the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

Deprivation of capital appears nowhere in the engine, the methodology or the board. Meanwhile the
tool models the Pension Credit capital tariff, names the downsizing trap, and cheerfully models
plans built on selling possessions, taking family money and cashing pension pots.

Every one of those is a deprivation question. For benefits it is the notional-capital rule; for
care charging it is the deliberate-deprivation test in the statutory guidance, which can treat
money as still held where avoiding a charge was a significant motivation and the need was
reasonably foreseeable. A local authority can recover from the person who received the money.
Neither rule has a time limit.

This does not need a calculation engine. It needs a warning on events the model already knows
about: a pension lump sum or withdrawal, a capital receipt, a large one-off cost, a gift, a home
sale.

The right place is beside the lump-sum tax-shock output, because that is the screen where somebody
decides to take money out of a pot.

The related warning worth adding at the same time is gift with reservation of benefit - signing a
home over to a child and continuing to live in it. It is the idea a household in this position
hears from friends, and it is the one that costs them everything.

## Not this card
Modelling gifts out, PETs and the seven-year taper. That is card 0059.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a plan moves a large sum - a lump sum, a receipt, a gift, a one-off cost or a home sale - THE APP SHALL warn that it can be treated as still held for means-tested benefits and for care charging.
- [ ] #2 WHEN equity release or transferring a home is discussed, THE APP SHALL warn about gift with reservation of benefit.
- [ ] #3 THE APP SHALL point the reader at a benefits check before they move the money.
<!-- AC:END -->

## Tasks
- [ ] Add a deprivation `WarningCode` triggered by the existing capital events
- [ ] Surface it beside the lump-sum output and on the capital and care panels
- [ ] Add the reservation-of-benefit warning to the equity-release copy
