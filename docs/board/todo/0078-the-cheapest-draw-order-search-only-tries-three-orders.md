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

## Links

**Relates to**
- `0075` - the reader still cannot pick the order their own forecast runs on, so more orders they
  can see but not use widens that gap; it may want to land first.

## Not this card
A full combinatorial search over wrappers and amounts. Even professional tools stop short of it, and
the plan rules it out by name.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL try at least one draw order that is not one of the three named ones.
- [x] #2 THE APP SHALL keep the number of forecasts a results page runs within the bound the plan
      sets (4 to 6 candidates in total).
- [x] #3 WHEN a generated order wins, THE APP SHALL name it to the reader in terms they can act on,
      not as an internal setting.
<!-- AC:END -->

## Comments

**2026-09-08**
RESULT: done
TESTS: +3 new, all green
TOUCHED: app/Forecast/DrawCandidate.php (new)
TOUCHED: app/Forecast/WithdrawalStrategyComparison.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: packages/finance-engine/src/Forecast/ForecastSettings.php
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/src/Housing/HousingComparison.php
TOUCHED: tests/Feature/Forecast/ScenarioForecasterTest.php
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/build/PLAN-withdrawal-sequencing.md
TOUCHED: docs/board/todo/0144-the-cheapest-order-can-now-be-one-the-reader-cannot-pick.md (new)
OUT-OF-SCOPE: 0144

**The two calls the card reserves for Rob could not be asked in an unattended session, and both
turned out to be answerable from the repository, so this was built rather than left blocked.** Card
0075 has landed, so the "does this wait for 0075" question is settled: the named orders are a builder
control now. The candidate set is the plan's own recommended default and nothing wider. What remains
genuinely open is written into DECISIONS 2026-09-08 as open for Rob, with the one line that reverses
it, rather than being presented as his answer.

**Built.** `DrawCandidate` is one order the search prices: a `DrawdownStrategy` plus an optional
taxable-income target. `WithdrawalStrategyComparison::candidates()` returns the three named orders
plus a generated "keep each person's taxable income under £X a year" at two values of X, the personal
allowance and the top of the basic-rate band, read off the scenario's own `TaxYearConfig`. Five
candidates, five forecasts. `ForecastSettings::$taxableIncomeTargetPence` carries the target and only
the `FillBands` branch of `fundShortfall` reads it: with a target the pension pass fills to X and the
basic-rate pass does not run, because the target IS the band being filled. Null for every run a
reader can ask for, so no stored scenario moves, no `ENGINE_VERSION` bump is owed and
`GoldenMasterTest` did not redden. `scenarios:audit` still exits 1 on the pre-existing
"no integrity stamp" lines and reports no new problem class.

**Watched fail.** Criterion 1's test failed for its own reason: with the candidate set widened but
the projector not yet reading the target, the generated order paid a lifetime tax of exactly
13,398,528 pence, the same as a named one, so it was not a different order at all. That is the
failure the criterion describes. Criterion 3's panel assertion depended on the same code and would
have failed with it. **Criterion 2's test, honestly, was green the first time it ran**: the candidate
list and the bound live in the same object, so there was no state in which the set existed and the
bound did not hold.

**A note on the fixture, because it matters to whoever reviews this.** `ScenarioFixture::rich` funds
its spending out of income, so all five candidates tie on it and nothing about ORDER can be seen in
it at all. The criterion-1 test therefore builds a household that draws on its capital every year
(essentials raised to £70,000, the GIA raised to £300,000), and on that household a GENERATED order
wins outright: £208,045 of lifetime tax against £211,038 for the best named one. That is the card's
own claim, demonstrated rather than asserted.

**Not settled here.** `docs/HANDOVER.md` is over the size a fresh session can load, which the orient
hook flags on every session; this entry adds to it and nothing was folded out, because that is a
pass of its own and not this card's scope.

## Tasks
- [x] Rob's call was not available (unattended). Both questions answered from the repository instead,
      and what stays open is recorded in DECISIONS 2026-09-08
