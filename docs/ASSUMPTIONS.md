# Economic assumptions — SIGNED OFF 2026-06-24

_Last updated: 2026-07-02_

> **Status: SIGNED OFF by Rob (2026-06-24), adopted as proposed.** Set A (FCA default) is the
> engine default; Sets B and C ship as runtime compare overlays. Re-verification against source
> is a **manual review** tracked as an open item in HANDOVER (the sets sit at their 2026-06-24
> sign-off; `AssumptionSetLibrary` carries a prose `sourceNote`, not per-figure URLs/dates —
> `figures:freshness` covers statutory tax figures only, `mortality:refresh` covers the ONS grid).
> See [DECISIONS.md](../DECISIONS.md).

## Runtime editability (2026-06-29 / 2026-07-02)
The signed-off presets are the sourced **baseline**, no longer the only figures a forecast can use:
- **User-derived custom sets (2026-06-29):** the six economic assumptions are editable on builder
  step 1, stored as a sparse `assumptionOverrides` delta on the chosen preset (empty = the preset,
  so a re-sourced preset flows through). Applied once in `ScenarioForecaster::assumptions()`; the
  results panel labels a tuned set **(customised)** and marks each user-set figure.
- **Per-asset overrides (2026-07-02):** `DcPension::growthAssumptionOverride`,
  `Property::growthAssumptionOverride` and `Account::yield` let an individual asset depart from
  the set's figure (wired + completeness-tested — DECISIONS 2026-07-02).

All return and volatility figures are **REAL (above-inflation), annual**. Three asset
classes: global equities, gilts/bonds, cash. The engine reads whichever `AssumptionSet`
it is handed; a simulation snapshots the set it used so results stay reproducible.

## Why FCA returns but DMS volatilities
The FCA prescribes only central/mean projection rates (the 2% / 5% / 8% nominal lower/
intermediate/higher set, intermediate capped at 5%) and **publishes no volatilities** — yet
a Monte Carlo needs volatility and correlation. So the default set takes FCA-derived
expected returns and borrows volatilities/correlations from the Barclays Equity Gilt Study /
Dimson-Marsh-Staunton (DMS) long-run record. FCA real returns are derived from the FCA's own
asset-class nominal midpoints (equities 6.5%, gilts 2.0%, cash 1.5% nominal) deflated by the
Handbook's 2.0% inflation: `real = (1+nominal)/1.02 − 1` → equities **+4.4%**, bonds **0.0%**,
cash **−0.5%**.

## The shipped sets (signed off 2026-06-24; live in `AssumptionSetLibrary`)

| Field | Set A — FCA default (recommended) | Set B — DMS historical | Set C — OBR/BoE inflation-anchored |
|---|---|---|---|
| Equity real return | 4.4% | 5.2% | 4.4% |
| Bond real return | 0.0% | 1.5% | 0.0% |
| Cash real return | −0.5% | 0.5% | −0.5% |
| Equity volatility | 23% | 23% | 23% |
| Bond volatility | 13% | 13% | 13% |
| Cash volatility | 2% | 7.5% | 2% |
| Eq–Bond correlation | 0.30 | 0.46 | 0.30 |
| Eq–Cash correlation | 0.10 | 0.10 | 0.10 |
| Bond–Cash correlation | 0.30 | 0.30 | 0.30 |
| Inflation mean | 2.0% | 3.0% | 2.0% |
| Inflation volatility | 1.5% | 4.0% | 1.0% |
| House growth (real) | 1.0% | 2.5% | 1.0% |
| Rent inflation (real) | 0.5% | 0.5% | 0.0% |
| Salary growth (real) | 1.0% | 1.5% | 1.0% |
| Investment income yield (nominal) | 2.0% | 2.0% | 2.0% |

## Judgement calls flagged for Rob (these are the bits to sanity-check)
1. **Cash real volatility set to 2%, not DMS's 7.5%.** DMS's 7.5% is mostly historical
   inflation shocks (1900–1980). Since the model shocks inflation separately, using 7.5%
   would double-count inflation risk on cash. Set A/C use 2%; Set B keeps the pure-DMS 7.5%
   to represent the full historical experience. **Confirm you're happy with the 2% override.**
2. **Eq–Cash (0.10) and Bond–Cash (0.30) correlations are reasoned estimates**, not directly
   cited point figures (DMS/Barclays publish the equity–bond correlation, not these).
3. **House price growth +1% real is the cautious end** of a wide range (long-run UK real
   house growth estimates run ~1% to ~3%). Set B uses the historical +2.5%.
4. **Set A equity 4.4% is deliberately below long-run history (5.2%)** because the FCA caps
   the central equity rate — a "don't over-promise" default.
5. **Investment income yield 2.0% nominal (uniform across sets, added 2026-06-27 for A5):** the
   slice of a GIA's total return paid as taxable income (dividends/interest), anchored to the
   global-equity dividend yield (~1.3–2%) — a modelling assumption, not a cited point figure.
   Reviewed and kept 2026-06-27.

## Sources (re-verification = the manual review tracked in HANDOVER Open items)
- FCA Handbook COBS 13 Annex 2 (projection rates): https://handbook.fca.org.uk/handbook/COBS/13/Annex2.html
- FCA/PwC, "Rates of return for FCA prescribed projections" (2017): https://www.fca.org.uk/publication/research/rates-return-fca-prescribed-projections.pdf
- UBS Global Investment Returns Yearbook 2025 (DMS): https://www.ubs.com/global/en/investment-bank/insights-and-data/2025/global-investment-returns-yearbook-2025.html
- Barclays Equity Gilt Study 2025: https://www.ib.barclays/news-and-events/equity-gilt-study-2025.html
- OBR Economic and Fiscal Outlook, March 2026: https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/
- ONS Private rent and house prices, UK (June 2026): https://www.ons.gov.uk/economy/inflationandpriceindices/bulletins/privaterentandhousepricesuk/june2026

⚠️ Confidence flags from the research: Barclays Equity Gilt Study exact figures come from
adviser summaries of the paywalled study; the FCA 2/5/8 + 2% inflation deduction is
corroborated via the FCA/PwC report and secondary sources; the cash-vol override and the
cash correlations are modelling choices, not cited point values.
