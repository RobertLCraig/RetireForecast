# A per-property growth override switches off house-price risk

## Why
From the expert panel, 2026-08-19 (property finding 13, adviser finding 9). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

In `PathProjector::growState` (around line 2362), if `propertyGrowthReal` is set the home grows at
that fixed real rate. Otherwise it takes the stochastic draw. So any overridden property becomes
**deterministic** in the Monte Carlo.

That is backwards. The properties people override are the ones whose value is least certain - a
park home, a specific flat in a slow block - and overriding is exactly how they say so. The
scenario that most needs a fan of outcomes gets a straight line instead. The salary override
already has the right design: it sets the trend, not the risk.

Separately, the modelled house volatility is an **index** figure. An index diversifies away the
property-specific component; a single property carries roughly double the dispersion. A household
whose whole net worth is one flat is not exposed to index risk.

## Not this card
The value of any particular growth rate. That is a scenario input.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a property carries a growth override, THE APP SHALL apply it as the mean and keep drawing year-to-year variation around it.
- [ ] #2 THE APP SHALL apply a sourced single-property volatility uplift to a primary residence, disclosed as an assumed figure and editable.
- [ ] #3 WHEN a depreciating home such as a park home is modelled, THE APP SHALL use a wider volatility than the index default.
<!-- AC:END -->

## Tasks
- [ ] Make the override set the mean, not replace the draw, in `growState`
- [ ] Add a sourced single-property volatility multiplier to `AssumptionSet`
- [ ] Disclose it through `assumedFigures()`
- [ ] Re-run the park-home scenarios and report the range, not a point estimate
