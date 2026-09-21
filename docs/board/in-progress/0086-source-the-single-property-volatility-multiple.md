# Pin the single-property volatility multiple to a published source

## Why
The engine now models the household's own home over **twice** the index house-price volatility:
18% real a year on the default set instead of 9% (`AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`,
built by card 0029). That figure widens the fan on every plan that keeps or buys a home, so it moves
the success probability, the capacity-for-loss headroom and the ranked comparison between staying put
and selling.

The 2.0 is the property reviewer's judgement in the 2026-08-19 expert review. It is **not a published
statistic**. Every other figure in `docs/spec/ASSUMPTIONS.md` cites a primary or fetchable secondary
source; this one and the CPI + 3% property-cost escalator (card 0085) are the two that do not, and
ASSUMPTIONS.md §13 says so out loud.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0029 could ship the mechanism and disclose the figure but could not go and check it.

## Links

**Relates to**
- `0085` - the same shape of gap, from the same review and the same missing web access, on the
  property-cost escalator. Whoever picks one up can settle both in one research pass.
- `0029` - built the mechanism and disclosed this figure, but had no web access to check it, which
  is why the gap is a card of its own.

## Not this card
Changing the mechanism. How the uplift is applied, disclosed and edited is card 0029's and is built.
This card only replaces the number and its citation, which is one constant and one doc section.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the single-property volatility multiple in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN the sourced figure differs from 2.0, THE APP SHALL use the sourced one and the disclosure SHALL move with it. proves: `test_the_uplift_scales_the_index_figure_rather_than_replacing_it`
<!-- AC:END -->

## Tasks
- [ ] Find a published estimate of UK single-property (idiosyncratic) house-price dispersion against
      an index. The repeat-sales literature is the likely home for it; Nationwide and Halifax publish
      index methodology that may quantify the residual.
- [ ] Set `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE` to what the source says, or record on
      this card why 2.0 stands.
- [ ] Update ASSUMPTIONS.md §13, moving it out of the sourcing-gap list, and add the citation to the
      source list at the foot of that file.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if the figure moves, and note that stored runs need
      re-running.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. The figure is one
constant in `packages/finance-engine/src/Dto/AssumptionSet.php`; the disclosure and the assumptions
panel both READ it, so changing it moves every screen with no other edit. Run
`php artisan test --testsuite=Engine` after, then the full suite.

