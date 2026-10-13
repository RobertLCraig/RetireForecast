# Research: a sourced national earnings growth figure for the triple lock's earnings limb

*Researched 2026-10-11 for board card 0100 (the triple lock is modelled without its earnings limb). All sources read 2026-10-11.*

## The question

`StatePensionUprating::increase()` raises the State Pension by the higher of the year's inflation and
the 2.5% floor. The triple lock also has a third limb, average earnings growth, and the engine has no
national earnings series to model it with. (`AssumptionSet::$salaryGrowth` is the household's own pay,
and the card rules it out.) The engine works in real terms against a CPI draw: salary is escalated as
`(1 + real) * (1 + infl) - 1` in `PathProjector::growState`. So the figure needed is **national average
weekly earnings growth in real terms, deflated by CPI**, as a long-run annual mean, with a source and a
`verified_on` date. It is needed for each shipped set: `fcaDefault()` (the default), `obrBoeAnchored()`
and `dmsHistorical()`.

House rule: a higher earnings figure raises the State Pension, so the default is the **lowest
defensible** figure, and the reader can edit it.

## Findings

### 1. What the triple lock actually uses

- **The measure.** DWP: "The AWE figures used for benefit up-rating in April are the May to July
  seasonally adjusted total pay figures" and "the reference month for up-ratings based on earnings is
  the previous July." That is ONS Average Weekly Earnings, whole economy, **total pay (including
  bonuses)**, the three-month average for May to July against a year earlier (ONS series KAC3).
  Source: DWP, *Abstract of DWP benefit rate statistics 2025*, published 28 January 2026,
  https://www.gov.uk/government/statistics/abstract-of-dwp-benefit-rate-statistics-2025/abstract-of-dwp-benefit-rate-statistics-2025 (read 2026-10-11).
