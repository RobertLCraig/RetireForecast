# Research: a published source for the single-property volatility multiple

*Researched 2026-10-11 for board card 0086 (the single-property volatility multiple has no published source). All sources read 2026-10-11.*

## The question

`AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE` ships at **2.0**. `AssumptionSet::singlePropertyVolatility()`
multiplies the index house-price volatility by it (9% real a year on Sets A and C becomes 18%; Set B's 11%
becomes 22%), and the sampled shock is scaled by that multiple **every year**, so the extra spread compounds
like a random walk over the whole projection. Its only source today is the property reviewer's judgement in
the 2026-08-19 expert review (ASSUMPTIONS.md §13, `AssumptionSetLibrary::SINGLE_PROPERTY_SOURCE`). The
question is what published evidence says about how much more volatile one home is than the index, and
whether 2.0 is the right default under the house rule (when in doubt, ship the more cautious defensible figure).

## Findings

### 1. The "twice the index" figure is in the literature, credited to Case and Shiller

- Giacoletti's job-market paper says: "Seminal work by Case and Shiller (1988) highlights that prices of
  individual homes are twice more volatile than city-level house price indexes. Similar findings are later
  reported by Flavin and Yamashita (2002), Piazzesi et al. (2007) and Piazzesi et al. (2015)."
  M. Giacoletti, *Idiosyncratic Risk in Housing Markets*, job-market paper, January 2017,
  https://finance.unibocconi.eu/sites/default/files/files/media/attachments/MarcoGiacolettiJMP20170116082355.pdf
  (read 2026-10-11).
- Cheung, Galimberti and Vermeulen say: "Case and Shiller (1989) report that annual house price appreciation
  has a standard deviation of close to 15 percent for individual houses." *Evidence on the Determinants and
  Variation of Idiosyncratic Risk in Housing Markets*, University of Canterbury WP 13/2023, September 2023,
  https://repec.canterbury.ac.nz/cbt/econwp/2313.pdf (read 2026-10-11).
- I could not check the Case-Shiller originals myself. The NBER scans (https://www.nber.org/papers/w2393.pdf,
  https://www.nber.org/papers/w2506.pdf, both tried 2026-10-11) are image-only PDFs and could not be read. The
  2.0 therefore comes from secondary sources that agree with each other, not from a table I read.

### 2. How big the house-specific shock is, and that it does not compound

- **California, 2001-2012 (Giacoletti, JMP above).** He estimates the house-specific shock across Los Angeles,
  San Diego and San Francisco, controlling for metro index, ZIP code, house characteristics and remodelling.
  The transitory shock's standard deviation σu runs from **9.6% to 17.8%** per resale (Table 8: LA 13.2% in
  2001 to 16.9% in 2012; San Diego 9.6% to 12.2%; SF 13.5% to 17.8%). The persistent part is close to zero
  (ρ ≈ 0.002-0.005, σv ≤ 3.6%). His key finding: "house-specific risk does not scale with the (square root of
  the) holding period". As a result, idiosyncratic risk "can make up to 60% of the volatility of capital gains
  when a home is resold after one year. However, after five years, idiosyncratic volatility is at most 20% of
  total volatility." Annual real volatility of the Case-Shiller metro indexes in the same paper is "around 4%
  ... and around 5%".
