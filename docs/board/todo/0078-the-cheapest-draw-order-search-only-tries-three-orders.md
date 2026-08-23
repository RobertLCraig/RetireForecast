# The "cheapest draw order" search only ever tries three orders

## Why
The results page says "of the 3 draw orders we tried, the cheapest is X". Those three are the three
orders the engine has names for: spend savings first, draw pension first, fill the free bands first.
So the search is a pick from a menu, not a search — it cannot find an order nobody has already
written down.

The plan this came from ([PLAN-withdrawal-sequencing.md](../../build/PLAN-withdrawal-sequencing.md),
section "#6") describes one more lever: **manage taxable income to £X**, where X is a target the plan
holds each person's taxable income under, tried at a few values (the personal allowance, the
basic-rate ceiling, and one or two in between). Each value is another candidate, and one of them can
beat all three named orders — a household whose income sits just over a threshold pays for it every
year of the plan.

It was not built, on purpose. Rob's decision 1 of 2026-07-01 was "a third named strategy, **not a
general planner yet**", and the plan's own #6 says to confirm the candidate set with Rob before
building it. Nobody has confirmed it, so the shipped set is the plan's recommended default: a fixed
handful of named orders.

Two things this card must settle before any code, because they are Rob's call and not the builder's:

- **How many targets, and which.** Every candidate is a whole forecast, and they run on a page
  render. The plan's ceiling is 4 to 6 candidates in total, so three named orders leaves room for
  about three targets.
- **Whether a target the reader cannot choose is worth showing at all.** Card 0075 already says the
  reader cannot pick the order their own forecast runs on. Adding orders they can see but not use
  widens that gap rather than closing it, so 0075 may want to land first.

## Not this card
A full combinatorial search over wrappers and amounts. Even professional tools stop short of it, and
the plan rules it out by name.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL try at least one draw order that is not one of the three named ones.
- [ ] #2 THE APP SHALL keep the number of forecasts a results page runs within the bound the plan
      sets (4 to 6 candidates in total).
- [ ] #3 WHEN a generated order wins, THE APP SHALL name it to the reader in terms they can act on,
      not as an internal setting.
<!-- AC:END -->

## Tasks
- [ ] Get Rob's call on the target values and on whether this waits for 0075
- [ ] Carry the target on the strategy the projector reads, without turning `DrawdownStrategy` into a
      general planner (Rob's decision 1, 2026-07-01)
- [ ] Add the targets to `WithdrawalStrategyComparison::CANDIDATES` and give each one a `label()`
- [ ] Re-run `php artisan scenarios:audit`; the panel's "cheapest" figure moves if a target wins
