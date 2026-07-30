# Economic assumptions — SIGNED OFF 2026-06-24

_Last updated: 2026-07-18 (added stochastic salary growth: volatility + salary-equity correlation)_

> **Status: SIGNED OFF by Rob (2026-06-24), adopted as proposed.** Set A (FCA default) is the
> engine default; Sets B and C ship as runtime compare overlays. Re-verification against source
> is a **manual review** tracked as an open item in HANDOVER (the sets sit at their 2026-06-24
> sign-off; `AssumptionSetLibrary` carries a prose `sourceNote`, not per-figure URLs/dates —
> `figures:freshness` covers statutory tax figures only, `mortality:refresh` covers the ONS grid).
> See [DECISIONS.md](../DECISIONS.md).

## Runtime editability (2026-06-29 / 2026-07-02)
The signed-off presets are the sourced **baseline**, no longer the only figures a forecast can use:
- **User-derived custom sets (2026-06-29; care cost growth added 2026-07-18):** the seven economic
  assumptions (investment growth, inflation, house growth, rent growth, salary growth, income yield,
  care cost growth) are editable on builder step 1, stored as a sparse `assumptionOverrides` delta on
  the chosen preset (empty = the preset, so a re-sourced preset flows through). Applied once in
  `ScenarioForecaster::assumptions()`; the results panel labels a tuned set **(customised)** and marks
  each user-set figure.
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
| House growth volatility (real) | 9% | 11% | 9% |
| House–equity correlation | 0.20 | 0.20 | 0.20 |
| Rent inflation (real) | 0.5% | 0.5% | 0.0% |
| Salary growth (real) | 1.0% | 1.5% | 1.0% |
| Salary growth volatility (real) | 2.0% | 2.5% | 2.0% |
| Salary–equity correlation | 0.10 | 0.10 | 0.10 |
| Investment income yield (nominal) | 2.0% | 2.0% | 2.0% |
| Care cost growth (real, above CPI) | 2.0% | 2.0% | 2.0% |
| Investment charges (a year, on invested balances) | 0.50% | 0.50% | 0.50% |

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
6. **House-price growth is now stochastic in the Monte Carlo (added 2026-07-18).** REAL house-price
   volatility **9%** a year (Set B's long-run **11%** spans the volatile mid-century + 1970s–2000s
   cycles) — roughly half of equities' ~20%, matching the long-run finding that housing is far less
   volatile than equities. Modelled as a single **house–equity correlation of 0.20** (low positive)
   rather than a full extra matrix row: the deterministic central projection is unchanged (it uses
   the mean), but the fan now widens with the home's value. The **low** correlation is the
   load-bearing choice — it is *why* selling and investing the proceeds diversifies concentrated
   housing risk, so the sell-and-rent option carries different risk from stay-put/buy. **Confirm
   you're happy with 9%/11% vol and the 0.20 correlation** (both tunable per set).