- **Published version.** Giacoletti, *Review of Financial Studies* 34(8), 2021, 3695-3741,
  https://doi.org/10.1093/rfs/hhab033. I did not fetch the full text. Per the abstract mirror
  (https://academicnewsletter.sufe.edu.cn/info/352238, read 2026-10-11), the idiosyncratic component "is
  large", "does not follow a random walk", and makes Sharpe ratios holding-period dependent. Cheung et al.
  (above, footnote 5) quote the published version as finding "individual house price volatility of around
  8.6 percent annually for houses held over a five-year holding period, dropping to below 7 percent when a
  house is held longer than 15 years".
- **Paris and Amsterdam, long-run (Eichholtz, Korevaar, Lindenthal and Tallec).** "For one year, almost all
  of the total return variance is idiosyncratic, and that drops to approximately 50 percent as holding periods
  get longer." Over 20-year holds, an idiosyncratic share of about 50% of variance means one property is about
  √2 ≈ **1.4x** as volatile as the market. They also confirm the effect does not compound: Amsterdam's two-year
  variance is 0.06 and its 20-year variance about 0.3, "instead of 0.6". *The Total Return and Risk to
  Residential Real Estate*, Cambridge CRERC WP 2020-01 (published RFS 34(8), 2021),
  https://www.landecon.cam.ac.uk/sites/default/files/2023-05/crerc-wp-2020-01-lindenthal.pdf (read 2026-10-11).
- **New Zealand, around 1 million repeat sales (Cheung et al., above).** Table 1 compares the standard deviation
  of annualised 5-year returns measured from regional indexes against individual repeat sales. National:
  index 2.55%, repeat sales 6.63% (**2.6x**). Regions: Auckland 2.94 vs 5.92 (2.0x), Canterbury 3.44 vs 5.87
  (1.7x), Nelson 4.01 vs 5.32 (1.3x). "On average, index-based regional risk estimates are 2.6 p.p. lower than
  the risk estimates based on individual repeat sales." The paper also finds idiosyncratic risk is higher for
  cheaper houses (+7% at the 10th price percentile) and falls with holding period (about 2.3% per extra year).
- **US, 26 million sales.** Peng and Thibodeau, *Idiosyncratic Risk of House Prices: Evidence from 26 Million
  Home Sales*, Real Estate Economics 45(2), 2017,
  https://ideas.repec.org/a/bla/reesec/v45y2017i2p340-375.html (abstract only, read 2026-10-11). The risk is
  large, it varies by ZIP code, and it is not compensated by higher appreciation. I found no headline multiple
  in the abstract.

### 3. No UK estimate was found

I searched for UK or England-and-Wales repeat-sales work on individual-home dispersion against an index
(Land Registry, Bank of England, UCL, RICS) and found none. Land Registry repeat-sales and UK HPI work estimates
the index, not the residual around it. The Bank of England's 2026 house-price-at-risk paper
(https://www.bankofengland.co.uk/-/media/boe/files/working-paper/2026/developing-a-house-price-at-risk-framework-for-the-uk.pdf,
found 2026-10-11, not read in full) works at regional and national level. There is **no published UK
single-property multiple**. The evidence comes from US (California), New Zealand, and historic Paris and
Amsterdam data. The NZ finding that Auckland and San Diego have "similar levels of magnitude of idiosyncratic
risk" suggests the effect carries across countries, but that is a judgement, not a UK measurement.

### 4. What the evidence implies for the engine's 2.0, and why it depends on the horizon

The literature's main point is that the house-specific shock is roughly **one-off**: about 10-18% in total,
taken when the home is sold, not growing each year. The engine instead scales the yearly index shock by the
multiple, so over T years its single-home spread is 2 × 9% × √T. A one-off shock I gives an effective multiple
of √(1 + I² / (σ²T)), where σ is the 9% index volatility. Generic examples:

| Horizon | Index alone (9% × √T) | Engine at 2.0 | Giacoletti-type, I = 18% | NZ-type, I ≈ 30% at 5 years | Eichholtz long-run |
|---|---|---|---|---|---|
| 1 year | 9% | 18% (2.0x) | 20% (2.2x) | n/a | most variance idiosyncratic |
| 5 years | 20% | 40% (2.0x) | 27% (1.3x) | 36% (1.8x) | n/a |
| 20 years | 40% | 80% (2.0x) | 44% (1.1x) | n/a | about 1.4x |

(The NZ I ≈ 30% is the 6.1% annualised idiosyncratic spread, √(6.63² − 2.55²), times 5 years.)

So over the horizons the app projects (decades), every source puts the true multiple **between about 1.1x and
1.8x**. The only higher figures are the 1-year horizon (about 2.2x) and the NZ national 2.6x, and that 2.6x is
measured against a smoothed annual-average index of only 2.55% volatility, not the 9% the engine uses. **2.0 is
at or above the evidence at every horizon of 5 years or more, so it is the more cautious defensible figure.**
Going higher would be beyond anything the sources support for a 9%-volatility index. Going lower (about 1.4x,
the Eichholtz long-run figure) is the sourced optimistic sensitivity.

## Recommended figure for the build

- **Constant:** `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE` (`packages/finance-engine/src/Dto/AssumptionSet.php`)
- **Value:** **2.0, unchanged.** It is the cautious end of a sourced range of about 1.1x-2.2x. Optimistic sensitivity: 1.4x.
- **Primary source URL:** https://finance.unibocconi.eu/sites/default/files/files/media/attachments/MarcoGiacolettiJMP20170116082355.pdf
  (Giacoletti; credits "twice more volatile" to Case and Shiller 1988; σu 9.6-17.8% per resale, not compounding).
  Supporting sources: https://repec.canterbury.ac.nz/cbt/econwp/2313.pdf (NZ, 1.3x-2.6x at 5 years) and
  https://www.landecon.cam.ac.uk/sites/default/files/2023-05/crerc-wp-2020-01-lindenthal.pdf (about 1.4x long-run).
- **verified_on:** 2026-10-11
- **Caveat to disclose:** no UK study was found. The figure rests on US, NZ and historic Dutch and French repeat-sales data.

## What the build session should change

1. **`AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`:** leave the value at `2.0`. Rewrite the docblock's
   SOURCE paragraph to say: the multiple is the "individual homes about twice as volatile as the city index"
   finding (Case and Shiller 1988, as reported by Giacoletti 2017/2021), which sits at the cautious end of the
   repeat-sales evidence because house-specific shocks are largely one-off rather than compounding; see
   docs/research/RESEARCH-0086-single-property-volatility.md.
2. **`AssumptionSetLibrary::SINGLE_PROPERTY_SOURCE`:** replace the string with one that cites the Giacoletti
   URL above as the primary source, names Cheung et al. 2023 (NZ) and Eichholtz et al. 2020 as support, and
   says no UK estimate exists. Keep it plain. Suggested text: `'Individual homes are about twice as volatile
   as the city index (Case and Shiller 1988, reported in Giacoletti 2017: https://finance.unibocconi.eu/sites/default/files/files/media/attachments/MarcoGiacolettiJMP20170116082355.pdf ).
   Repeat-sales studies put the long-horizon multiple at 1.1-1.8x (Cheung et al. 2023, NZ:
   https://repec.canterbury.ac.nz/cbt/econwp/2313.pdf ; Eichholtz et al. 2020, about 1.4x), so 2.0 is the
   cautious end. No UK study found.'`
3. **`AssumptionSetLibrary::SINGLE_PROPERTY_VERIFIED_ON`:** `'2026-08-19'` changes to `'2026-10-11'`.
4. **`docs/spec/ASSUMPTIONS.md` §13:** replace the "⚠️ SOURCING GAP, the second in this document" paragraph
   with a sourced one carrying the facts in Findings 1, 2 and 4. Name the range (about 1.1x-2.2x by horizon,
   2.0 chosen as the cautious end, 1.4x as the optimistic sensitivity) and say no UK study exists. Remove
   §13 from the sourcing-gap list and drop "Confirm you are happy with 2.0x until that lands". Add the three
   URLs, each with "read 2026-10-11", to the source list at the foot of the file.
5. **`ScenarioForecaster::ENGINE_VERSION`:** **no bump.** The figure did not move, so stored runs stay valid.
   Card criterion 2 ("when the sourced figure differs from 2.0") does not apply. Record that on the card.
6. Run `php artisan test --testsuite=Engine`, then the full suite. Tests that pin the source string or the
   verified_on date will need the new text.

**Out of scope for 0086, worth a card:** the engine treats the house-specific spread as compounding every
year (a random walk). The evidence says it is mostly a one-off shock at sale (Finding 2). A one-off shock
model would be more faithful, but it is a mechanism change, which this card's "Not this card" section
excludes. Raise it separately if wanted. The current mechanism errs on the cautious side.
