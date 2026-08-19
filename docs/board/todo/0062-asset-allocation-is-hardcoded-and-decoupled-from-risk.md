# The growth assumption can be raised without raising the risk

## Why
From the expert panel, 2026-08-19 (adviser finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. The adviser called this the finding an investment
committee would stop on.

**Allocation is hardcoded.** `ForecastSettings::allocation()` falls back to a cautious 40/60 and
`ScenarioBuilder` passes that in explicitly. It is not among the editable assumptions. It is the
largest single determinant of the answer and the user cannot touch it.

**The growth override moves return without moving risk.** `AssumptionOverrides::apply` handles an
investment-growth override with a uniform parallel shift of every asset class's **mean**, leaving
volatilities and correlations untouched. So a user who raises growth because they hold equities gets
an equity return at a cautious portfolio's volatility. That manufactures a free lunch inside a Monte
Carlo whose entire job is to price risk.

**There is no glidepath.** The portfolio is 40/60 at 66 and 40/60 at 99. No de-risking option
exists.

**The bond assumption may be stale.** A zero real return on a 60% bond weight is a large part of the
blended figure, and it is stale in the cautious direction against recent index-linked gilt yields -
which also suppresses the modelled value of the de-risked, secured-income strategies a cautious
household most needs.

## Not this card
Disclosing the current default, which is card 0038.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL expose portfolio allocation as an editable input, defaulting to the current cautious mix.
- [ ] #2 WHEN a user changes the expected return, THE APP SHALL change the volatility with it, or refuse the change.
- [ ] #3 THE APP SHALL offer a de-risking glidepath over the projection.
- [ ] #4 THE APP SHALL carry a source and a verified-on date for each asset class return and volatility.
<!-- AC:END -->

## Tasks
- [ ] Add allocation to the builder and to `ForecastSettings`
- [ ] Make the growth override re-weight the mix rather than shift the means
- [ ] Add a glidepath option
- [ ] Re-source the bond real return against current index-linked gilt yields, dated
