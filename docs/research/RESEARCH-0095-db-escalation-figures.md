# Research: published sources for the DB escalation figures

*Researched 2026-10-11 for board card 0095 (the RPI-over-CPI wedge and the default fixed escalation rate have no published source). All sources read 2026-10-11.*

## The questions

Two constants in `packages/finance-engine/src/Dto/` compound on guaranteed defined-benefit income for the whole plan, and neither cites anything:

- `PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` = **0**. `increase()` returns `$inflation + RPI_OVER_CPI_WEDGE_BPS / 10_000` for `Rpi`. The docblock argues that RPI is aligned with CPIH from February 2030, so the two "agree" for most of a plan.
- `DbPension::DEFAULT_FIXED_ESCALATION_BPS` = **300**. It is used when a scheme is on `Fixed` and the reader gave no rate. The claim is "3% and 5% are the two rates scheme rules commonly grant, and 3% is the lower."

The card asks three questions:

1. Is the RPI-to-CPIH alignment really February 2030, and what does it do?
2. What is the published RPI-over-CPI wedge before and after 2030? Should the build use no wedge, a flat one, or one that changes by year? Which of those is cautious for RPI-linked income?
3. What is the published distribution of fixed increase rates in UK DB scheme rules, and does it support 3%?

## Findings

### 1. The RPI-to-CPIH alignment: confirmed for February 2030, but RPI moves to CPIH, not to CPI

- HM Treasury and the UK Statistics Authority published their response to the RPI methodology consultation on **25 November 2020**, alongside the Spending Review. The consultation page says: "The change proposed can legally and practically be made by UKSA in February 2030." The Chancellor declined consent to any change "before the maturity of the final specific index-linked gilt in 2030". Source: https://www.gov.uk/government/consultations/a-consultation-on-the-reform-to-retail-prices-index-rpi-methodology (read 2026-10-11).
- What the change does, from the response document itself (*A Response to the Consultation on the Reform to Retail Prices Index (RPI) Methodology*, paras 14-16 and 8): the Authority will bring "the methods and data sources of CPIH into the RPI in February 2030". On 20 November 2020 the Authority Chair told the Chancellor it "would be able to legally and practically implement its proposal to the RPI in February 2030". After the change, "the RPI and CPIH will continue to be calculated separately … and will be published as separate indices". The government "will not offer compensation" to index-linked gilt holders. Para 19 says some DB pension scheme members will be affected. Source: https://assets.publishing.service.gov.uk/media/5fbd3b86d3bf7f5735e29b43/RPI_Response_FINAL_VERSION_.pdf (read 2026-10-11).
- The OBR describes how this works in practice: "monthly growth rates in CPIH will be applied to RPI from February 2030, with the annual RPI and CPIH inflation rates fully aligning from February 2031 onwards." Source: OBR, *Economic and fiscal outlook – October 2024*, Box 2.3, https://obr.uk/box/the-long-run-difference-between-rpi-and-cpi-inflation/ (read 2026-10-11).

**This corrects the shipped reasoning.** After 2030, RPI follows **CPIH**, not CPI. CPIH is CPI plus owner-occupier housing costs and council tax. The OBR expects CPIH to run above CPI over the long run. So the wedge does **not** go to zero after 2030. It gets smaller, but it stays positive. The docblock's line that "the two agree" past 2030 is wrong. So is the results-page sentence in `ResultPresenter::assumedFigures()` that RPI and CPI "are the same for nearly all of it".

### 2. The RPI-over-CPI wedge, year by year (OBR, March 2026)

**The long-run figure.** In October 2024 the OBR cut its long-run estimate of the RPI-CPI wedge from "around 0.9 percentage points" to "around 0.4 percentage points". That is the CPIH/RPI-over-CPI wedge after alignment. It comes from long-run CPI of 2.0%, rents rising with earnings at about 3.8%, and CPIH weights (CPI 81%, council tax 3%, owner-occupier costs 16%). Earnings growth 0.5 points higher or lower moves the wedge by about 0.1 points. The OBR also said the CPIH-CPI wedge would "fall gradually from 2026 onwards … reaching 0.1 percentage points in the final years of the forecast" and then climb back to the long-run figure. Source: Box 2.3 above (read 2026-10-11).

