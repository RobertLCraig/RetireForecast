# B5: capacity for loss

## Why
Next after B1 in the adviser-parity plan. Mostly framing over stress machinery that already
exists: how far can wealth fall before the essential floor breaks?

## Not this card
A3 ISA rules, A4 salary sacrifice, B3 estate checklist, B4 annual review.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL state how far wealth can fall before the essential spending floor is
      breached, for a given scenario.
- [x] #2 THE APP SHALL express that as a percentage fall and a cash figure, both net of the
      mortgage, consistent with the project's one definition of total wealth.
<!-- AC:END -->

## Tasks
- [x] Derive the breach point from the existing stress machinery
- [x] Surface it on the results page
- [x] PDF twin

## Direction
**2026-08-22** Built as `WealthFallLever` (engine) plus `App\DecisionSupport\CapacityForLoss`
(app), surfaced as a "How much could you afford to lose?" panel on the results page and its PDF
twin, pinned to the housing strategy on display like the protection panel.

What it does: marks every part of wealth down by the same fraction on the base date, then binary
searches integer percentages for the largest fall at which `essentialsAlwaysMet` still holds. The
search is over whole percents on purpose, so the figure reported is one the projection was actually
run at and passed, and the cash figure is derived from that same integer against
`WealthFallLever::baseWealth` (liquid + money-purchase pots + home equity net of the mortgage,
NNEG-floored), so the two figures cannot disagree.

Modelling calls I made, none of which the repository settled:
- **The fall is across the board, not investments only.** AC #2 pins the denominator to total
  wealth including the home, so a fall that only touched investable assets would report a
  percentage of a total it could never reach. Marking everything down together is also the more
  adverse reading, per the standing "adverse default, user-editable" preference. It is explicitly
  not a market model, and the panel says so.
- **The fall hits home EQUITY, not gross value**, so the mortgage stays put and a geared household
  correctly shows less capacity. A home already worth less than its loan is left alone (NNEG floor).
- **DB and State pensions are untouched** (income, not a pot). An unrealised GIA gain falls with the
  balance and is floored at zero, because the engine carries no loss forward.
- **The bar is the essential floor only**, not "the money lasts": that is the card's wording, and it
  is the bar that makes the answer sensitive to the lever.

One change came out of a smell-check against the real stored scenarios rather than the synthetic
fixtures: the first cut returned null when a plan already fails its essentials, which hid the panel
on twelve of the twenty-five stored scenarios. That is the most important thing the panel can say,
so it now reports `alreadyBreached` as its own state with its own copy. `scenarios:audit` is clean.

Not settled here, for whoever reviews: the reported capacity assumes the household carries on
spending exactly as entered after the loss. A household that cut back would have more room, and
nothing on the panel prices that; the "How far can we go?" explorer is the nearest existing answer.

Two housekeeping notes. `ScenarioForecaster::variantInputs()` is new: the "resolve the plan on
display before stressing it" logic existed privately in `ProtectionGap` and inline in
`SustainableSpend`, and this needed a third copy, so it now lives once on the forecaster and
`ProtectionGap` reads it. `SustainableSpend` still has its own single-variant version, left alone
as out of scope. And the session brief asked for `.\vendor\bin\pest.bat`, which this project does
not have: it runs PHPUnit, so the suite was run with `php artisan test` (all green) and
`.\vendor\bin\pint.bat --dirty` for style.

**This has not been looked at in a browser.** The worktree is not what Herd serves, so both new
panels still need Rob's eyes on the real page, and that check belongs with card 0001.
