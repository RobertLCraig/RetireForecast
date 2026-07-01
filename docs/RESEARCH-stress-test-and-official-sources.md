# Research: stress-test panel + official data sources (2026-07-01)

Rob asked: what are the **industry standards** for a retirement stress-test panel, leaning toward an
**official source (ONS / FCA)** for the data. This note also covers his follow-up: pull **care-cost
stochasticity** and the **ONS-refresh** mortality data **from ONS**. Findings + a recommendation; the
open decisions are at the end (nothing is built yet).

## 1. Industry standards for stress-testing a decumulation plan

Three approaches are used in the sector; they are complementary, not rivals.

1. **Historical sequence backtesting** — run the *actual* year-by-year return + inflation path starting in
   each past year ("what if you retired in 1937 / 1973 / 2000 / 2007?"). This is the purest test of
   **sequence-of-returns risk** (a bad first decade with withdrawals is what sinks a plan). It is the method
   behind the 4% rule (Bengen, US 1926–) and is what the leading UK decumulation tool, **Timeline** (Abraham
   Okusanya, *Beyond the 4% Rule*), is built on, using the **Dimson–Marsh–Staunton (DMS) Global Investment
   Returns Yearbook** long-run dataset. Strength: real, legible, no distributional assumption. Weakness: only
   ~1 century of (overlapping) history; past is not future.
2. **Monte Carlo** — random return paths from a fitted distribution. **We already have this** (10k-path
   joint-life simulator). Strength: many scenarios, tails beyond the historical record. Weakness: normal/vol
   assumptions can understate fat tails and the *clustering* that sequence risk is about.
3. **Deterministic prescribed shocks / regulatory scenarios:**
   - **FCA COBS 13 Annex 2 projection rates** — the low/mid/high illustration rates (commonly ~3/5/7% nominal,
     +2% CPI). These are **forward-looking central estimates for illustrations, not a stress test**. A
     modification-by-consent on SMPI runs to Sept 2026, and CP25/39 is consulting on adapting projections.
     Useful only as the "house-view growth assumption" anchor (which our editable assumptions already cover).
   - **PRIIPs KID performance scenarios** — a *defined regulatory stress methodology*: unfavourable / moderate
     / favourable = 10th / 50th / 90th percentile of the modelled distribution (Cornish–Fisher on historical
     returns), plus a **stress scenario** at a stressed volatility (99th percentile at 1yr, 95th beyond, since
     the 2023 RTS amendment). It is a per-product forward simulation rather than a household-cashflow backtest,
     but the "stressed-volatility percentile" is the nearest thing to an official quantitative stress *definition*.

**Fit for this tool:** we already have Monte Carlo (approach 2). The gap Rob flagged is the **sequence-risk
backtest (approach 1)** — the sector standard, and the one that most directly answers "could this plan have
survived the worst real start in living memory." Recommendation: build the stress-test panel as **historical
sequence backtesting**, and (optionally, later) expose a PRIIPs-style stressed-volatility scenario as a second
lens. FCA supplies the *methodology/illustration rates*, not historical return *data*.

## 2. The data problem — and the options (updated after inspecting the actual files)

Sequence backtesting needs annual UK **equity total returns** (dividends reinvested — roughly *half* of long-run
UK equity return, so this is not optional), **gilt/bond total returns**, and **inflation**, going back far enough
to include 1929/1937, the 1973–74 crash + 1970s stagflation (the UK worst case: equities circa −70% real across
1973–74), 2000 and 2008. **There is no ONS asset-return series** — ONS owns prices/inflation and demography.