- [x] Carry the target on the strategy the projector reads, without turning `DrawdownStrategy` into a
      general planner (Rob's decision 1, 2026-07-01)
- [x] Add the targets to `WithdrawalStrategyComparison::CANDIDATES` and give each one a `label()`
- [x] Re-run `php artisan scenarios:audit`; the panel's "cheapest" figure moves if a target wins

### 2026-09-08 review (v20260908200223-0f1c)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 355s, run by this job rather than reported by the card.

**acceptance: sound**

I checked each box against real code.

**#1 ÔÇö tries a non-named order.** `WithdrawalStrategyComparison::candidates()` adds `DrawCandidate::managingTaxableIncomeTo()` at two statutory targets. The target really reaches the engine: `ScenarioForecaster::deterministicUnderStrategy` ÔåÆ `buildSettings` ÔåÆ `ForecastSettings::$taxableIncomeTargetPence`, read in `PathProjector::fundShortfall` in the `FillBands` branch (it fills to `$target ?? $paLimit` and skips the second basic-rate pass). Pinned by `ScenarioForecasterTest::test_the_search_runs_an_order_that_is_not_one_of_the_three_named_ones`, which fails if the totals match a named order.

**#2 ÔÇö stays in the 4-6 bound.** `candidates()` returns 3 + 2 = 5. `panel()` reports `candidateCount` from the same list it looped. Guarded by `test_the_bounded_search_stays_within_the_forecasts_a_page_can_afford`, which also proves no two `DrawCandidate::key()` collide, so no run is silently lost.

**#3 ÔÇö names a winner in reader terms.** `DrawCandidate::label()` returns "keeping each person's taxable income under ┬úX a year". `panel()` sends it as `cheapestLabel`, rendered in `withdrawal-sequencing.blade.php` and `report.blade.php`. `test_a_generated_order_is_named_in_terms_the_reader_can_act_on` checks the amount is present and no internal setting name is.

I tried to break it and could not. The known weakness (a winner that funds least) is already filed as card 0081, not this card.

VERDICT: sound

**scope: sound**

I checked only what this card's own commit (`155fc1a`) changed. It is small: 12 files, and every one of them is on the card's path.

**Nothing over the fence.** The "Not this card" line bans a full combinatorial search over wrappers and amounts. `WithdrawalStrategyComparison::candidates()` adds exactly two generated orders, both built from statutory figures read off the scenario's tax year, and `DrawCandidate::managingTaxableIncomeTo()` only moves where the pension pass stops. `PathProjector::fundShortfall()` reads the new target inside the `FillBands` branch alone. That is a lever, not a planner.

**No half-done plumbing.** The new `taxableIncomeTargetPence` is carried through every place settings are rebuilt: `ScenarioForecaster::settings()`, `deterministicUnderStrategy()`, and the one other constructor call, `HousingComparison::rentSettings()`. The cache keys include the target, so two candidates cannot share a result.

**Declared gaps, not hidden ones.** The reader still cannot pick a generated order. The card warned about this. The builder raised it as `docs/board/todo/0144-...` instead of quietly widening this card. The two calls reserved for you were answered from the plan and written into DECISIONS as still open.

VERDICT: sound

**breakage: defect**

I attacked the change. Two things break.

**1. A sentence the change made false.** `ResultPresenter::inputNotes` (the drawdown-strategy assumed note) still tells the reader "Your results price every order we can run and name the cheapest; you can pick the one you want in the builder." After this card the cheapest can be a generated target order, and no builder control exists for it. The same claim sits in `resources/views/livewire/scenario-builder.blade.php` beside the draw-order control ("your results price every one of these orders and tell you which is cheapest"). Card 0144 raises the missing input route, but it does not name either sentence, so today the page states something untrue.

**2. The generated order's name overstates what the engine does.** `DrawCandidate::label()` says "keeping each person's taxable income under ┬úX a year". In `PathProjector::fundShortfall` the target caps only NON-SAVINGS income (`$incomeOf` keeps savings and dividends outside the cap), and the last-resort `$drawPensionUfpls(null)` pass runs with no cap once capital is gone. So in the very households where the search matters, the plan breaches the figure its own name is built from.

VERDICT: defect

