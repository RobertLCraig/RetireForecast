# Research: the three letting-cost rates (management, void, maintenance)

*Researched 2026-10-11 for board card 0087 (pin the three letting-cost rates to a published source). All sources read 2026-10-11.*

## The question

The engine takes three costs off a let property's gross rent before anything is banked or taxed:
`Property::DEFAULT_LETTING_MANAGEMENT_BPS` (shipped 1_200 = 12%, VAT included),
`Property::DEFAULT_LETTING_VOID_BPS` (shipped 800 = 8%) and
`Property::DEFAULT_LETTING_MAINTENANCE_BPS` (shipped 500 = 5%), in
`packages/finance-engine/src/Dto/Property.php`. That is 25% in total. `docs/spec/ASSUMPTIONS.md` §14
records all three as a reviewer's judgement (2026-08-19) with no published source. This note asks
what published or fetchable figures say for each, and applies the house rule: where several figures
are defensible, ship the more cautious one (here: the HIGHER cost), user-editable.

## What the official survey does and does not give

The English Private Landlord Survey 2024 (MHCLG, published 5 December 2024) is the primary source on
how landlords let and manage, but it publishes **no fee, void-length or repair-spend figures**. It
says "Just over two in five (43%) landlords in 2024 said they used agent letting services" and "just
under one in five (18%) said they used agent management services"; void periods and the cost of
repairs appear only as landlord concerns (39% of those who cut rent did so "to avoid a lengthy void
period"; 35% named "the cost of repairs" as a concern). Median gross rental income was £19,200.
Source: <https://www.gov.uk/government/statistics/english-private-landlord-survey-2024-main-report/english-private-landlord-survey-2024-main-report>
(read 2026-10-11). So no primary government series exists for any of the three rates; every figure
below is a fetchable secondary source (industry research or agents' published fee guides).

## Findings

### 1. Management (fully managed agent fee)

No ARLA Propertymark, Which? or EPLS fee survey could be fetched. Searches turned up only a
third-party claim (Whito, September 2026) that Propertymark's typical figures are "around 15 to 20%
for full management", often quoted ex VAT; the Propertymark original was not found, so it is not
relied on. Agents' and landlord-software published ranges, all fetched:

- Buildington, "Letting Agent vs Self-Managing: A 2026 Guide for Landlords" (16 July 2026): full
  management "10% to 15% of monthly rent, plus VAT"; "a quoted 10% management fee becomes an
  effective 12% once the standard 20% rate is added".
  <https://www.buildington.co.uk/blog/letting-agent-vs-self-managing-a-2026-guide-for-landlords> (read 2026-10-11)
- Purplebricks, "Letting agent fees for landlords" (undated): "Full management fees typically range
  from 10-20% of your monthly rent plus VAT."
  <https://www.purplebricks.co.uk/moving-guides/letting-agent-fees-for-landlords> (read 2026-10-11)
- Landlord Studio, "Property Management Fees UK" (published 9 July 2025, updated 1 October 2026):
  national "8%–15% of monthly rent", London "12%–15%", North West and Midlands "8%–12%"; VAT
  treatment not stated.
  <https://www.landlordstudio.com/uk-blog/property-management-fees-uk> (read 2026-10-11)

**Range:** 8% to 15% plus VAT is the consensus band, with 10% to 15% plus VAT for a high-street
agent; that is **12% to 18% of gross rent VAT included**, and up to 24% at Purplebricks' 20% top.
The shipped 12% inc VAT is a 10%+VAT fee, which is the **bottom** of the high-street band, not the
cautious end. The adverse figure is the top of the consensus band: 15% + VAT = **18%**.

### 2. Void (rent lost between tenancies)

- Rushbrook & Rathbone analysis, reported by Property Reporter (13 August 2026): England's average
  void "currently stands at 21 days" (June 2026), "down three days from the May peak of 24 days",
  against "the June 2025 average of 19 days". The measure (per void, between tenancies) is not
  defined in the article.
  <https://www.propertyreporter.co.uk/englands-average-void-period-falls-to-21-days-as-landlords-adapt-to-rra.html> (read 2026-10-11)
- Paragon / BVA BDRC landlord survey (800 landlords): the share of landlords reporting a void "peaked
  at 39 per cent in Q3 2020" and was "around one in four landlords in Q4 2021".
  <https://www.mortgagesolutions.co.uk/mortgage-news/2022/02/07/rental-property-voids-hit-five-year-low/> (read 2026-10-11)
- Not fetched, search snippets only (Paragon's own press page returned HTTP 403): Paragon Q4 2022
  put the average void at 70 days (from 82) among landlords who had one; a 2026 Pegasus Insight
  survey reportedly put 41% of landlords with a void of over 7 days in the past year, averaging 66
  days. Treat these as unverified upper-end context only.

**Range:** 21 to 24 days per void (2026), which is 5.8% to 6.6% of the year if a void falls every
year; much less if the tenant stays several years, much more if a void lasts the 66 to 70 days the
unverified surveys report. The shipped 8% is about 29 days a year, one month's rent, which is above
every fetched 2026 per-void figure and matches the "one month's void per annum" rule of thumb
landlord guides use. It is already the cautious defensible figure; no fetched source argues for
higher on a single let.

### 3. Maintenance (repairs, compliance, inventory)

No published series gives landlord repair and compliance spend directly as a share of rent. The
nearest fetchable figure:

- Pegasus Insight, "Landlord Trends Q3 2025", reported by Mortgage Solutions (6 January 2026):
  "landlords are now spending between 25% and 45% of their gross rental income on running costs",
  and "property maintenance and repairs account for between 31% and 39% of total portfolio
  expenditure". Average annual expenditure £19,604 (non-HMO landlords); average BTL portfolio gross
  income £79,000. Sample size and commissioning client not stated.
  <https://www.mortgagesolutions.co.uk/mortgage-news/2026/01/06/landlords-income-squeezed-by-rising-repair-and-maintenance-costs/> (read 2026-10-11)
  Same research also at <https://www.propertyreporter.co.uk/landlords-spending-up-to-45-of-rental-income-on-running-costs.html> (read 2026-10-11).

**Derived range (this note's arithmetic, not a published figure):** maintenance and repairs as a
share of gross rent = 31%–39% of 25%–45% = **7.75% to 17.6%** of gross rent. The lowest product,
31% x 25% = 7.75%, already exceeds the shipped 5%, and that excludes compliance (gas safety, EICR,
inventory), which the survey counts separately. The shipped 5% sits below every figure this
evidence supports. The cautious figure that stays defensible is **8%**, the low end of the derived
range rounded up. A higher one (12% to 17%) would rest on HMO-heavy portfolios and on multiplying
two ranges, which goes further than the source.

Cross-check: the repo's `HousingComparison::HOME_MAINTENANCE_RATE_BPS` (1% of value a year, in
ASSUMPTIONS.md) is a homeowner rule. At a typical 5% to 6% gross yield it equals 17% to 20% of rent,
so 8% is not out of line with the app's own other maintenance assumption.

## Recommended figures for the build

| Constant | Shipped | Recommended | Basis | Source URL | verified_on |
|---|---|---|---|---|---|
| `DEFAULT_LETTING_MANAGEMENT_BPS` | 1_200 | **1_800** (18%, VAT included) | top of the 10%–15% + VAT high-street band | <https://www.buildington.co.uk/blog/letting-agent-vs-self-managing-a-2026-guide-for-landlords>; also <https://www.purplebricks.co.uk/moving-guides/letting-agent-fees-for-landlords>, <https://www.landlordstudio.com/uk-blog/property-management-fees-uk> | 2026-10-11 |
| `DEFAULT_LETTING_VOID_BPS` | 800 | **800** (8%, unchanged) | about one month a year; above the fetched 21–24 day 2026 averages | <https://www.propertyreporter.co.uk/englands-average-void-period-falls-to-21-days-as-landlords-adapt-to-rra.html>; also <https://www.mortgagesolutions.co.uk/mortgage-news/2022/02/07/rental-property-voids-hit-five-year-low/> | 2026-10-11 |
| `DEFAULT_LETTING_MAINTENANCE_BPS` | 500 | **800** (8%) | low end of 31%–39% x 25%–45% of gross rent (derived) | <https://www.mortgagesolutions.co.uk/mortgage-news/2026/01/06/landlords-income-squeezed-by-rising-repair-and-maintenance-costs/> | 2026-10-11 |

Total letting cost moves from **25% to 34% of gross rent**. That is inside the 25%–45% running-cost
band Pegasus reports (the void is lost income rather than a running cost, but the comparison is
conservative because Pegasus also counts insurance and utilities, which these three exclude).

## What the build session should change

1. `packages/finance-engine/src/Dto/Property.php`: set `DEFAULT_LETTING_MANAGEMENT_BPS = 1_800`
   (comment "18.00% of gross rent, VAT included"), leave `DEFAULT_LETTING_VOID_BPS = 800`, set
   `DEFAULT_LETTING_MAINTENANCE_BPS = 800`. Rewrite the docblock above them: "12% management, 8%
   void, 5% maintenance, a quarter of the rent" becomes "18% management, 8% void, 8% maintenance,
   about a third of the rent"; "around 10% plus VAT" becomes "10% to 15% plus VAT, shipped at the
   top"; replace "a reviewer's judgement, NOT a published series" with the three source URLs and
   verified_on 2026-10-11.
2. `docs/spec/ASSUMPTIONS.md` §14: change the heading's 25% to 34% and the three figures; set
   verified_on to 2026-10-11; replace the SOURCING GAP paragraph with the table above, saying
   plainly that no primary government series exists (the EPLS 2024 publishes none) so the sources
   are agents' fee guides and industry surveys, and that maintenance is derived arithmetic from the
   Pegasus survey, not a published rate. Add the five URLs to the source list at the foot of the file.
3. Bump `ScenarioForecaster::ENGINE_VERSION`, because two figures move; note that stored let
   scenarios need re-running.
4. Run `php artisan test --testsuite=Engine`, then the full suite. Any test that hard-codes 25%,
   12% or 5% (for example around `test_the_assumed_letting_costs_are_disclosed_with_their_values`)
   should read the constants, not restate them; fix any that restate them.

## Gaps stated plainly

- No primary (government or ARLA Propertymark) source was found for any of the three rates.
- The Propertymark 15%–20% figure, the Paragon 70-day and the Pegasus 66-day void figures were seen
  only in search snippets and are not relied on.
- No published series states landlord maintenance as a share of rent; the 8% is derived.