7. **Salary growth is now stochastic in the Monte Carlo too (added 2026-07-18).** A still-working
   household's future pay rises (and the savings/pension contributions the surplus funds) are
   uncertain, so REAL salary growth now draws a per-year shock. Volatility **2.0%** real (Set B's
   long-run **2.5%** spans the volatile 1970s–80s real-wage swings): aggregate real earnings growth
   is far smoother than markets — about half the volatility of GDP growth (SF Fed) and near-acyclical
   once workforce composition nets out. Correlated to equities at a deliberately **low 0.10** (weaker
   than housing's 0.20), reflecting that near-acyclicality; the contemporaneous GDP-growth/equity-return
   link is close to zero. The deterministic central projection is unchanged (it uses the mean); the
   effect is narrower than housing (it only bites for the working years of a still-earning person), but
   it stops a working couple's accumulation looking artificially certain. **Confirm you're happy with
   2.0%/2.5% vol and the 0.10 correlation** (both tunable per set, and opt-in: a null volatility keeps
   salary deterministic, so every pre-existing stored run reproduces unchanged).
8. **Care fees escalate at CPI + 2% real (added 2026-07-18; verified_on 2026-07-18).** The engine draws
   one CPI series and models most costs as a real spread over it; care was the exception left riding flat
   CPI, which understated the tool's headline late-life risk. Care-home fees are ~60–75% staff cost pinned
   to the **National Living Wage**, which government ratchets deliberately above prices, and PSSRU/LSE and
   OBR long-term social-care projections escalate care unit costs on **earnings/productivity (~2% real above
   CPI)**, not CPI. The recent ~10%/yr run-rate (≈CPI+4–5%) is an NLW + employer-NI spike, **not** a standing
   assumption; the defensible standing range is 1.5–3% real. Per the adverse-default rule the shipped value
   is the **most adverse of the plausible standing values, CPI + 2%** (a time-limited "care shock" at CPI+4–5%
   remains an unbuilt option — see docs/PLAN-output-inflation-and-charts.md A1). Applies to the sampled
   self-funder fee, compounded to the year the (late-life) spell falls, mirroring the property-costs bucket.
   **Opt-in / null-safe:** a null rate keeps care flat-real, so every pre-existing stored care run reproduces
   unchanged. User-editable per scenario. **Confirm you're happy with CPI + 2% real.**
9. **Deterministic care-stress parameters (A2, added 2026-07-18; verified_on 2026-07-18).** The "What you can
   afford" screen shows, beside each care-free verdict, an "if significant care is needed" stress:
   **one 4-year nursing spell at £1,800/wk (self-funder)** on the **last-surviving partner**, ending at their
   representative death age, means-tested and CPI+2%-escalated. Per the adverse-default rule these are the
   adverse-but-defensible end: £1,800/wk is LaingBuisson's top-decile / dementia-nursing figure (national
   average is ~£1,600); 4 years is the upper tail (PSSRU mean ~2.5 yr, ~27% of stays exceed 3 yr). **One
   spell on the last survivor, not both partners':** a single significant spell still discriminates a strong
   plan from a weak one, where a both-partners worst case would sink every plan and tell the reader nothing;
   the last survivor is the adverse means-test position (alone → the home is assessable, no partner income to
   share the cost). **These are fixed for now — a per-scenario editor (fee / duration / onset) and a
   probability-weighted "typical" alternative are flagged refinements. Confirm you're happy with the £1,800/wk
   × 4-year single-spell stress.**
10. **Investment charges 0.50% a year (uniform across sets, added 2026-07-31; verified_on 2026-07-31).** Every
   return figure above is **gross of charges** — the FCA COBS 13 basis quotes returns before costs and expects
   charges to be deducted separately — so until now the household was modelled as holding its portfolio for
   free. Cost is the most reliably predictable drag in the whole model: more certain than any return
   assumption, and it compounds every year in the *reassuring* direction. The charge covers the platform /
   administration fee plus the funds' ongoing charges (OCF), and is deducted from **invested** balances only
   (DC pots, ISAs, GIAs). **Cash deposits bear none** — a bank account has no platform or fund fee — and
   neither does the home.
   **Why 0.50%, and why not the most adverse figure.** The evidence: UK workplace DC default arrangements
   averaged **0.48%** member-borne (DWP Pension Charges Survey 2020; non-qualifying schemes 0.53%), with a
   median AMC of **0.28%** on providers' largest default funds (DWP Pension Provider Survey 2024/25, published
   21 Jul 2025); the statutory **0.75%** charge cap binds auto-enrolment defaults but **not** decumulation, so
   it is an upper anchor rather than a ceiling for a retired household; retail DIY runs ~**0.30–0.60%** all-in
   (platform 0.15–0.35%, fund OCF ~0.15–0.25%). 0.50% sits just on the adverse side of that central case.
   This is a **deliberate departure from the usual most-adverse default**: the charge falls on invested wealth
   and not on housing, so it moves the sell-and-invest plans against the stay-put ones. An over-adverse figure
   would therefore be a thumb on the scale of the very comparison the tool exists to make, not a safe margin.
   **Opt-in / null-safe:** a null charge keeps returns gross, so every pre-existing stored run reproduces
   unchanged. User-editable per scenario (the 8th economic assumption). **Confirm you're happy with 0.50%** —
   and note that an *advised* household pays this plus an ongoing advice fee (~0.83%), which is the separate
   cost-of-advice comparison in docs/build/PLAN-adviser-parity.md B1, not modelled here.
