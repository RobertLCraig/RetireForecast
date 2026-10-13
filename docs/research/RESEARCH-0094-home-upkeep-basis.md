# What a bought home's upkeep should be charged on: a percentage of value, a flat figure, or both

*Researched 2026-10-11 for board card 0094 (the bought home's upkeep is a percentage of its value). Every source below was read on 2026-10-11.*

## The question

When the reader enters no running cost for the home they would buy, and the current home has none
to scale, `HousingComparison::newHomeRunningCosts()` charges `HOME_MAINTENANCE_RATE_BPS = 100`,
which is 1% of the buy price a year. The question is what UK owner-occupiers actually spend on
maintenance and repair a year, expressed as (a) a percentage of property value and (b) a flat GBP
figure, split by property age or type where a source allows. The answer has to say what each basis
charges on a GBP 60,000 park home, a GBP 120,000 flat and a GBP 300,000 house, and which basis is
less wrong at each end.

## Findings

### 1. ONS Living Costs and Food Survey: the only primary flat-GBP series

The ONS *Family spending workbook 5: expenditure on housing* for the financial year ending 2025
(April 2024 to March 2025) was published 2026-06-11. Dataset page:
<https://www.ons.gov.uk/peoplepopulationandcommunity/personalandhouseholdfinances/expenditure/datasets/familyspendingworkbook5expenditureonhousing>.
The file read was
<https://www.ons.gov.uk/file?uri=/peoplepopulationandcommunity/personalandhouseholdfinances/expenditure/datasets/familyspendingworkbook5expenditureonhousing/fye2025/workbook5housing.xlsx>.
It reports average weekly spend per household in GBP:

| Line (Table 2.4, by age of household reference person) | Under 30 | 30-49 | 50-64 | **65-74** | 75+ | All |
|---|---|---|---|---|---|---|
| Maintenance and repair of dwelling (central heating repairs, house maintenance, paint, small materials) | 5.70 | 9.20 | 15.10 | **14.10** | 13.10 | 11.90 |
| Alterations and improvements to dwelling (central heating installation, contracted-out home improvements, bathroom fittings, DIY double glazing and kitchens, capital materials) | 6.80 | 30.20 | 35.90 | **42.50** | 31.90 | 32.00 |
| **Both, GBP per week** | 12.50 | 39.40 | 51.00 | **56.60** | 45.00 | 43.90 |
| **Both, GBP per year (x52)** | 650 | 2,049 | 2,652 | **2,943** | 2,340 | 2,283 |

Table 2.2 in the same workbook shows the all-household total moving very little over four years:
GBP 9.70 + 30.80 a week in 2021-22, then 10.70 + 31.10, 11.00 + 33.10, and 11.90 + 32.00 in 2024-25.

How to read it:
- **The two lines have to be added together for this card.** A new boiler, a re-roof, replacement
  windows and a new bathroom are the items the card names. The survey classes them as
  "alterations and improvements", not as "maintenance and repair". Taken alone, the repair line
  (GBP 619 a year across all households, GBP 733 at 65-74) leaves out the large, lumpy
  replacements that dominate real upkeep.
- **The figures are averaged over ALL households, renters included.** A renter's spend on the
  landlord's structure is close to zero. That makes the per-owner-occupier average higher than the
  table shows. This is inference, not a published figure: the workbook has no tenure split, and I
  did not find one in any table I read.
- **The improvements line also includes elective work** such as extensions and conservatories.
  That pulls the other way. I could not find a published split between like-for-like replacement
  and enlargement.
- **Cross-check against value.** The UK average house price was GBP 271,000 in March 2025
  (gov.uk UK HPI summary, published 2025-05-21,
  <https://www.gov.uk/government/statistics/uk-house-price-index-for-march-2025/uk-house-price-index-summary-march-2025>).
  The 65-74 figure of GBP 2,943 is 1.09% of that price, and the all-household GBP 2,283 is 0.84%.
  So **at an average-priced home the 1% rule and the ONS cash figure agree.** They part company
  only away from the average.

### 2. English Housing Survey: what the stock needs, not what owners spend

*English Housing Survey: Profile of English housing 2013*, Chapter 3, Table 3.1, gives average
required expenditure per dwelling in 2013 prices, excluding VAT and contractor profit
(<https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/445377/Chapter_3_Dwelling_condition_and_safety.pdf>).
"Comprehensive" repairs are urgent work, plus work needed within 5 years, plus replacements needed
within 10 years.

| Group | Comprehensive repair need per dwelling (GBP, 2013) | Divided by 10 years |
|---|---|---|
| Owner occupied | 4,414 | 441 |
| Pre-1919 | 6,792 | 679 |
| 1919-1944 | 5,852 | 585 |
| 1945-1964 | 3,967 | 397 |
| 1965-1980 | 2,886 | 289 |
| Post-1980 | 1,499 | 150 |
| Semi and detached | 4,812 | 481 |
| Converted flat | 4,551 | 455 |
| Purpose-built flat | 1,806 | 181 |

What it is good for:
- It is the only primary source here split by **age and type**, and it shows the gradient the card
  suspected. Pre-1919 stock needs about 4.5 times what post-1980 stock needs. Purpose-built flats
  need the least, because the block's exterior is shared.
- It is **not an annual spend figure**. It is a snapshot of work outstanding, priced at block or
  contract rates, without VAT or profit, in 2013 prices. It leaves out routine and cyclical work
  and decoration. So dividing by ten gives a **floor, not an estimate**.
- I found no newer per-dwelling repair-cost table for owner-occupiers. The 2022-23 and 2023-24
  housing-quality releases on gov.uk report non-decency rates and energy-upgrade costs instead
  (2023-24 put the average cost of reaching EPC band C at GBP 7,320, which is not a repair cost).

### 3. BCIS (RICS): about 1% of REBUILD cost, and a warning against using it per home

BCIS, *FM market represents nearly 8% of UK GDP*, published 2023-07-03
(<https://bcis.co.uk/news/fm-market-represents-nearly-8-of-uk-gdp/>), summarising *Economic
Significance of Maintenance 2023*: "housing maintenance expenditure represented 1.16% of the
replacement cost of the dwellings to be maintained" (2021 data). The same page warns that this
ratio "has metamorphosed into a rule of thumb" and that "estimates of national expenditure are
inappropriate for assessing the budget needs of specific properties".

Two points matter for this card:
- The base is **replacement (rebuild) cost, not market value**. Rebuild cost tracks floor area and
  construction type. It does not track location. That is the card's own argument: a cheap home
  in a cheap area costs nearly as much to rebuild, so nearly as much to maintain, as the same home
  somewhere dear. So BCIS supports a percentage of value only where value is close to rebuild
  cost, and it supports a flat amount at the low end.
- BCIS's per-building running-cost data (Building Running Costs Online) and its rebuild GBP/m2
  rates are behind a subscription. I found no public residential GBP/m2 maintenance figure. BCIS
  reported a 3.8% average rise in residential rebuild costs in its July 2025 model update
  (<https://bcis.co.uk/news/rebuild-cost-models-update-shows-uplift-in-reinstatement-costs/>).

### 4. Secondary: trade surveys

- **Checkatrade**, as reported by Electrical Times on 2025-04-02
  (<https://www.electricaltimes.co.uk/?p=29950>): UK consumers spend "an average of £3,403
  annually on general property upkeep". The same release also gives "£627 per month on home
  maintenance and repairs", of which £283 a month is building repair and maintenance. The two
  figures contradict each other (GBP 627 a month is GBP 7,524 a year), and no sample size or
  method is given. Treat it as weak. I could not open the release on Checkatrade's own site.
- **The "Checkatrade 2023: about 1% of value, older stock 1.5% to 4%" claim** quoted in
  `HousingComparison.php` and ASSUMPTIONS.md §17 **could not be traced to a Checkatrade
  publication**. The 1% to 4% wording matches advice once on Southwark Council's leaseholder
  service-charge pages, which now return 404. Several property-press pieces repeat 1% as "what
  experts say" without a source. The 1% is therefore a rule of thumb with no traceable
  measurement behind it. The rebuild-cost-based 1.16% from BCIS is the nearest thing to one.
- **Park homes.** I found no published maintenance-cost series for park homes.

## The three homes compared

GBP per year. "Floor" means the recommended basis below: the greater of 1% of the buy price and a
flat GBP 3,000.

| Basis | GBP 60,000 park home | GBP 120,000 flat | GBP 300,000 house |
|---|---|---|---|
| A. 1% of value (ships now) | 600 | 1,200 | 3,000 |
| B. Flat, ONS repair line only, HRP 65-74 (GBP 14.10/wk) | 733 | 733 | 733 |
| C. Flat, ONS repair + improvements, all households (GBP 43.90/wk) | 2,283 | 2,283 | 2,283 |
| D. Flat, ONS repair + improvements, HRP 65-74 (GBP 56.60/wk) | 2,943 | 2,943 | 2,943 |
| EHS 2013 floor for comparison (comprehensive need / 10, 2013 prices, no VAT) | none published | 181 (purpose-built) to 455 (converted) | 481 (semi/detached) to 679 (pre-1919) |
| **Recommended: max(1% of value, GBP 3,000)** | **3,000** | **3,000** | **3,000** |

Which basis is less wrong at each end:
- **Low end (park home, flat): the flat figure is less wrong.** One boiler replacement or one roof
  repair costs more than the GBP 600 that 1% charges on a GBP 60,000 home. The percentage then
  drops below even the ONS repair-only line for 65-74s (GBP 733), which leaves out every
  replacement. At the low end, 1% of value is not cautious. It understates upkeep by a factor
  of 3 to 5.
- **Middle (about GBP 270,000 to 300,000): both bases agree.** 1% of GBP 300,000 is GBP 3,000.
  The ONS 65-74 figure is GBP 2,943. Either is defensible, and that agreement is why a hybrid
  works.
- **High end (well above GBP 300,000): the percentage is less wrong.** Dearer homes are usually
  larger, and BCIS ties upkeep to rebuild cost, which grows with floor area. A flat GBP 3,000 on a
  GBP 600,000 house would understate it.
- **Where the floor most likely overstates:**
  - A park home. The site owner maintains the ground and infrastructure from the pitch fee, and
    the unit is small and single-storey. No source exists, so caution governs.
  - A purpose-built leasehold flat whose fabric is covered by a service charge. The EHS shows this
    type needs the least repair.
  - Both cases are why the figure must stay user-editable. An entered `buyRunningCosts` already
    wins over any default.

## Recommended basis for the build

**The answer is a hybrid: charge the greater of 1% of the buy price and a flat GBP 3,000 a year.**

- **Keep `HousingComparison::HOME_MAINTENANCE_RATE_BPS = 100`** (1% of value) as the percentage
  arm, PUBLIC, unchanged. Source for the percentage: BCIS 1.16% of replacement cost (2021 data,
  <https://bcis.co.uk/news/fm-market-represents-nearly-8-of-uk-gdp/>, published 2023-07-03),
  cross-checked by ONS FYE2025 spend being 1.09% of the GBP 271,000 UK average price. verified_on
  2026-10-11.
- **Add `HousingComparison::HOME_MAINTENANCE_FLOOR_POUNDS = 3_000`**, PUBLIC, so
  `ResultPresenter::assumedFigures()` reads it rather than restating it.
  - Source: ONS Family spending workbook 5, FYE2025, Table 2.4. For households whose reference
    person is 65-74, maintenance and repair (GBP 14.10 a week) plus alterations and improvements
    (GBP 42.50 a week) is GBP 56.60 a week, or GBP 2,943 a year.
    <https://www.ons.gov.uk/file?uri=/peoplepopulationandcommunity/personalandhouseholdfinances/expenditure/datasets/familyspendingworkbook5expenditureonhousing/fye2025/workbook5housing.xlsx>,
    published 2026-06-11, verified_on 2026-10-11.
  - GBP 2,943 is rounded up to GBP 3,000. That is a 1.9% rounding toward caution, not a measured
    price uplift.
- **Why 65-74 and not all households:**
  - It is the cohort a downsizing purchase belongs to.
  - It is the higher of the two defensible ONS figures (GBP 2,943 against GBP 2,283), and the house
    rule ships the more cautious one.
  - The all-household figure is diluted by renters, who spend little on structure.
- **Why not the repair line alone (GBP 733):** it leaves out boilers, roofs, windows and
  bathrooms. Those are the costs the card exists to price.
- **Prices are FYE2025 (April 2024 to March 2025) pounds.** The build session should treat the
  floor the same way the engine treats other flat GBP constants (in today's money, inflated by the
  engine's own price index). It should not hardcode a nominal amount that stays fixed for 30
  years. Check how the engine handles `essentialAnnualSpend` and match that.

### What the build session should change

1. `HousingComparison`:
   - Add `public const HOME_MAINTENANCE_FLOOR_POUNDS = 3_000;`.
   - Replace the docblock above `HOME_MAINTENANCE_RATE_BPS`. The "Checkatrade 2023" citation in
     it cannot be traced (Finding 4). Use the two sources above with verified_on 2026-10-11.
   - In `newHomeRunningCosts()`, change the final fallback line from 1% of the buy price to the
     greater of `$buyPrice->applyRate(Percent::fromBasisPoints(self::HOME_MAINTENANCE_RATE_BPS))`
     and `Money::fromPounds(self::HOME_MAINTENANCE_FLOOR_POUNDS)`. Compare on `->pence` if
     `Money` has no max helper.
   - The explicit `buyRunningCosts` branch and the scaled-current-home branch stay exactly as they
     are.
2. `ResultPresenter::assumedFigures()`:
   - Change the assumed-upkeep sentence so it names the basis actually applied. Below the
     crossover it should say something like "a flat £3,000 a year, the typical spend on upkeep
     and replacements for households aged 65-74 (ONS)". At or above the crossover it should keep
     "1% of its value".
   - Read both constants and do the same comparison, ideally by calling
     `HousingComparison::newHomeRunningCosts()` for the amount rather than re-deriving it.
   - The crossover is GBP 300,000 (GBP 3,000 / 1%).
3. `AssumedFiguresDisclosureTest`:
   - Two existing tests change their expected figure, because both buy prices sit below the
     crossover. The GBP 150,000 case (asserts "1% of its value") now discloses £3,000. The
     GBP 250,000 case now discloses £3,000 rather than £2,500, and its "not £1,500" guard still
     holds.
   - Add one case above the crossover (for example GBP 400,000, so £4,000 at 1%) to prove the
     percentage arm.
   - Add one case at GBP 60,000 to prove the floor.
   - Criterion #1's proving test is `test_an_assumed_upkeep_figure_is_disclosed_with_its_value`.
     Keep that name, and make it assert that the source and its verified_on date appear beside
     the figure.
4. `docs/spec/ASSUMPTIONS.md` §17 (criterion #2). Replace the entry with text to this effect: "The
   upkeep of a home you would BUY, where no running cost is entered and the current home has none
   to scale, is the greater of 1% of the buy price and £3,000 a year (verified_on 2026-10-11). The
   £3,000 is ONS Family spending FYE2025 Table 2.4: households aged 65-74 spend £14.10 a week on
   maintenance and repair plus £42.50 on alterations and improvements, £2,943 a year, rounded up.
   The 1% is BCIS's 1.16% of rebuild cost (2021), which matches ONS spend at the UK average price.
   The alternative, 1% of value alone, would have charged £600 on a £60,000 park home and £1,200
   on a £120,000 flat, below even the £733 that 65-74s spend on minor repairs before any boiler,
   roof or window. An entered running cost always wins." Remove the "Confirm you are happy with 1%"
   line.
5. Re-run the stored scenarios. Every stored scenario that buys below GBP 300,000 with no entered
   running cost now carries more essential spend, up to GBP 2,400 a year more at GBP 60,000.

### Confidence and open gaps

- **Age:** no source splits owner-occupier spend by property age or type in current prices. Only
  the EHS 2013 need figures split by age and type.
- **Tenure:** the ONS figures cover all tenures.
- **Park homes:** no source covers them at all.
- **Does not change the recommendation:** none of these gaps does, because each one either pushes
  the per-owner figure up (tenure) or is covered by the figure being user-editable (park homes,
  service-charged flats).
