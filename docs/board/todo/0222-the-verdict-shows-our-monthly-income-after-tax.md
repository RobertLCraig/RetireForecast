---
needs: 0212, 0213
---
# The verdict shows our monthly income after tax, at the three stages that matter

## Why
Found by the full review on 2026-10-21. The income plan (L573-701) is gross, as entered, collapsed,
on the Spending tab. The ladder has the sources and the tax, yearly, collapsed. There is no "what we
live on each month, after tax" figure.

## Direction
A strip on the verdict tab: monthly household income after tax, in today's money, at three stages -
now; once both have retired and the State Pension is paid; after the first death. Split by the five
income groups from card 0220. Derive from the year's `YearResult` sources less the year's tax; never
store a separate total.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL show monthly after-tax household income at the three stages, in today's money. proves: `test_the_verdict_shows_monthly_net_income_at_three_stages`
- [ ] #2 THE APP SHALL make each stage's parts sum to its total, and the total equal the year's income less its tax divided by 12. proves: `test_monthly_net_income_reconciles_to_the_year`
<!-- AC:END -->