**The latest figures.** The latest EFO is March 2026; on 2026-10-11 https://obr.uk/efo/ redirected to it. Its long-term determinants were published on 28 May 2026 as *Long-term economic determinants – March 2026 Economic and fiscal outlook* (XLSX). Release page: https://obr.uk/supplementary-forecast-information-release-long-term-economic-determinants-march-2026/. File: https://obr.uk/download/long-term-economic-determinants-march-2026-economic-and-fiscal-outlook/ (read 2026-10-11). Footnote 3 of the file says: "from Q1 2030 onwards our forecast assumes RPI grows in line with CPIH … Annual growth rates in RPI and CPIH will fully align after the transition year, in February 2031."

The wedge is RPI minus CPI, in percentage points, worked out from that sheet's CPI and RPI rows (fiscal years):

| Year | CPI % | RPI % | Wedge (pp) |
|---|---|---|---|
| 2026-27 | 2.01 | 2.86 | 0.84 |
| 2027-28 | 1.95 | 2.94 | 0.99 |
| 2028-29 | 2.04 | 2.81 | 0.78 |
| 2029-30 | 2.00 | 2.89 | 0.89 |
| 2030-31 | 2.00 | 2.08 | 0.08 |
| 2031-32 | 2.00 | 2.21 | 0.21 |
| 2032-33 | 2.00 | 2.27 | 0.27 |
| 2033-34 | 2.00 | 2.32 | 0.32 |
| 2035-36 | 2.00 | 2.34 | 0.34 |
| 2038-39 | 2.00 | 2.36 | 0.36 |
| 2041-42 to 2075-76 | 2.00 | 2.39 | 0.39 |

The sheet's "Growth rate assumptions" tab puts long-run CPI at 2.0 and RPI at 2.3855, "In line with CPIH". That gives a long-run wedge of **0.3855 pp, about 39 bp**.

**Which wedge is cautious.** For an RPI-linked pension the household receives, a lower wedge means lower income, so a lower wedge is the cautious choice. The three options:

- **0 (shipped).** This is the strict lower bound, but no published source supports it. The OBR's wedge is positive in every year to 2075-76, and it is never below 0.08 pp. Keeping 0 understates an RPI pension by about 0.4% a year for ever, plus about 0.9 pp a year before 2030. Over thirty years that is roughly a 15% shortfall in that pension's income. The house rule asks for the lower **defensible** figure, and zero is not defensible: the source contradicts it in every year. So the house rule does not argue for keeping 0. Keeping it would be a choice to understate, not a reading of the data.
- **A flat 39 bp (recommended).** This is the OBR's published long-run wedge. For a plan starting in 2026-27, the running total of a flat 39 bp stays **below** the OBR's running total at every horizon. The gap is +0.45 pp after year 1, peaks at +1.94 pp after year 4, and is still about +1.0 pp at year 15. The two match after that, because the OBR path sits at 0.39 from 2041-42. So flat 39 bp never makes an RPI pension higher than the OBR path would. It gives up the pre-2030 wedge, which is the cautious direction, and it still models a real gap. One known limit: a plan that starts in 2030-31 or later would be slightly overstated in the transition years (0.08 to 0.38 against 0.39). This app projects from today, so that case does not arise yet.
- **A wedge that changes by year (most accurate).** This would use about 85 bp before February 2030, about 8 bp in 2030-31, and then climb to 39 bp. But `PensionEscalationBasis::increase(float $inflation, float $fixedRate)` takes no year. Building this means adding a year parameter through the projector. That is a mechanism change, which this card rules out ("This card only replaces numbers"). It would also make RPI pensions higher than the flat figure does, so it is less cautious. Do not build it under this card.

### 3. Fixed increase rates in UK DB scheme rules (PPF Purple Book 2025)

The latest edition is *The Purple Book 2025*. It uses scheme data as at 31 March 2025, and https://www.ppf.co.uk/purple-book lists no 2026 edition. Source PDF: https://www.ppf.co.uk/-/media/PPF-Website/Public/Purple-Book-Data-2025/Pension-Protection-Fund-Purple-Book-2025-accessible.pdf (read 2026-10-11), chapter 3, Figures 3.13 and 3.14 (PDF page 12).

