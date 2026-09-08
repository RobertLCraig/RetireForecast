# The "cheapest draw order" can be the one that funds the least

## Why
The results page picks a winning draw order by one number: the tax the plan pays, now counting both
the tax paid year by year and the Inheritance Tax the estate pays at the end
(`App\Forecast\WithdrawalStrategyComparison::lifetimeTax`).

That number is the right one **only while every order funds the same spending**. It normally does:
the household's spend target does not change with the order money is taken in, so less tax means
more left over, and the cheapest order really is the best one. The comparison rests on that.

It stops being true the moment an order runs out of money. A plan that cannot meet its spend stops
drawing, so it stops paying tax, and a smaller estate pays less at death as well. Both halves of the
metric fall. So of two orders, the one that leaves the reader's spending **unfunded** can be reported
as "the cheapest", and the steer behind the `interpret` gate then tells them to lean towards it.

Nothing warns anybody. `RetireForecast\FinanceEngine\Forecast\ForecastResult` carries
`$fullSpendAlwaysMet`, `$essentialsAlwaysMet` and `$depletionCalendarYear` for exactly this, and the
comparison reads none of them. This is the household this tool is built for: one close enough to
running out that the question matters.

## Links

**Relates to**
- `0078` - widening the candidate set of draw orders is that card; this one fixes how a candidate
  is scored.
- `0075` - letting the reader choose the order is that card, and is likewise not this one.

## Not this card
Widening the candidate set (card 0078) or letting the reader choose the order (card 0075). This is
about the number the winner is picked by, whatever the candidates are.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL NOT report a draw order as cheapest when it leaves more of the household's
      spending unfunded than the order in place does.
- [x] #2 WHEN candidate orders do not all fund the same spending, THE APP SHALL say so on the panel
      rather than comparing their tax silently.
- [x] #3 THE APP SHALL keep the reported saving the difference of two of the engine's own runs
      (card 0007 acceptance #5 must not regress).
<!-- AC:END -->

## Tasks
- [x] Reproduce it first: build a household that depletes under one named order and not another, and
      show the panel naming the depleting one
- [ ] Decide with Rob what a reader should see when the orders are not comparable: hide the winner,
      or show it with the shortfall beside it
- [x] Read `ForecastResult::$fullSpendAlwaysMet` / `$depletionCalendarYear` in
      `WithdrawalStrategyComparison::for`, not a re-derivation from the year list
- [x] Re-run `php artisan scenarios:audit`

## Comments

**2026-09-08**
RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Forecast/WithdrawalStrategyComparison.php
TOUCHED: resources/views/livewire/partials/withdrawal-sequencing.blade.php
TOUCHED: resources/views/pdf/partials/report.blade.php
TOUCHED: tests/Feature/Forecast/ScenarioForecasterTest.php
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

Reproduced first, on a real household, not a stub: the rich fixture spending £45,000 a year funds
every year of the plan under "drawing your pension first" and about 58% of them under "spending your
savings first", which pays £23,000 LESS lifetime tax for exactly the reason the card names. With the
pension-first order stored as the reader's own, the panel named the order funding least as the
cheapest; the new test watched that fail on that sentence before the fix.

The fix is a filter, not a new score. `funding()` reads the run's own report and
`fundsAtLeastAsMuchAs()` keeps a candidate in the running only where no measure is worse than the
order in place, so `lifetimeTax()` and the "difference of two engine runs" rule are untouched
(criterion 3 stays pinned by `test_the_optimiser_returns_the_cheapest_candidate_and_reconciles_to_two_engine_runs`,
which was green before and after and was not edited).

One deliberate widening of the third Task. It says read `$fullSpendAlwaysMet` and
`$depletionCalendarYear`; those alone do not fix the fault. They are all-or-nothing, and on a
household where EVERY order runs short they tie exactly while the orders still fund very different
amounts (measured: 0.364 vs 0.515 of the plan's years, same flags, same depletion year), so the
cheapest-funding-least case survives untouched. So `funding()` also reads
`essentialsYearsMetFraction()` and `fullSpendYearsMetFraction()`, which are the DTO's OWN honest
companions to those flags. Nothing is re-derived from the year list inside the comparison, which is
what the Task was guarding.

The second Task is left open because it is Rob's: the acceptance only requires the panel to SAY the
orders are not comparable, which it now does on both the screen and the PDF, and the winner is still
named (it can now only be an order funding at least as much as the reader's own). Whether the
shortfall should be printed beside it is not built. See DECISIONS 2026-09-08.

`scenarios:audit` was re-run. It exits non-zero with 123 problems, all pre-existing DB state and none
a new class: stale assumption sets missing the card 0064 figures, and stored runs predating the
integrity-stamp column. Nothing here moves a stored figure. Built in a worktree, so the new panel
sentence has not been seen in a browser.
