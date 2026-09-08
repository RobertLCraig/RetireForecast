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
- [ ] #1 THE APP SHALL NOT report a draw order as cheapest when it leaves more of the household's
      spending unfunded than the order in place does.
- [ ] #2 WHEN candidate orders do not all fund the same spending, THE APP SHALL say so on the panel
      rather than comparing their tax silently.
- [ ] #3 THE APP SHALL keep the reported saving the difference of two of the engine's own runs
      (card 0007 acceptance #5 must not regress).
<!-- AC:END -->

## Tasks
- [ ] Reproduce it first: build a household that depletes under one named order and not another, and
      show the panel naming the depleting one
- [ ] Decide with Rob what a reader should see when the orders are not comparable: hide the winner,
      or show it with the shortfall beside it
- [ ] Read `ForecastResult::$fullSpendAlwaysMet` / `$depletionCalendarYear` in
      `WithdrawalStrategyComparison::for`, not a re-derivation from the year list
- [ ] Re-run `php artisan scenarios:audit`