- Figure 3.13 covers pension indexation for benefits built up **before 6 April 1997**: "More than three quarters of schemes provide indexation … The most common increase types were a fixed three per cent a year, and RPI inflation capped at five per cent a year." Its sub-table "Fixed non-nil increases", as a percentage of schemes:

  | Fixed rate | Share of schemes |
  |---|---|
  | 2.5% | 1% |
  | **3%** | **18%** |
  | 4% | 1% |
  | 5% | 9% |
  | Other | 1% |
  | Total fixed non-nil | 30% |

- For benefits built up **after 5 April 1997**: "Around 85 per cent of schemes provide indexation of capped inflation … the most common cap is five per cent a year." The PDF text did not give a clean share of fixed increases for post-1997 benefits, and this note does not state one.
- Basis of the figures: scheme returns. Where a scheme has more than one rate, it reports the rate that applies to the largest share of its protected liabilities. Discretionary increases are excluded.

So 3% is the most common fixed rate, held by 18 of the 30 percentage points of schemes that grant a fixed increase. 5% is second at 9 points, so "3% and 5% are the two common rates" holds. A lower rate does exist: 2.5% at 1% of schemes. It is too rare to be a fair default, so **3% is both the most common rate and the lower of the two common ones**, and it stands. I found no TPR publication with a fixed-rate breakdown. The Purple Book is the PPF's own data, so none was needed.

## Recommended figures for the build

| Constant | Value | Source URL | verified_on |
|---|---|---|---|
| `PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` | **39** (was 0) | https://obr.uk/download/long-term-economic-determinants-march-2026-economic-and-fiscal-outlook/ (long-run RPI 2.3855% less CPI 2.0%), backed by https://obr.uk/box/the-long-run-difference-between-rpi-and-cpi-inflation/ | 2026-10-11 |
| RPI-to-CPIH alignment date (cited, not a constant) | February 2030; annual rates fully aligned from February 2031 | https://www.gov.uk/government/consultations/a-consultation-on-the-reform-to-retail-prices-index-rpi-methodology and https://assets.publishing.service.gov.uk/media/5fbd3b86d3bf7f5735e29b43/RPI_Response_FINAL_VERSION_.pdf | 2026-10-11 |
| `DbPension::DEFAULT_FIXED_ESCALATION_BPS` | **300** (unchanged) | https://www.ppf.co.uk/-/media/PPF-Website/Public/Purple-Book-Data-2025/Pension-Protection-Fund-Purple-Book-2025-accessible.pdf (Fig. 3.13: fixed 3% 18% of schemes, 5% 9%, 2.5% 1%) | 2026-10-11 |

### What the build session should change

Work on `master` in `C:\Dev\RetireForecast`.

1. **`packages/finance-engine/src/Dto/PensionEscalationBasis.php`**
   - Set `RPI_OVER_CPI_WEDGE_BPS = 39`.
   - Rewrite its docblock with these points:
     - RPI takes CPIH methods and data from February 2030 (HMT/UKSA response, 25 November 2020).
     - RPI follows CPIH, not CPI, so the wedge narrows but stays positive.
     - 39 bp is the OBR March 2026 long-run RPI-minus-CPI figure.
     - A flat 39 bp stays at or below the OBR year-by-year path's running total at every horizon, for a plan starting 2026-27. It gives up the pre-2030 wedge of about 0.8-1.0 pp, which is the cautious direction.
     - The year-by-year schedule is not modelled, because `increase()` takes no year.
   - Cite both URLs and "verified 2026-10-11". Remove the "no citation" and "could not be verified" sentences.
2. **`packages/finance-engine/src/Dto/DbPension.php`**
   - Keep `DEFAULT_FIXED_ESCALATION_BPS = 300`.
   - Replace "SOURCE: judgement, not a published series" with a PPF Purple Book 2025 citation (Fig. 3.13, benefits before 6 April 1997): fixed 3% at 18% of schemes is the most common fixed rate, 5% is next at 9%, and 2.5% is 1%. Add the PDF URL and "verified 2026-10-11".