- **The latest uprating.** For April 2026 the full new State Pension went from £230.25 to £241.30 a
  week, which is +4.80%: https://www.gov.uk/government/publications/benefit-and-pension-rates-2026-to-2027/proposed-benefit-and-pension-rates-2026-to-2027 (read 2026-10-11).
  The written ministerial statement of 26 November 2025 says that 4.8% is the earnings figure for May to
  July 2025, and that September 2025 CPI was 3.8%. I read this statement through search summaries only:
  Hansard (https://hansard.parliament.uk/html/Commons/2025-11-26/WrittenStatements) refused a direct
  fetch with HTTP 403. The gov.uk rate table confirms the 4.8% arithmetic.
- **Revisions.** DWP uprates on the published May to July figure, and ONS revises that figure later.
  The current ONS series shows 4.9% for May to July 2025 (KAC3, release of 15-09-2026), where 4.8% was
  used. The difference is immaterial for a long-run mean.
- **The next uprating.** In the ONS release of 15-09-2026, KAC3 (total pay, three-month year-on-year)
  is **3.9%** for May to July 2026, and KAI9 (regular pay) is 3.5%. Because September 2026 CPI is not
  yet published, April 2027 is not yet settled. Series CSV: https://www.ons.gov.uk/generator?format=csv&uri=/employmentandlabourmarket/peopleinwork/earningsandworkinghours/timeseries/kac3/lms (downloaded 2026-10-11).
- **Is it legal or political?** The earnings link is statutory (Social Security Administration Act
  1992 s.150A). The CPI and 2.5% limbs are government policy. ASSUMPTIONS.md section 19 already says
  this. I did not re-check legislation.gov.uk for it.

### 2. ONS Average Weekly Earnings: the long-run record

I worked these out from the ONS series files downloaded 2026-10-11. The AWE series starts in
January 2000, so 2000 to 2025 (annual averages of monthly levels) is the full record.

- Regular pay level, whole economy, SA (KAI7): https://www.ons.gov.uk/generator?format=csv&uri=/employmentandlabourmarket/peopleinwork/earningsandworkinghours/timeseries/kai7/lms. Annual average £296 in 2000, £679 in 2025.
- Total pay level, whole economy, SA (KAB9): https://www.ons.gov.uk/generator?format=csv&uri=/employmentandlabourmarket/peopleinwork/earningsandworkinghours/timeseries/kab9/lms. £313 in 2000, £728 in 2025.
- CPI index, 2015=100 (D7BT, release of 16-09-2026): https://www.ons.gov.uk/generator?format=csv&uri=/economy/inflationandpriceindices/timeseries/d7bt/mm23. 72.7 in 2000, 138.4 in 2025.

Compound annual growth, using CPI as the deflator (the engine's own deflator):

| Window | Regular pay, nominal | Total pay, nominal | CPI | **Regular pay, real** | **Total pay, real** |
|---|---|---|---|---|---|
| 2000-2025 (full record) | 3.38% | 3.43% | 2.61% | **0.75%** | **0.80%** |
| 2000-2008 (pre-crash) | 4.00% | 4.17% | 1.93% | 2.03% | 2.20% |
| 2008-2025 (post-crash) | 3.09% | 3.09% | 2.93% | **0.15%** | **0.15%** |
| 2000-2019 (pre-Covid) | 2.85% | 2.89% | 2.09% | 0.74% | 0.78% |
| 2015-2025 | 4.13% | 4.21% | 3.30% | 0.80% | 0.88% |

Real regular pay growth year by year, 2001-2025: mean 0.76%, median 1.38%, standard deviation 1.82
points. The figure was negative in 2009-2014 and in 2017, 2022 and 2023. Over the full record, total pay
(the series the triple lock uses) beat regular pay by about 0.05 points a year, so which series you take
barely matters for a long-run mean.

ONS's own real-terms figures for the latest quarter (May to July 2026) were cited through search
summaries of the ONS bulletin, because ONS pages refused WebFetch: real regular pay growth was 0.6% on
CPIH and 0.8% on CPI. Bulletin: https://www.ons.gov.uk/employmentandlabourmarket/peopleinwork/employmentandemployeetypes/bulletins/averageweeklyearningsingreatbritain/september2026. The CSV series above came straight from ONS and were the basis for every number in the table.

### 3. OBR: the forecast and the long-run assumption

- **EFO, March 2026** (https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/, read 2026-10-11):
  - Para 2.29: "Nominal weekly wage growth is expected to slow to around 3½ per cent in 2026 and then
    average 2¼ per cent a year". The rest of the forecast runs to 2030-31.
  - Para 2.30: "Nominal hourly earnings growth is forecast to be around 2½ per cent a year in the medium
    term". "Real hourly earnings growth is expected to average about ½ a per cent a year over the medium
    term". Weekly grows more slowly than hourly because the ageing population lowers average weekly
    hours.
  - CPI: 3.4% in 2025, 2.3% in 2026, then **2.0% from 2027 onwards**.
  - So the forecast's medium-term real weekly earnings growth is 1.0225 / 1.020 - 1 = **0.25% a year**.
    The triple lock indexes to weekly AWE, not hourly, so this is the relevant figure.
  - Detailed tables (not opened): https://obr.uk/download/march-2026-economic-and-fiscal-outlook-detailed-forecast-tables-economy/
- **Fiscal risks and sustainability, July 2026** (https://obr.uk/frs/fiscal-risks-and-sustainability-july-2026/, read 2026-10-11),
  Table 2.1, "Summary of baseline long-term demographic and economic assumptions":
  - Labour productivity growth: **1.4%** in the 2026 FRS, 1.5% in the 2024 FRS, 0.9% on the March 2026
    EFO average. It is the "OBR assumption". The executive summary says: "long-term average productivity
    growth of 1.4 per cent over the next 50 years".
  - Average earnings growth: **3.7%** nominal, which is the "sum of labour productivity and GDP deflator
    growth". GDP deflator growth is 2.2%. The March 2026 EFO average is 2.4% nominal.
  - So the OBR's long-run real earnings growth is about 1.4 to 1.5% a year against the GDP deflator. The
    table has no CPI row. Against a 2% CPI it is about 1.7%.
  - On the triple lock, the OBR projects State Pension spending of about 9% of GDP partly because of
    "the cost of triple-lock uprating (calculated based on historical inflation and earnings
    volatility)", against about 7% if the pension were uprated with earnings alone.

### Sourced alternatives, lowest first (all real, against CPI)

| Figure | Source | Note |
|---|---|---|
| -0.03% | ONS AWE 2010-2019 | A window picked to give a low number. Not defensible as a long-run mean. |
| 0.15% | ONS AWE 2008-2025 | The weakest sustained real-wage period in the record. Looking back, it is the adverse case. |
| **0.25%** | OBR EFO March 2026, weekly 2¼% nominal against 2% CPI | The official forward-looking forecast for the measure the triple lock uses. |
| 0.5% | OBR EFO March 2026, real hourly | Hourly. Not the triple lock's measure. |
| **0.75%** (regular) / 0.80% (total) | ONS AWE 2000-2025, full record | The full history of the series. |
| ~1.5% (GDP deflator) / ~1.7% (CPI) | OBR FRS July 2026 | The 50-year baseline, tied to productivity. This is the optimistic end. |

## Recommended figure for the build

- **AssumptionSet property:** `nationalEarningsRealGrowth` (a `Percent`, real, annual, against CPI).
  The name follows `careCostRealGrowth`.
- **Override key:** `nationalEarningsGrowth`. Add it to `AssumptionOverrides::KEYS`, next to
  `careCostGrowth`, which is the same pattern.
- **FigureSource label:** 'Real national average weekly earnings growth'.
- **verified_on:** `2026-10-11`.

| Set | Value | Why | Source constant (new) |
|---|---|---|---|
| `fcaDefault()` (default) | **0.25%** | The lowest defensible forward-looking figure: the OBR's own medium-term weekly-earnings forecast, net of the 2% CPI this set uses. 0.15% (ONS 2008-2025) is lower only through a chosen window, and it is 0.1 points away. | `OBR_EARNINGS_SOURCE` |
| `obrBoeAnchored()` | **0.25%** | The same: the set is anchored to the March 2026 EFO. | `OBR_EARNINGS_SOURCE` |
| `dmsHistorical()` | **0.75%** | This set reads macro means off the full long-run record, so it takes the full AWE record (2000-2025). It uses regular pay, the lower of the two series. | `ONS_EARNINGS_SOURCE` |

Paste-ready source strings:

```php
public const OBR_EARNINGS_SOURCE = 'OBR Economic and Fiscal Outlook, March 2026, paras 2.29-2.30: nominal weekly '
    .'earnings growth averaging 2.25% a year in the medium term against CPI at 2.0% from 2027, so about '
    .'0.25% real. The OBR long-run (FRS July 2026) baseline of about 1.5% real is the optimistic end: '
    .'https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/';

public const ONS_EARNINGS_SOURCE = 'ONS Average Weekly Earnings, whole economy regular pay (KAI7) deflated by '
    .'CPI (D7BT), 2000-2025 annual averages: 0.75% a year real (total pay KAB9: 0.80%; 2008-2025: 0.15%): '
    .'https://www.ons.gov.uk/employmentandlabourmarket/peopleinwork/earningsandworkinghours/timeseries/kai7/lms';

public const NATIONAL_EARNINGS_VERIFIED_ON = '2026-10-11';
```

`economicSourcing()` takes a single `$macroSource`. Pass the earnings source as a second argument, or
pick it per set inside the builder (the DMS set gets ONS, the others get OBR), so that
`EconomicAssumptionSourcingTest`'s constructor enumeration still finds a citation for the new property.

### What the build session should change

1. `AssumptionSet`: add the property and accessor next to `careCostRealGrowth`. Set the three values in
   `AssumptionSetLibrary`, and add the FigureSource row and constants above.
2. `AssumptionOverrides::KEYS`: add `nationalEarningsGrowth`, mapped onto the new property the way
   `careCostGrowth` maps onto `careCostRealGrowth`.
3. `PathDraws`: add `nationalEarningsRealGrowth()`, copying `careCostRealGrowth()` in Deterministic,
   HistoricalSequence and Sampled. Keep it deterministic, with no volatility of its own. That is a stated
   simplification, mirroring the per-person salary override.
4. `StatePensionUprating::increase(float $inflation, float $earningsReal, int $calendarYear, ?int $untilYear)`:
   - While the lock applies, return `max($inflation, (1 + $earningsReal) * (1 + $inflation) - 1, floor)`.
   - Under `Inflation`, and after `TripleLockUntil` ends, keep returning `$inflation` alone. Without the
     lock, the State Pension follows prices, because that is how the existing cases are defined. Card
     0099 owns that choice.
   - Rewrite the docblock that names the gap.
5. Call it from `PathProjector::growState` with the draw. Also update the comment near the top of the
   projector that describes the uprating.
6. `ResultPresenter::assumedFigures()`: replace the "earnings limb is not modelled" sentence with one
   that names the value, e.g. "national earnings are assumed to grow 0.25% a year above CPI (OBR March
   2026 forecast)". Read the value from the set; do not restate it.
7. `docs/spec/ASSUMPTIONS.md` section 19: delete the "earnings limb is not modelled" bullet and add the
   figure, the three per-set values, the sources and the alternatives table above.
8. Bump `ScenarioForecaster::ENGINE_VERSION`.

**What to expect from the change, so a test can pin it.** With a positive real figure, the earnings limb
is always at or above CPI, so the lock becomes `max(infl + ~real, 2.5%)`. In the deterministic default
(2% CPI, 0.25% real) the earnings limb gives 2.255%, so the **2.5% floor still binds**. The default
projection therefore changes only in stochastic paths where inflation is above about 2.24%, where it
adds 0.25 points to that year's rise. Under the DMS set (3% CPI, 0.75% real) the earnings limb binds
every year at about 3.77%. Card AC #1 needs a fixture where the earnings limb beats prices and the
floor, e.g. 2% inflation and 1% real gives 3.02% > 2.5%.

## What could not be found or confirmed

- The verbatim text of the 26 November 2025 written ministerial statement: Hansard returned HTTP 403.
  The 4.8% is confirmed through the gov.uk rate table (230.25 to 241.30).
- The ONS bulletin pages could not be fetched (domain blocked for WebFetch). Every ONS number above
  comes from the ONS time-series CSV files, which downloaded directly, apart from the CPIH and CPI real
  figures for the latest quarter, which came from search summaries.
- The year-by-year earnings rows of the OBR detailed forecast tables (xlsx) were not opened. The
  medium-term figure comes from the EFO's own paragraph text.
- s.150A was not re-read on legislation.gov.uk.