## Comments

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
This unattended session had no web access either. WebSearch and WebFetch were both refused for
permission, the same gap that produced this card. I checked the two local places a source could
already be: the SecondBrain vault (`find.py` for house price volatility, idiosyncratic and repeat
sales found nothing on the subject), and `docs/` in this repository, where only ASSUMPTIONS.md §13
and this card mention the repeat-sales literature, with no citation. Both criteria are left open.
Criterion 1's fallback, "record that the search found none", would be false here, because no search
was run. I did not cite a paper from memory, because a verified_on date needs a fetched page.
Criterion 2 only applies once a figure has been sourced. The constant, ASSUMPTIONS.md and
ENGINE_VERSION are unchanged. **Direction:** this card cannot be built by the unattended loop as it
is set up now. It needs either a session where WebSearch/WebFetch are allowed, or Rob running the
`research` skill interactively, and card 0085 should go in the same pass. Once a figure is sourced,
the build is the constant, §13 and its source-list entry, and an ENGINE_VERSION bump. The existing
test already reads the constant, so it follows the new figure without an edit.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
This is the second unattended run today, and it was blocked the same way. The WebSearch and WebFetch
schemas loaded, but both calls were refused for permission: one search for idiosyncratic UK
house-price dispersion and one fetch of Nationwide's HPI methodology page. A fresh grep of `docs/`
found no source added since the first run. §13 of ASSUMPTIONS.md is still the only mention. Both
criteria stay open for the same reasons as before, and nothing but this entry changed. The suite ran
through `php artisan test` because this worktree has no `vendor/bin/pest.bat` (card 0150).
**Direction:** the scheduler keeps picking this card up, and each unattended run will end here. The
card needs to be held out of the unattended loop until one of two things happens. Either WebSearch
and WebFetch are allowed for the build sessions, or Rob runs the `research` skill interactively,
covering 0085 at the same time. That is Rob's call, not a build.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Third unattended run, blocked the same way. WebSearch (idiosyncratic house-price volatility,
repeat sales, UK) and WebFetch (Nationwide HPI methodology) both loaded and were then refused for
permission. A grep of `docs/` turned up no new source. The only other hit was card 0087, which has
the same kind of sourcing gap. Both criteria stay open, and the constant, ASSUMPTIONS.md and
ENGINE_VERSION are unchanged. The suite ran through `php artisan test` because `vendor/bin/pest.bat`
is still missing in this worktree (card 0150). **Direction:** unchanged from the two entries above.
Nothing new came out of this run, and the next unattended run will end the same way. Holding the
card out of the loop, or allowing web tools for build sessions, is Rob's call.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Fourth unattended run, blocked the same way. WebSearch loaded and was then refused for permission. I
did not try curl from the shell, because that would get round a permission Rob has not granted. Both
criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite ran through
`php artisan test` because `vendor/bin/pest.bat` is still missing here (card 0150). **Direction:**
this is the same as the three entries above. The scheduler should stop picking this card up until
web tools are allowed or Rob researches it interactively.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Fifth unattended run, blocked the same way. WebSearch loaded and was then refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite
ran through `php artisan test` because `vendor/bin/pest.bat` is still missing here (card 0150).
**Direction:** unchanged. Five runs in a row have ended on the same refusal. Until web tools are
allowed for build sessions, or Rob researches this interactively along with 0085, every further run
spends a full suite pass (about 7 minutes) and produces nothing but another entry like this one.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Sixth unattended run, blocked the same way. WebSearch loaded and was refused for permission on the
first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite ran
through `php artisan test` because `vendor/bin/pest.bat` is still missing (card 0150).
**Direction:** unchanged from the five entries above. This needs Rob's call: hold the card out of
the loop, or allow web tools for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Seventh unattended run, blocked the same way. WebSearch loaded and was refused for permission on the
first query. Both criteria stay open. The code and ASSUMPTIONS.md are unchanged, and only this card
file was edited. The suite ran through `php artisan test` because `vendor/bin/pest.bat` is still
missing (card 0150). **Direction:** unchanged. This needs Rob's call: hold the card out of the loop,
or allow web tools for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Eighth unattended run, blocked the same way. WebSearch loaded and was refused for permission on the
first query. Both criteria stay open. The code and ASSUMPTIONS.md are unchanged. The suite ran
through `php artisan test` because `vendor/bin/pest.bat` is still missing (card 0150).
**Direction:** unchanged. This needs Rob's call: hold the card out of the loop, or allow web tools
for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Ninth unattended run, blocked the same way. WebSearch loaded and was refused for permission on the
first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite ran
through `php artisan test` because `vendor/bin/pest.bat` is still missing (card 0150).
**Direction:** unchanged. This needs Rob's call: hold the card out of the loop, or allow web tools
for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Tenth unattended run, blocked the same way. WebSearch loaded and was refused for permission on the
first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite ran
through `php artisan test` (1604 passed, 1 skipped) because `vendor/bin/pest.bat` is still missing
(card 0150). **Direction:** unchanged. This needs Rob's call: hold the card out of the loop, or
allow web tools for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Eleventh unattended run, blocked the same way. WebSearch loaded and was refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged.
**Direction:** unchanged. This needs Rob's call: hold the card out of the loop, or allow web tools
for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Twelfth unattended run, blocked the same way. WebSearch loaded and was refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite
ran through `php artisan test` (1604 passed, 1 skipped) because `vendor/bin/pest.bat` is still
missing (card 0150). **Direction:** unchanged. This needs Rob's call: hold the card out of the
loop, or allow web tools for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Thirteenth unattended run, blocked the same way. WebSearch loaded and was refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite
ran through `php artisan test` (1604 passed, 1 skipped) because `vendor/bin/pest.bat` is still
missing (card 0150). **Direction:** unchanged. This needs Rob's call: hold the card out of the
loop, or allow web tools for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Fourteenth unattended run, blocked the same way. WebSearch loaded and was refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged.
**Direction:** unchanged. This needs Rob's call: hold the card out of the loop, or allow web tools
for build sessions.

**2026-09-21** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0086-source-the-single-property-volatility-multiple.md
OUT-OF-SCOPE: none
Fifteenth unattended run, blocked the same way. WebSearch loaded and was refused for permission on
the first query. Both criteria stay open, and the code and ASSUMPTIONS.md are unchanged. The suite
ran through `php artisan test` (1604 passed, 1 skipped) because `vendor/bin/pest.bat` is still
missing (card 0150). **Direction:** unchanged. This needs Rob's call: hold the card out of the
loop, or allow web tools for build sessions.
