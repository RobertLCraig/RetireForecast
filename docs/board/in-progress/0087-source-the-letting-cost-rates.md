# Pin the three letting-cost rates to a published source

## Why
The engine now takes **25% of gross rent** off a let property before it is banked or taxed: 12%
management, 8% void, 5% maintenance (`Property::DEFAULT_LETTING_MANAGEMENT_BPS` and its two
siblings, built by card 0030). A quarter of the rent is the difference between a let that pays and
one that loses money every month, so these three figures decide whether letting the home out ranks
above selling it.

They are the property reviewer's judgement in the 2026-08-19 expert review. They are **not published
statistics**. `docs/spec/ASSUMPTIONS.md` §14 says so out loud, and they are the third sourcing gap in
that document alongside the property-cost escalator (card 0085) and the volatility multiple
(card 0086).

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0030 could ship the mechanism, disclose all three figures and expose them as inputs, but
could not go and check them.

## Links

**Relates to**
- `0085` - the same shape of gap, from the same review and the same missing web access, on the
  property-cost escalator. Whoever picks one up can settle all three in one research pass.
- `0086` - likewise, on the single-property volatility multiple.
- `0030` - built the mechanism, disclosed all three figures and exposed them as inputs, but had no
  web access to check them.

## Not this card
Changing the mechanism. How the deduction is applied, disclosed and edited is card 0030's and is
built. This card only replaces three numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for each of the three letting-cost rates in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one and the disclosure SHALL move with it. proves: `test_the_assumed_letting_costs_are_disclosed_with_their_values`
<!-- AC:END -->

## Tasks
- [ ] Find published figures for each: a UK letting-agent fully-managed fee survey (management),
      an ARLA Propertymark or Rightmove void-period statistic (void), and a landlord repairs and
      compliance cost series (maintenance).
- [ ] Set the three constants in `packages/finance-engine/src/Dto/Property.php` to what the sources
      say, or record on this card why the reviewer's figures stand.
- [ ] Update ASSUMPTIONS.md §14, moving it out of the sourcing-gap list, and add the citations to the
      source list at the foot of that file.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any figure moves, and note that stored let
      scenarios need re-running.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. The three figures are
constants in `packages/finance-engine/src/Dto/Property.php`; the results-page disclosure READS them,
so changing one moves the screen with no other edit. Run `php artisan test --testsuite=Engine`
after, then the full suite.

## Comments

**2026-09-23** RESULT: blocked
TESTS: +0 new, suite not changed
TOUCHED: docs/board/in-progress/0087-source-the-letting-cost-rates.md
OUT-OF-SCOPE: none
This session could not use WebSearch or WebFetch; the permission for both was refused, as it was on
card 0086. The repository has no source for the rates either: nothing in docs/research/ or
docs/spec/ gives a management-fee, void or maintenance figure with a URL. ASSUMPTIONS.md §14 still
records the gap. Both criteria are open. The second one only applies once a sourced figure exists,
so I wrote no test for it and left the constants in `Property.php` alone. As the Plan says, this
card needs a session with web access, and it should do 0085 and 0086 in the same pass. Unattended
runs will keep blocking here until web access is granted to the loop.

**2026-09-23** RESULT: blocked
TESTS: +0 new, suite not changed
TOUCHED: docs/board/in-progress/0087-source-the-letting-cost-rates.md
OUT-OF-SCOPE: none
This is the second run today, and nothing has changed. WebSearch and WebFetch were both refused on
their first call. A grep of docs/ outside the board still finds the gap only at ASSUMPTIONS.md
§14 (lines 231-232), with no source anywhere. Both criteria are still open, and `Property.php`
has not been touched. Repeating this run won't help. Rob needs to either grant web access to the
unattended loop, or do the research pass (0085, 0086 and 0087 together) in an attended session.

**2026-09-23** RESULT: blocked
TESTS: +0 new, suite not changed
TOUCHED: docs/board/in-progress/0087-source-the-letting-cost-rates.md
OUT-OF-SCOPE: none
Third run today, same result. WebSearch and WebFetch were each refused on their first call. Nothing
in docs/research/ or DECISIONS.md gives a sourced management, void or maintenance rate, so both
criteria stay open and `Property.php` is untouched. Each run like this one only adds a comment. The
scheduler should stop picking this card until web access is granted, or until Rob does the
research pass for 0085, 0086 and 0087 in an attended session.

**2026-09-23** RESULT: blocked
TESTS: +0 new, suite not changed
TOUCHED: docs/board/in-progress/0087-source-the-letting-cost-rates.md
OUT-OF-SCOPE: none
Fourth run today. WebSearch and WebFetch were refused again, on the first call of each. docs/ still
has no letting-market URL (no ARLA, Propertymark, Rightmove, Zoopla, NRLA or letting-agent source),
so both criteria stay open and `Property.php` is untouched. Nothing on this card can move without
web access or an attended research pass, so the scheduler should stop picking it until one of those
happens. The suite was run with `php artisan test`, because this worktree has no `pest.bat` (card
0150).
