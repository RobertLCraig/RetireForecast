# Pin the three letting-cost rates to a published source

## What I need from you
Nothing. The A/B question was decided on 2026-09-29 (see Comments), and the web research is done:
`docs/research/RESEARCH-0087-letting-cost-rates.md` (2026-10-11). Build from that note. Do not go to the web.

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
- `0084` - asked whether the unattended loop may use the web; Rob's ruling there is the
  research-only session this card's `waiting_on:` names.

## Not this card
Changing the mechanism. How the deduction is applied, disclosed and edited is card 0030's and is
built. This card only replaces three numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [x] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for each of the three letting-cost rates in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [x] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one and the disclosure SHALL move with it. proves: `test_the_assumed_letting_costs_are_disclosed_with_their_values`
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

**2026-09-23** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-28** Manager pass: this card's text was restored from commit `51b1442`. Commit `36d2291` ("board: 0088 what the session left uncommitted") had committed it as an empty file. That commit also emptied one other card, `...running-costs-are-charged-as-household-spend.md`, which this pass has not touched. The card is left in `human-review/` with an ask: four unattended takes ended on the same WebSearch refusal, and only Rob can decide whether the loop gets the web (card 0084).

**2026-09-29** **Decided:** B, in the narrower form Rob ruled on 0084 on 2026-09-28: the unattended loop gets the web only in a separate research session that can write notes under `docs/research/` and nothing else, and a later build session with no web builds this card from the note. Settled by an attended agent under Rob's rule that human-review holds only what he must decide: this card's A-or-B ask is the question 0084 asked, and Rob answered it there. The research session is `progressboard#0211`, not yet built, so this card carries `waiting_on:` until it lands, which keeps the build loop from spending another take on the refusal.

**2026-10-13** `waiting_on:` removed. The research this card waited for was done in an attended session on 2026-10-11 and is now committed as `docs/research/RESEARCH-0087-letting-cost-rates.md`, so no research session is needed: build from the note. Attended session, on Rob's go.

**2026-10-13** RESULT: done
TESTS: +1 new (test_the_letting_rate_placeholders_show_the_sourced_defaults), 1 extended (test_the_assumed_letting_costs_are_disclosed_with_their_values), 1 re-examined (test_the_finance_cost_reducer_is_read_off_rental_profit_not_gross_rent), all green
TOUCHED: packages/finance-engine/src/Dto/Property.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
docs/spec/ASSUMPTIONS.md
docs/HANDOVER.md
tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
tests/Feature/Livewire/ScenarioBuilderTest.php
packages/finance-engine/tests/Forecast/LettingCostsTest.php
OUT-OF-SCOPE: none

Built from docs/research/RESEARCH-0087-letting-cost-rates.md, no web used. Management 1_200 -> 1_800 (18% inc VAT), void stays 800, maintenance 500 -> 800; total 25% -> 34%. ASSUMPTIONS 14 now carries a source URL and verified_on 2026-10-11 for each rate, says no primary series exists (EPLS 2024 publishes none), and says the maintenance 8% is derived arithmetic on the Pegasus survey, not a published rate; seven URLs added to the source list. ENGINE_VERSION -> finance-engine/letting-costs-sourced-at-34-percent; stored let plans with a blank rate need re-running (HANDOVER notes it).

#2: the proves test read the constants, so it could not fail on a value change. I pinned the sourced literals ('18% for letting-agent', '8% for repairs', '34% of it in all') and watched it fail on the old 12%. It also asserts the prose no longer says 'quarter': ResultPresenter restated the old total as 'usually about a quarter of the rent', now 'a large share of the rent'. The builder's three letting placeholders were hard-coded 12/8/5, so a blank input showed a figure the engine no longer charges; they now read the constants via ScenarioBuilder::lettingDefaultPct(). New Livewire test watched red on placeholder 12 first.

Re-examined, not loosened: LettingCostsTest::test_the_finance_cost_reducer_is_read_off_rental_profit_not_gross_rent went red because it hard-coded the 25% (24,000 rent -> 18,000 profit). It now derives the net-rent twin from the three constants, so its claim (same profit, same tax) is unchanged.

Not settled here: the step-3 placeholders and results-page sentence are NOT seen in a browser (Herd serves C:\Dev\RetireForecast, not this worktree). The void help text still says '8% is about a month a year' in prose; the void did not move, so I left it.
