---
needs: 0222
---
# One screen shows where the money comes from and what it lets you spend

## Why
Rob, 2026-10-23: "need at least 1 screen that just shows what the sources of income are, what the
projected spend should/could be based on that income". The figures existed, but spread over the
long results page, in folds.

## Direction
A page of its own at `/scenarios/{scenario}/money`, linked from the dashboard and the results page.
Two tables, monthly, in today's money, at the card-0222 life stages: each income source by name
(regular income apart from money drawn from pots and savings), less tax, money to spend; then what
the plan spends, its essentials, what is left, and what it could spend at the safe extra from
`SustainableSpend`. The "could spend" row is a real forecast at that extra, so a survivor's
scaled-down budget shows as it is, not as essentials plus a flat sum.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL show each named income source, tax, money to spend, the plan's spend and what it could spend, per stage, and each column SHALL add up. proves: `test_each_stage_adds_up_from_its_named_sources_to_what_is_left`
- [x] #2 THE APP SHALL refuse another user's plan. proves: `test_another_users_plan_is_refused`
<!-- AC:END -->

## Comments

**2026-10-23** RESULT: built
TESTS: +2 new in `IncomeAndSpendingTest`
TOUCHED: new `App\Livewire\IncomeAndSpending` and its view, route `scenarios.money`, `ResultPresenter::incomeAndSpending()` (stage picking moved to `lifeStages()`, shared with `monthlyNetIncome()`), `SustainableSpend::forecastAt()`, links on the dashboard and results page
OUT-OF-SCOPE: not in the PDF; no chart (two short tables say it).

A "Your plan spends £X a month more than it can keep up" line shows when the plan's spend now is
above what it could carry. Checked on the rich fixture as rendered text, **not seen in a browser**.