3. **`packages/finance-engine/tests/Forecast/DbEscalationTest.php`**
   - `test_rpi_escalates_at_cpi_because_no_wedge_is_modelled` asserts the wedge is 0 and that RPI income equals CPI income at 2046. Both fail once the wedge moves. Rename it, for example to `test_rpi_escalates_at_cpi_plus_the_sourced_wedge`. Assert `assertSame(39, PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS)` so a later change is deliberate. Then assert that RPI income at 2046 is **above** CPI income, and close to CPI at (6% + 0.39%) compounded over the same years. Use the file's existing `compounded()` and `assertEqualsWithDelta` pattern; check whether `compounded()` takes the rate in percent before using it.
   - Update the docblock on `test_each_escalation_basis_reaches_a_different_twenty_year_income`, which says RPI "lands on CPI". RPI is still left out of that test's list, so no assertion changes are needed there.
4. **`app/Forecast/ResultPresenter.php`, inside `assumedFigures()`**
   - The RPI disclosure does **not** read the constant. It hard-codes "We increase it at CPI instead … the two are the same for nearly all of it, and we have no published figure for the gap", and all of that is now false.
   - Rewrite it to read `PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS` the same way the fixed-rate sentence reads its constant (`self::ratePct(Percent::fromBasisPoints(...)->asPercent())`).
   - Suggested text: "You set one of your defined-benefit pensions to increase with RPI. We increase it at CPI plus {$wedge} a year, the Office for Budget Responsibility's long-run gap between the two once RPI is brought into line with CPIH from 2030. Before 2030 the real gap is larger, about 0.8 to 1 point a year, so an RPI pension is modelled slightly LOW rather than slightly high, which is the direction we err in."
   - Also update the comment above the block, which says RPI "is modelled at CPI because no wedge between them is sourced".
   - For the fixed-rate sentence, "Scheme rules commonly grant 3% or 5%" is now sourced and can stay. Separately, its line "Over thirty years the difference between them roughly doubles the pension" overstates: 1.05^30 / 1.03^30 ≈ 1.78. Consider "adds roughly three quarters to the pension".
5. **`tests/Unit/Forecast/AssumedFiguresDisclosureTest.php`**
   - `test_an_rpi_basis_discloses_that_the_model_treats_it_as_cpi` checks only that the text contains 'RPI' and 'CPI', so it still passes. Rename it to match the new behaviour.
   - Add an assertion that the disclosure contains the wedge read from the constant (for example `0.39%`, in whatever form `ratePct` prints) and "2030". That makes the disclosure move with the constant, which is criterion #2.
   - Update the comment at the RPI test that says "The engine models no RPI-over-CPI wedge".
   - `test_an_assumed_fixed_db_escalation_rate_is_disclosed_with_its_value` needs no change, because the fixed rate is unchanged.
6. **`docs/spec/ASSUMPTIONS.md` §18**
   - Remove the "⚠️ SOURCING GAP, the sixth" block, and lower the gap count wherever the document keeps one.
   - In its place, state the following: RPI escalates at CPI + 0.39% (OBR March 2026 long-term determinants, long-run RPI 2.3855% against CPI 2.0%; RPI takes CPIH methods from February 2030 under the HMT/UKSA response of 25 November 2020). The pre-2030 wedge of about 0.8-1.0 pp is deliberately not modelled, which is cautious. The fixed default stays at 3%, the most common fixed rate in scheme rules (PPF Purple Book 2025 Fig. 3.13: 18% of schemes, against 9% at 5%).
   - Give each figure its URL and "verified_on: 2026-10-11". Remove "Confirm you are happy with RPI-as-CPI and 3% until that lands."
7. **`app/Forecast/ScenarioForecaster.php`**
   - Bump `ENGINE_VERSION`, for example to `'finance-engine/rpi-wedge-39bp-obr-2026'`, because RPI-linked DB income changes.
   - Then run `php artisan test` and `php artisan scenarios:audit` from PowerShell (Herd). Only scenarios with an RPI basis should move.

When the OBR publishes its next EFO, check the long-run RPI and CPI rows on the long-term determinants sheet. If RPI minus CPI moves by more than about 5 bp, update the constant and its verified_on date.
