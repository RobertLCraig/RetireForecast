# The upkeep of a home you would buy is priced as a percentage of its value

## Why
Where the reader gives no running cost for the home they would buy, and the home they are selling
has none of its own to scale, the model charges **1% of the purchase price a year** for the rest of
the plan (`HousingComparison::HOME_MAINTENANCE_RATE_BPS`). On a £150,000 purchase that is £1,500 a
year of essential spend, every year, from a figure nobody entered.

A roof, a boiler, a rewire, a bathroom and a set of windows cost about the same in a cheap area as
an expensive one. What a home's value tracks is its location and the quality of its stock, not the
cost of keeping it standing, so a percentage of value is a proxy for the wrong thing. It understates
upkeep at the low end, and the low end is exactly where a downsizing purchase sits: the plan that
buys the cheapest home is charged the least to maintain it, which is the opposite of how an older,
smaller, cheaper property usually behaves.

The 1% is the widely-quoted UK rule of thumb (Checkatrade's 2023 survey put average homeowner
maintenance at about 1% of value a year, older stock at 1.5% to 4%). Nobody chose the BASIS: the
rule of thumb is stated as a percentage, so the code was written as a percentage.

Card 0033 was asked to review this and could not finish it. Comparing a percentage basis against a
flat annual figure needs a published maintenance-cost series read from the web, and the unattended
build loop has no web access. The figure is now documented in
[docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) §17 with this card named as the open work.

## Links

**Relates to**
- `0033` - it raised this, disclosed both derived upkeep figures on screen, and left the basis open.

## Not this card
The DISCLOSURE of the figure, which card 0033 finished: the 1%-of-value fallback shows as an
assumed figure and the scaled-from-the-current-home branch as a computed one. This card is only
about which basis the fallback should use.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN no running cost is entered for a home being bought, THE APP SHALL charge upkeep on a basis backed by a published maintenance-cost source, with that source and its verified-on date recorded beside the figure. proves: `test_an_assumed_upkeep_figure_is_disclosed_with_its_value`
- [ ] #2 THE APP SHALL state, in docs/spec/ASSUMPTIONS.md, which basis was chosen and what the alternative would have charged on a low-value purchase. proves: none - a doc statement, read by a person
<!-- AC:END -->

## Tasks
- [ ] Research UK home-maintenance cost data: a percentage-of-value series and a flat-annual-cost
      series (English Housing Survey repair costs, RICS or BCIS maintenance data, a large insurer's
      or trade body's homeowner survey). Web access needed, so this card is not for the unattended
      loop until the sources are in hand.
- [ ] Compare the two bases on a low-value purchase (a £120,000 flat, a £60,000 park home) and on a
      mid-value one, and say which is less wrong at each end.
- [ ] Apply the chosen basis in `HousingComparison::HOME_MAINTENANCE_RATE_BPS` or its replacement,
      keeping the constant PUBLIC so `ResultPresenter::assumedFigures()` keeps reading it rather
      than restating it. Per the adverse-default rule, ship the more cautious of two defensible
      figures and leave it user-editable.
- [ ] Update ASSUMPTIONS.md §17 and re-run the stored scenarios if the figure moves.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Run `php artisan test` first and confirm it is green.

The figure has exactly one home, `HousingComparison::HOME_MAINTENANCE_RATE_BPS`, and exactly one
caller, `HousingComparison::newHomeRunningCosts()`. Its disclosure reads the constant in
`ResultPresenter::assumedFigures()`, so changing the constant moves the sentence on the results page
with it and `AssumedFiguresDisclosureTest` proves that link still holds.

A flat annual basis is a bigger change than a different percentage: `newHomeRunningCosts()` would
return a Money that does not depend on the buy price, and the disclosure sentence would have to stop
saying "% of its value". Both are small, but they are two edits, not one.

## Comments
**2026-09-05** WRITTEN BY THE AGENT THAT WORKED CARD 0033, which found this while meeting its own
third criterion. Nothing here has been fixed, and no figure has moved: 1% of value is still what the
model charges.
