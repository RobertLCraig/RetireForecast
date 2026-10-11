# Research: a published source for the service-charge escalation default

*Researched 2026-10-11 for board card 0085 (the service-charge escalation default has no published source). All sources read 2026-10-11.*

## The question

`ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` is shipped at **300** (CPI + 3.00% a year, real) and
compounds the `while_owning_home` bucket (service charge, ground rent, block levies) for every year of a
projection whenever the reader leaves the rate blank. Its only source is the property reviewer's judgement
in the 2026-08-19 expert review, with CPI + 1.5% as the optimistic sensitivity (ASSUMPTIONS.md §12). What
**published** figure supports a long-run above-CPI rate for UK block service charges, what are the sourced
optimistic and adverse alternatives, and what should the constant be?

## Findings

### 1. No official statistic measures service-charge inflation

- **The ONS CPI has no service-charge class.** In the full MM23 dataset (downloaded 2026-10-11 from
  https://www.ons.gov.uk/file?uri=/economy/inflationandpriceindices/datasets/consumerpriceindices/current/mm23.csv)
  the only indices under COICOP 04.4 are water supply (04.4.1, D7DR) and sewerage (04.4.3, D7DQ). The
  European class 04.4.4 "other services relating to the dwelling" (caretaking, communal cleaning, lifts,
  communal lighting) is not published for the UK. UK CPI also leaves out buildings insurance (12.5.2 is
  *house contents* insurance, D7F2), because owner-occupier costs sit outside CPI.
- **The nearest ONS components rose more slowly than CPI**, so they do not support an above-CPI rate. Annual
  averages, 2015 = 100, from the same file:

  | Series | 2015 | 2025 | Real a year, 2015-2025 |
  |---|---|---|---|
  | D7BT CPI all items | 100.0 | 138.4 | (the base) |
  | D7CF 04.3 regular maintenance and repair of the dwelling | 100.0 | 117.4 | about -1.6% |
  | D7F2 12.5.2 house contents insurance | 100.0 | 129.1 | about -0.7% |
  | D7CG 04.4 water and misc. dwelling services | 100.0 | 155.9 | not usable: driven by the 2025 water-bill reset, and a service charge does not cover water bills |

  (The real rates are my arithmetic on the ONS index levels.)
- **The English Housing Survey 2023-24 (accredited official statistics)** puts the mean annual service charge
  for leaseholders who pay one at £1,720 (median £1,375; flats mean £1,857). It says the average "has remained
  relatively stable in recent years, with no significant change over the past five years". That is flat in
  cash terms, which is a fall in real terms.
  https://www.gov.uk/government/statistics/english-housing-survey-2023-to-2024-leasehold-experience-fact-sheet/english-housing-survey-2023-to-2024-leasehold-experience-fact-sheet
  (page updated 7 July 2026; read 2026-10-11). It is a household survey of what people say they pay, so it
  catches change slowly and lags.

### 2. Hamptons' Service Charge Index is the only published multi-year series

Hamptons (an estate agency) builds its index from service charges on flats listed for sale in England and
Wales, starting in 2016. It is industry data, not a statistic, but it is published yearly, named and
repeatable. The primary pages are https://www.hamptons.co.uk/articles/2025-service-charge-index and
https://www.hamptons.co.uk/articles/service-charge-index-2024. Both returned HTTP 403 to an automated fetch,
so the figures below were read in trade-press reports that quote Hamptons and its lead analyst:

- **2025** (Property Industry Eye, 3 March 2026,
  https://propertyindustryeye.com/leaseholders-face-record-high-service-charges/; Landlord Today, 2 March 2026,
  https://www.landlordtoday.co.uk/breaking-news/2026/03/soaring-service-charges-on-leasehold-flats-hit-capital-values/):
  - England and Wales average £2,405 a year, up 4.6% in the year against CPI up 3.4%.
  - Five years (from £1,814 in 2020): up 32.6% against CPI up 30.9%.
  - Decade (2016-2025): up 55.6% against CPI up 39.8%.
  - London average £2,801: up 6.4% in the year, 41.2% over five years and 64.5% over the decade.
- **2024** (Mortgage Solutions, 10 February 2025,
  https://www.mortgagesolutions.co.uk/mortgage-news/2025/02/10/service-charge-costs-rise-11-to-average-2-3k-annually-hamptons/):
  up 11.0% to £2,300 against CPI up 2.5%, the largest annual rise since the index began. Hamptons put it down
  to wage and insurance costs. 2023 was up 4.3%.

Real rates as annual figures (my arithmetic: the ratio of the two growth figures, annualised; "decade" is
taken as 9 to 10 annual steps because the index starts in 2016):

| Hamptons window | Charge | CPI | Real a year |
|---|---|---|---|
| E&W, 1 year 2025 | +4.6% | +3.4% | +1.2% |
| E&W, 1 year 2024 | +11.0% | +2.5% | +8.3% (a one-year spike) |
| E&W, 5 years 2020-2025 | +32.6% | +30.9% | **+0.3%** |
| E&W, decade 2016-2025 | +55.6% | +39.8% | **+1.1% to +1.2%** |
| London, 5 years | +41.2% | +30.9% | +1.5% |
| London, decade | +64.5% | +39.8% | **+1.6% to +1.8%** |

### 3. Component evidence, which explains the gap but is not a whole-charge rate

- **Block buildings insurance (FCA, 21 September 2022):** industry data show the average premium for
  multi-occupancy residential buildings rose 125%, from £6,800 to £15,300, between 2016 and 2021.
  https://www.fca.org.uk/news/press-releases/fca-sets-out-potential-interventions-reform-multi-occupancy-buildings-insurance-market
  That is about +15% a year real across that window. It was a one-off repricing after Grenfell, and the FCA
  ties it to buildings with fire-safety risk. Retail home insurance has since levelled off and fallen: the
  ABI's average combined premium was £393 in Q1 2025 and £384 in Q3 2025 (ABI premium tracker, as reported
  by Which?, https://which.co.uk/news/article/whats-happening-to-home-insurance-premiums-a3EjJ5O6GVvP).
  This is retail cover, not block cover, and is given for direction only.
- **BCIS five-year facilities-management forecast (July 2026,** reported by Cleaning Mag on 9 July 2026,
  https://cleaningmag.com/news/bcis-reveals-updated-five-year-maintenance-cleaning-and-energy-forecast**):**
  over the five years to 1Q2031, maintenance costs +15.7%, cleaning costs +29.1% (driven by labour) and energy
  about -10%. Against the OBR's CPI path of 2.3% in 2026 and 2.0% a year after that (OBR *Economic and fiscal
  outlook*, March 2026, para 2.21, https://obr.uk/efo/economic-and-fiscal-outlook-march-2026/), that works out
  at about **+0.9% real a year for maintenance, +3.1% for cleaning and -4% for energy** (my arithmetic). The
  full BCIS briefing is paywalled. An earlier release (Construction Index, 9 October 2024,
  https://www.theconstructionindex.co.uk/news/view/bcis-sets-out-five-year-building-maintenance-forecast)
  had maintenance +16% and cleaning +23% over five years to 2029, so the picture holds across releases.

### 4. What this says about the shipped 3%

No published whole-charge series, official or industry, shows anything near **CPI + 3% sustained over
several years**. The highest multi-year whole-charge figure is London over the Hamptons decade, at about
+1.8% real. That decade includes the post-Grenfell insurance repricing and the 2022-24 wage and energy surge,
so it already reflects a bad stretch. A figure of 3% or more appears only in one component (BCIS cleaning
labour, five-year forecast) or in a single year (2024). Compounded over 30 years, 3% real multiplies the
charge by 2.43 in real terms. The sourced 1.8% gives 1.71 and 1.2% gives 1.43.

Sources disagree on the low end. Official sources (EHS, ONS components) suggest the charge rises at or below
CPI. The industry index says CPI + 0.3% over five years and CPI + 1.2% over ten. **The defensible range for a
long-run default is CPI + 0% to CPI + 1.8%.** The 2.5%-plus readings are short-run shocks. The model already
handles those better with the `while_owning_home` one-off cost (a Section 20 major-works demand) than with a
lifetime rate.

## Recommended figure for the build

House rule: costs take the higher of the defensible figures, and the figure stays user-editable.

- **Constant:** `ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` in
  `packages/finance-engine/src/Dto/ExpenseProfile.php`
- **Recommended value: `180`** (CPI + 1.8% a year). This is the adverse end of the sourced long-run range:
  Hamptons Service Charge Index, London flats, 2016-2025, up 64.5% against CPI up 39.8%.
- **Source URL to put beside it:** https://www.hamptons.co.uk/articles/2025-service-charge-index (figures as
  reported at https://propertyindustryeye.com/leaseholders-face-record-high-service-charges/ and
  https://www.landlordtoday.co.uk/breaking-news/2026/03/soaring-service-charges-on-leasehold-flats-hit-capital-values/)
- **verified_on:** 2026-10-11
- **Sourced optimistic alternative: CPI + 0.3%** (Hamptons, England and Wales, five years 2020-2025: up 32.6%
  against CPI up 30.9%). The EHS 2023-24 finding of "no significant change over the past five years" supports
  an even lower figure.
- **Sourced central figure: CPI + 1.2%** (Hamptons, England and Wales decade: up 55.6% against CPI up 39.8%).
- **Sourced stress (worth stating, not the default): about CPI + 3%.** This is the BCIS July 2026 cleaning-cost
  forecast (+29.1% over five years to 1Q2031 against the OBR's CPI path), and close to the reviewer's original
  figure. It is a harsh case for a block whose charge is mostly staff costs.

**Note for Rob.** This lowers the shipped default from 3% to 1.8%, so the forecast becomes less cautious than
it is now. The 1.8% is still the adverse end of what the published evidence supports, and 3% has no source
for a whole charge over several years. If you would rather keep 3% as a deliberate stress default, the
honest citation is the BCIS cleaning forecast, labelled as one component's five-year forecast.

### What the build session should change (no web needed)

1. `ExpenseProfile.php`: set `DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS = 180; // CPI + 1.80% a year`. Rewrite
   the docblock's "Source of record" paragraph to cite Hamptons Service Charge Index 2025 (London decade
   +64.5% against CPI +39.8%, about +1.8% a year real; England and Wales decade about +1.2%; five years about
   +0.3%), the URLs above and `verified_on 2026-10-11`. Note that the ONS CPI has no service-charge class and
   that the EHS 2023-24 shows charges flat in cash. Change "CPI + 1.5% is the optimistic sensitivity" to CPI
   + 0.3%. Remove the "primary citation is still owed (card 0085)" sentence.
2. `resources/views/livewire/scenario-builder.blade.php` (the step-4 property-cost growth help text, around
   the `propertyCostsGrowthDefaultPct()` call): replace "the cautious figure from the expert property review
   of 2026-08-19. Its optimistic case is **1.5%**" with wording such as: "the cautious end of what service
   charges have done over the last decade (Hamptons Service Charge Index 2025: London flats). The England and
   Wales average was about **1.2%**, the last five years about **0.3%**, and a staff-heavy block could see
   **3%**."
3. `tests/Feature/Livewire/ScenarioBuilderTest.php`,
   `test_the_property_cost_growth_input_offers_its_sourced_alternatives`: change `assertSee('1.5%')` to
   `assertSee('0.3%')` (and `1.2%` if it is added), and change `assertSee('expert property review of
   2026-08-19')` to `assertSee('Hamptons Service Charge Index')`.
   `test_a_blank_rate_escalates_the_bucket_at_the_engine_default` reads the constant and should need no edit.
   Search the tests for any hard-coded `300` or `3%` tied to this constant.
4. `docs/spec/ASSUMPTIONS.md` §12: change the heading to "CPI + 1.8% real ... verified_on 2026-10-11". Replace
   the "Why 3%" paragraph with the table in Finding 2 and the range in Finding 4 (adverse 1.8% London decade,
   central 1.2% England and Wales decade, optimistic 0.3% England and Wales five years, stress about 3% BCIS
   cleaning forecast). Note that no official series exists (ONS CPI has no 04.4.4; EHS shows charges flat in
   cash). Delete the "SOURCING GAP" warning. Keep one line saying the source is industry data from flats
   listed for sale, not an official statistic.
5. Bump `ScenarioForecaster::ENGINE_VERSION` and re-run the stored scenarios, because the figure moves.
6. Run `php artisan test --testsuite=Engine` and the builder feature test.

**Caveat to keep in the docs:** Hamptons measures charges on flats *listed for sale*, which may differ from
all charges paid, and its index only goes back to 2016. Revisit when Hamptons publishes its 2026 index
(usually February or March).
