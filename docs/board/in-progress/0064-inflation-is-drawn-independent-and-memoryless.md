# Inflation has no memory and is unrelated to investment returns

## Why
From the expert panel, 2026-08-19 (adviser finding 13). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`ReturnModel::generatePath` draws inflation from an independent normal each year, uncorrelated with
the asset shocks. Two consequences.

**No persistence.** Real inflation is strongly autocorrelated - it arrives in multi-year episodes,
1973 to 1975 and 2021 to 2023 being the obvious ones. Independent annual draws understate the
spread of the cumulative price level over decades. That matters here more than usual, because the
model runs against nominal tax thresholds frozen for years, so it understates fiscal drag.

**No correlation with returns.** In a real-return framework, a 2022-style shock is high inflation
**and** deeply negative real bond returns **and** negative real equity returns, all at once.
Drawing them independently means the model never produces the single worst year a bond-heavy
retiree has actually lived through.

Both are small changes to `ReturnModel` and both widen exactly the tail that matters.

This compounds with card 0024. Until an interest-only mortgage stops being CPI-indexed, high
inflation in this model is pure downside, because the natural hedge on a nominal debt has been
removed.

Related and cheap: `HistoricalBacktester` holds lifespans fixed at the representative death ages,
so the historical stress never combines a bad early sequence with a long life. Those two risks
multiply.

## Not this card
The choice of default inflation rate, which is already sourced and signed off.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL model inflation with year-to-year persistence, using a sourced parameter.
- [ ] #2 THE APP SHALL correlate inflation with real asset returns, so a high-inflation year can coincide with negative real returns.
- [ ] #3 THE APP SHALL let the historical backtest run at a long-life horizon as well as the representative one.
<!-- AC:END -->

## Tasks
- [ ] Add an autoregressive term to the inflation draw, sourced from UK history and dated
- [ ] Add an inflation row to the correlation matrix
- [ ] Run the backtester at the last-survivor 75th and 90th percentiles as well
- [ ] Re-pin the golden master from card 0039 afterwards
