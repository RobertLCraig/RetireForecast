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
- [x] #1 WHEN a plan moves a large sum - a lump sum, a receipt, a gift, a one-off cost or a home sale - THE APP SHALL warn that it can be treated as still held for means-tested benefits and for care charging.
- [x] #2 WHEN equity release or transferring a home is discussed, THE APP SHALL warn about gift with reservation of benefit.
- [x] #3 THE APP SHALL point the reader at a benefits check before they move the money.
<!-- AC:END -->

## Tasks
- [x] Add a deprivation `WarningCode` triggered by the existing capital events
- [ ] Surface it beside the lump-sum output and on the capital and care panels
- [x] Add the reservation-of-benefit warning to the equity-release copy

## Comments

**2026-09-06**
RESULT: done
TESTS: +11 new, all green
TOUCHED: packages/finance-engine/src/Benefits/Deprivation.php; packages/finance-engine/src/Support/WarningCode.php; packages/finance-engine/src/Forecast/PathProjector.php; packages/finance-engine/tests/Benefits/DeprivationWarningTest.php; app/Forecast/ResultPresenter.php; app/Forecast/LumpSumTaxShock.php; tests/Feature/Forecast/DeprivationNoticeTest.php; docs/HANDOVER.md; docs/board/in-progress/0049-deprivation-of-capital-is-never-warned-about.md
OUT-OF-SCOPE: none

`Benefits\Deprivation` is the one home for the copy, so the four surfaces cannot say it
differently: the notional capital rule for means-tested benefits, the deliberate deprivation test
in Annex E of the Care and Support Statutory Guidance (significant motivation plus a reasonably
foreseeable need, and the council can recover from whoever received the money), that neither rule
has a time limit, that this forecast applies neither because both turn on motive, the
benefits-check pointer, and the gift-with-reservation-of-benefit paragraph.

`PathProjector::deprivationWarnings` raises `WarningCode::CAPITAL_DEPRIVATION` on any year that
moves a large sum: a pension lump sum or withdrawal, capital received, a labelled one-off cost
(which is how a gift out is entered in this builder today), or a forced sale. "Large" is the
£16,000 capital limit that already ends Housing Benefit and Council Tax Support, read from
`housingSupportUpperCapitalLimit` rather than restated, and passed in uprated to the year's prices
so the real year and its nominal twin trip on exactly the same moves. Nothing smaller can end an
award on its own, and a warning on every plan is a warning nobody reads, so the negative cases are
tested too. The message is a WARNING, not a calculation, because no model holds motive or
foreseeability: no figure moves, so there is no `ENGINE_VERSION` bump and no stored re-run.

Surfaces: a `capital_deprivation` results note (which the PDF shares), reported once on the
earliest move; and the lump-sum tax-shock panel, which the card named as the right place. The
panel needed no Blade change, because it already renders the engine's warnings list.

What I assumed, and where the repository could not settle it:

- **A year-0 home sale is invisible to the projector.** `HousingComparison::withHousing` hands it a
  household that already holds the proceeds, so the engine cannot warn on the single largest move
  this tool exists to compare. The presenter raises that one itself off the `HousingAction`, and it
  takes precedence over any later engine warning (it is year 0 and it is the biggest move). That is
  why `Deprivation::message` takes an OPTIONAL threshold: the presenter holds no tax-year config,
  and a home sale needs no size test to be large.
- **The two rules are cited by name, not by fetched URL.** This session had no web access, so
  nothing is pinned with a `verified_on` date. Nothing here reaches a projection, only prose.
- **A gift out is not a modelled event.** It is entered as a one-off cost, which is what the trigger
  reads. Modelling gifts properly is card 0059, which this card excludes.

Not done, and why:

- **The care panel carries no warning line of its own** (task 2, left open). `careImpactPanel` is a
  Monte Carlo statistics panel with no free-text slot, so it would need a Blade change of its own;
  the care-charging rule is stated inside the message the results note and the lump-sum panel both
  carry, which is what acceptance #1 asks for. Nothing was carded for it because it is this card's
  own task, not a separate fault.
- **`docs/spec/METHODOLOGY.md` still does not mention deprivation**, which the card's Why observes.
  It is not in the acceptance, so it is left alone rather than quietly widened.
- **Not seen in a browser.** Built in a worktree, so the new note and the two new warning lines are
  proven by tests only.

One honesty note on the tests. Four of the six engine cases and three of the five app cases were
watched failing for the reason the criterion describes (no warning raised, no note, the
equity-release copy silent, the lump-sum panel silent). The two negative cases, that a quiet plan
and a small move raise nothing, passed from the first run because nothing raised anything yet;
they are regression guards on the threshold, not tests that were watched catching a defect.