- FCA Handbook COBS 13 Annex 2 (projection rates): https://handbook.fca.org.uk/handbook/COBS/13/Annex2.html
- FCA/PwC, "Rates of return for FCA prescribed projections" (2017): https://www.fca.org.uk/publication/research/rates-return-fca-prescribed-projections.pdf
- UBS Global Investment Returns Yearbook 2025 (DMS): https://www.ubs.com/global/en/investment-bank/insights-and-data/2025/global-investment-returns-yearbook-2025.html
- Barclays Equity Gilt Study 2025: https://www.ib.barclays/news-and-events/equity-gilt-study-2025.html
- OBR Economic and Fiscal Outlook, March 2026: https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/
- ONS Private rent and house prices, UK (June 2026): https://www.ons.gov.uk/economy/inflationandpriceindices/bulletins/privaterentandhousepricesuk/june2026
- Jordà, Knoll, Kuvshinov, Schularick & Taylor, "The Rate of Return on Everything, 1870–2015", NBER Working Paper 24112 (housing far less volatile than equities; low equity–housing covariance / diversification gains): https://www.nber.org/papers/w24112
- Champagne, Kurmann & Stewart, "Dissecting Aggregate Real Wage Fluctuations", FRB San Francisco WP 2011-23 (aggregate real wage growth volatility ~0.51× GDP-growth volatility — far smoother than profits): https://www.frbsf.org/wp-content/uploads/wp11-23bk.pdf
- ONS, Average weekly earnings in Great Britain (real regular-pay growth series used to sanity-check the ~2% annual real-earnings volatility): https://www.ons.gov.uk/employmentandlabourmarket/peopleinwork/employmentandemployeetypes/bulletins/averageweeklyearningsingreatbritain/january2026
- King's Fund, Social Care 360 — expenditure and provider fees (care unit-cost drivers): https://www.kingsfund.org.uk/insight-and-analysis/long-reads/social-care-360-expenditure
- PSSRU/LSE (Wittenberg et al.), long-term care expenditure projections (care unit costs escalated on earnings/productivity, ~2% real above prices): https://eprints.lse.ac.uk/88376/1/Wittenberg_Adult%20Social%20Care_Published.pdf
- DWP, Pension Charges Survey 2020 (average member-borne ongoing charge 0.48% in qualifying default arrangements; 0.53% non-qualifying; all below the 0.75% cap): https://www.gov.uk/government/publications/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes/pension-charges-survey-2020-charges-in-defined-contribution-pension-schemes
- DWP, The Pension Provider Survey 2024/25, published 21 July 2025 (median AMC on providers' largest default funds 0.28%; decumulation charges too incompletely reported to publish): https://www.gov.uk/government/publications/the-pension-provider-survey-202425/the-pension-provider-survey-202425
- Vanguard Investor UK, fees explained (account fee 0.15% capped at £375/yr, £4/mo under £32,000; self-managed fund OCFs 0.06%–0.79%): https://www.vanguardinvestor.co.uk/what-we-offer/fees-explained
- LaingBuisson, "Care of Older People" UK market report — self-funder fee inflation (~10%/yr to Dec-2025, ~20% over two years; NLW + employer-NI driven): https://www.laingbuisson.com/press-releases/older-people-forced-to-pay-nearly-20-more-for-their-care-as-fees-skyrocket-over-the-last-two-years/

⚠️ Confidence flags from the research: Barclays Equity Gilt Study exact figures come from
adviser summaries of the paywalled study; the FCA 2/5/8 + 2% inflation deduction is
corroborated via the FCA/PwC report and secondary sources; the cash-vol override and the
cash correlations are modelling choices, not cited point values.