**Important correction (verified by downloading the files):** I originally recommended the Bank of England
millennium dataset as the official, shippable source. I downloaded and inspected **BoE v3.1** (sheet "A31.
Interest rates & asset ps", plus the datahub CSV mirror) and it **does not contain equity total returns or a
dividend-yield series** — only a **share price index** (capital return) and **bond yields**. A price-only equity
backtest would omit dividends and so materially understate returns / overstate the chance of ruin. **BoE alone
cannot produce an accurate backtest.** The realistic sources:

| Source | Equity **total** return? | Official? | Licence | Notes |
|---|---|---|---|---|
| **BoE "Millennium of Macroeconomic Data" v3.1** | **No** — share *price* index + bond *yields* only | Yes (central bank) | **OGL v3.0** (fully shippable, incl. commercial) | Bond total return is reconstructable from yields; equity dividends are missing (verified). CPI to 2016. |
| **Jordà–Schularick–Taylor Macrohistory ("Rate of Return on Everything") R6** | **Yes** — equity total return, capital gain, dividend yield; bond + bill total return; CPI; UK 1870–2020 | Academic (peer-reviewed, QJE 2019) | **CC BY-NC-SA 4.0 — NON-commercial + ShareAlike** | Free + accurate + citable. Fine for a **private** tool; **risky for a free *public* release** (NC forbids commercial-provider integration; ShareAlike is sticky). |
| **ONS** inflation (RPI 1947–, CPIH/CPI) | n/a | Yes | OGL | Inflation leg to the present; cross-check / extend the CPI. |
| **DMS Yearbook (UBS)** / **Barclays Equity Gilt Study** | Yes (gold standard; what Timeline uses) | Commercial | **Paid, not redistributable** | Best data, but licence-blocked for us. |

**The tension is three-way, not a clean win:** *official + fully-shippable* (BoE, but no equity total return),
*accurate + free-for-personal* (JST macrohistory, but non-commercial so risky if the tool goes public), or
*accurate + paid* (DMS/Barclays). Given the tool is **personal-use today** (the whole `compliance.personal_use`
posture), the pragmatic recommendation is:

- **Now (personal use): JST macrohistory R6** for UK equity/bond/bill **total returns** + dividend yield + CPI
  (accurate, free for non-commercial use, peer-reviewed), extended for recent inflation with **ONS**. Cite both
  with `verified_on`. **Flag the licence as a public-release blocker** the same way `compliance.personal_use`
  flags the regulatory line: before any public release, swap the source to **BoE (bond TR from yields) + a
  fully-licensed equity total-return / dividend series**, or license DMS, or drop the shipped historical numbers.
- **Alternative if Rob wants strictly OGL even now:** BoE bond total return from yields + BoE equity *price*
  index plus a **documented dividend-yield assumption** (an approximation, flagged) — fully shippable but less
  accurate than JST's measured dividends.

**Engine work (unchanged either way):** a new fixed-sequence `PathDraws` driver feeding a specific historical
path of real returns/inflation into the existing `PathProjector`, plus the chosen dataset baked in as a **sourced
PHP data class** (the engine is no-I/O, so no runtime file read — like the tax figures), each series cited.

**Engine work required:** a new fixed-sequence entry point. The deterministic forecaster uses *expected*
returns; the Monte Carlo draws *random* returns. Backtesting needs a third driver that feeds a **specific
historical path** of returns/inflation into the same `PathProjector` (which already takes `PathDraws`), so a
"retire in year Y" run reuses the exact tax/spend/mortality engine. This is the code; the data is the gate.

## 3. Rob's follow-up — care-cost + ONS-refresh "from ONS"

- **ONS-refresh script: fully ONS.** ✅ The engine's `CohortLifeTable` maps directly onto **ONS national life
  tables** (annual, 3-year rolling) and **"Past and projected period and cohort life tables"** (biennial,
  currently 2020-based; a 2022-based release exists), downloadable as xlsx by single year of age 0–100 under
  OGL. Build: ingest the ONS cohort qₓ, diff against our current table, surface the changes. No non-ONS source
  needed.
- **Care-cost stochasticity: only *partly* ONS — this is a genuine gap.** ONS supplies useful pieces but **not
  the two numbers care modelling turns on:**
  - ONS **does** publish "Care homes and estimating the self-funding population, England" (self-funder
    proportions/counts, from the CQC provider returns) and **health-state / disability-free life expectancy**
    (relevant to *when* care starts).
  - ONS does **not** publish care-home **weekly fees** or the **probability / duration** of needing care.
    Those are:
    - **Weekly fees** — **LaingBuisson** "Care of Older People" (the sector-standard survey; ~£1,300/wk
      residential, ~£1,600/wk nursing self-funder, 2025/26), or the **CMA** care-homes market study, or the
      gov.uk charging-reform impact assessments.
    - **Probability / duration** — **PSSRU / CPEC (LSE)** modelling (the canonical UK figures: roughly 1 in 4
      older people need residential care; typical length-of-stay distributions).
  So a credible care-cost model can anchor **entry timing** on ONS health-state life expectancy but must take
  the **fee level** and **need probability/duration** from LaingBuisson + PSSRU (each cited with `verified_on`).
  A strictly ONS-only care model would be materially incomplete. **Needs Rob's decision** (see below).

## Open decisions (nothing built yet)

- [ ] **Stress-test data source (REOPENED after verifying the files — BoE has no equity total return).** Choose:
      **(1)** JST macrohistory R6 (accurate total returns, free-for-personal, but CC BY-NC-SA → flag as a
      public-release blocker) — recommended for the personal tool now; **(2)** BoE OGL, equity total return
      approximated as price index + a documented dividend-yield assumption (fully shippable, less accurate);
      **(3)** license DMS/Barclays; **(4)** PRIIPs-style synthetic stress instead of a real backtest.
- [ ] **Care-cost sources** — ONS cannot supply care fees or need-probability/duration. Accept **LaingBuisson
      (fees) + PSSRU (probability/duration)** as cited non-ONS sources (with ONS health-state life expectancy
      for timing), or restrict care-cost to the ONS-only pieces (incomplete)?

## Sources
- Bank of England research datasets ("A Millennium of Macroeconomic Data" v3.1, OGL v3.0): https://www.bankofengland.co.uk/statistics/research-datasets
- ONS national life tables: https://www.ons.gov.uk/peoplepopulationandcommunity/birthsdeathsandmarriages/lifeexpectancies/datasets/nationallifetablesunitedkingdomreferencetables
- ONS past and projected period and cohort life tables: https://www.ons.gov.uk/peoplepopulationandcommunity/birthsdeathsandmarriages/lifeexpectancies/bulletins/pastandprojecteddatafromtheperiodandcohortlifetables/2020baseduk1981to2070
- ONS care homes / self-funding population: https://www.ons.gov.uk/peoplepopulationandcommunity/healthandsocialcare/socialcare/articles/carehomesandestimatingtheselffundingpopulationengland/2022to2023
- FCA COBS 13 Annex 2 (projections): https://handbook.fca.org.uk/handbook/COBS/13/Annex2.html
- FCA/PRIIPs RTS Annex IV (performance + stress scenarios): https://www.handbook.fca.org.uk/techstandards/PRIIPs/2017/reg_del_2017_653_oj/annex04.html
- Timeline (historical backtesting; DMS Yearbook): https://timelineapp.co/authors/abraham-okusanya
- Jordà–Schularick–Taylor Macrohistory database, "Rate of Return on Everything" R6 (CC BY-NC-SA 4.0): https://www.macrohistory.net/database/
