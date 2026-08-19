# A one-path estate figure is shown next to a ten-thousand-path probability

## Why
From the expert panel, 2026-08-19. The estate planner and the adviser found this separately.
Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`ScenarioCompare` takes the success probability from the Monte Carlo run and the terminal wealth
and inheritance tax from the **deterministic** forecast. So a comparison table sets a figure with
ten thousand paths behind it beside one with a single path behind it, at a median lifespan, with no
care, and reports both to the pound.

The estate panel makes it worse by labelling that figure "everything you're modelled to leave". It
is stated exactly, and it is gross of probate cost and delay, the beneficiary's income tax on an
inherited pension, a park home's resale commission, and any care debt. The panel carries good
caveats about gifts, trusts and reliefs, and none about the precision of the estate figure itself.

Where a plan involves a rolled-up loan the point estimate hides the mechanism entirely. Once the
debt passes the property value the no-negative-equity floor binds, so terminal wealth stops moving
and the property is fully consumed. A reader sees a number and assumes it is the home minus some
cost. It is not the home at all.

`IhtDistribution` already reports the tax as a distribution, so the machinery exists.

## Not this card
Leading the results page with the probability, which is card 0010.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a terminal estate is reported, THE APP SHALL show it as a range across the simulated paths, not a single figure.
- [ ] #2 WHEN a comparison table mixes measures, THE APP SHALL label which are single-path and which are simulated.
- [ ] #3 WHEN a plan's property is consumed by a rolled-up loan, THE APP SHALL say in plain words that the lender takes the property and the beneficiaries inherit the remaining liquid assets.
- [ ] #4 THE APP SHALL caveat the estate figure for probate cost and delay, beneficiary income tax and any care debt.
<!-- AC:END -->

## Tasks
- [ ] Add a terminal-wealth percentile series to `Simulator`, as `IhtDistribution` already does
- [ ] Use it in `ScenarioCompare` and the estate panel
- [ ] Add the plain-words equity-release sentence and the caveat to `ResultPresenter`, so the PDF inherits both
