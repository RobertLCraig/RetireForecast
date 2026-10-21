# Savings and property income are taxed 2 points more from April 2027, and the engine never applies it

## Why
Found by the full review on 2026-10-21.

`TaxYearRegistry::SUPPORTED_TAX_YEARS` is `2025-26` and `2026-27`, and `PathProjector` is built with
one `TaxYearConfig` and uses it for every year of the plan. So a rate change announced for a later
year never reaches the forecast.

Budget 2025 (policy paper, 26 November 2025, legislated in Finance Bill 2025-26):
- **Savings income:** 22% / 42% / 47% from 6 April 2027 (was 20 / 40 / 45). Allowances unchanged.
  The starting rate for savings stays £5,000 to 5 April 2031.
- **Property income:** its own rates, 22% / 42% / 47% from 6 April 2027, in England, Wales and NI.
  Property income is taxed after employment and other income, before savings and dividends.
  Finance costs (mortgage interest) are relieved at 22%, not 20%.
- **Dividends:** 10.75% / 35.75% / 39.35% from April 2026. The engine has these for 2026-27, but a
  plan whose base tax year is 2025-26 keeps 8.75% / 33.75% for life.

Effect: cash-heavy plans and let-out plans look better than they are. The let-out plan is one of the
options the comparison ranks.

Sources:
- https://www.gov.uk/government/publications/changes-to-tax-rates-for-property-savings-dividend-income/changes-to-tax-rates-for-property-savings-dividend-income
- https://www.gov.uk/government/publications/changes-to-tax-rates-for-property-savings-and-dividend-income/change-to-tax-rates-for-property-savings-and-dividend-income-technical-note

## Direction
Let the projector resolve the rates for the tax year it is in, not only the base year. The smallest
change is a per-year rate schedule (rates by tax year, thresholds still indexed as now), rather than
a full `TaxYearConfig` for every future year. Every rate carries its `source` and `verified_on`.
Check whether rental income is taxed today as non-savings income or on its own path, before choosing
where the property rates go. Bump `ENGINE_VERSION`; expect the golden master red.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN savings interest above the allowances is taxed in tax year 2027-28 or later, THE APP SHALL tax it at 22% / 42% / 47%. proves: `test_savings_income_is_taxed_at_the_2027_rates_from_april_2027`
- [ ] #2 WHEN rental income is taxed in 2027-28 or later, THE APP SHALL tax it at the property rates. proves: `test_rental_income_is_taxed_at_the_property_rates_from_april_2027`
- [ ] #3 WHEN the base tax year is 2025-26, THE APP SHALL tax dividends at the 2026-27 rates from April 2026. proves: `test_a_2025_base_year_plan_takes_the_2026_dividend_rates`
- [ ] #4 WHEN the year is 2026-27, THE APP SHALL keep the 20% / 40% / 45% savings rates. proves: `test_savings_income_keeps_the_old_rates_before_april_2027`
<!-- AC:END -->
