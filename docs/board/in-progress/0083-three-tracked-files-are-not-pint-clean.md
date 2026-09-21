# Three tracked files are not Pint-clean, so a plain `pint` run rewrites work nobody asked it to

## Why
House style is Pint, and the documented command is `vendor/bin/pint --dirty` — only the files the
current change touched. That flag is hiding a drift: run Pint over the whole repository and three
tracked files come back dirty, none of them recently edited.

    app/Forecast/LumpSumTaxShock.php
    app/Forecast/QuickWhatIf.php
    packages/finance-engine/src/Pension/TaxFreeCashCalculator.php

The fixers are all cosmetic — `ordered_imports`, `braces_position`, `fully_qualified_strict_types`,
`unary_operator_spaces`, `not_operator_with_successor_space`, `single_line_empty_body`.

The cost is small and it lands on the wrong person. Anyone who runs plain `pint` instead of
`--dirty` gets a diff in three files they did not touch, mixed into their own change, and has to
decide whether to keep it or unpick it. It is also why `pint --test` cannot be used as a green/red
gate today: it exits 1 on a clean tree.

Nobody chose this. `--dirty` means a file only gets formatted when it is next edited, so a rule
added to `pint.json` after these files were last touched simply never reached them.

## Links

**Relates to**
- `0027` - found while working that card, which changed no PHP, so none of this is its doing.

## Not this card
Changing the Pint ruleset, or making Pint a CI gate. This is only bringing three files up to the
ruleset already in force. Found while working card 0027, which changed no PHP.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN `vendor/bin/pint --test` runs over the whole repository on a clean tree, THE APP SHALL exit zero. proves: manual — Pint is the formatter, not something the suite runs; the check is the command itself.
- [x] #2 THE APP SHALL keep the test suite green across the reformat, since the change is cosmetic only. proves: none — no single test names this; it is the whole suite, run before and after.
<!-- AC:END -->

## Tasks
- [x] Run `vendor/bin/pint` (no `--dirty`) from the project root
- [x] Read the diff and confirm every hunk is formatting, not behaviour
- [x] Run `php artisan test` and confirm it is still green

## Comments

**2026-09-08**
RESULT: done
TESTS: +0 new, all green
TOUCHED: packages/finance-engine/src/Pension/TaxFreeCashCalculator.php
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

Only ONE of the three files the card names was still dirty. `app/Forecast/LumpSumTaxShock.php` and
`app/Forecast/QuickWhatIf.php` were both brought up to the ruleset by cards worked between this card
being written and it being picked up, since `--dirty` formats a file the moment it is next edited.
Nothing was needed for them and neither was touched.

The remaining file is `packages/finance-engine/src/Pension/TaxFreeCashCalculator.php`, fixed by
`fully_qualified_strict_types`, `braces_position`, `single_line_empty_body` and `ordered_imports`.
The whole diff is two lines: `fully_qualified_strict_types` shortened the docblock's
`{@see \RetireForecast\FinanceEngine\Forecast\PathProjector::ufplsSplit}` to `{@see
PathProjector::ufplsSplit}` and hoisted the matching `use` to the top. The other three fixers found
nothing left to change once that one had run. No statement, no signature and no expression moved.

That hoist is the one thing worth a second look on an ENGINE file, because Pint turning a docblock
reference into a real `use` is how engine isolation has been broken here before. It is safe this
time: the class hoisted is `RetireForecast\FinanceEngine\Forecast\PathProjector`, engine code
already, so the file imports nothing from `App\` or `Illuminate\` and the framework-free rule still
holds. `EngineIsolationTest` agrees.

Both criteria are `manual` and `none`, so no test was written for them; the checks are the two
commands themselves. `vendor/bin/pint --test` now reports `passed` and exits zero on a clean tree,
which is criterion #1 and which also means `pint --test` can be used as a gate from here. The full
suite was run after the reformat and is green, which is criterion #2.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/board/todo/0147-the-documented-test-command-runs-out-of-memory.md
TOUCHED: docs/board/todo/0148-card-0117-asks-for-work-that-card-0083-has-already-done.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: 0147, 0148

Picked this card up in a fresh worktree and found the build already on disk and committed
(`133a7dc`), so this run is a verification rather than a second build. Nothing about the reformat
was redone and no PHP file was touched. Both criteria are `manual` and `none`, so there is no test
to write; the checks are the two commands, and both were re-run here rather than read off the entry
above.

`vendor/bin/pint --test` on a clean tree reports `{"tool":"pint","result":"passed"}` and exits zero,
which is criterion #1. The suite is green — 1605 tests, 1604 passed, 1 skipped, 16,785 assertions —
which is criterion #2. The ticks above were already set by the run that did the work and are left as
they are.

Two things had to be settled before the suite would run at all, and both are the environment rather
than this card:

The worktree's copied `vendor/` had a stale autoload map pointing at a `retireforecast/finance-engine`
path that does not exist here, so PHPUnit died loading the engine tests. `composer update
retireforecast/finance-engine` fixed it, exactly as `CLAUDE.md` says it should, and left
`composer.lock` unchanged.

`php artisan test` then died on a PHP fatal error — 128M exhausted, the Herd CLI default — partway
through the Feature suite, before it had run everything. That is the documented command failing, not
a test failing, so it is carded as **0147** rather than worked around quietly. The green figures
above come from `php -d memory_limit=1G vendor/bin/phpunit`, which is the same suite with headroom.

The other finding is **0148**: card `0117` in `todo/` asks for this card's work, in narrower terms,
with both criteria still open. It was written first and never picked up. A session taking it next
would find it already green and have to choose between a tick it did not earn and a card it cannot
close. I have not edited `0117`.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/board/todo/0149-a-worktree-can-start-with-the-engine-source-deleted.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: 0149

Third pick-up of this card, and like the second it is a verification: the reformat was already on
disk and committed, no PHP file was touched, and no tick was changed. Both criteria are `manual` and
`none`, so there is nothing to write a failing test against; the checks are the two commands, and
both were re-run here rather than read off the entries above.

`vendor/bin/pint --test` over the whole repository on a clean tree reports
`{"tool":"pint","result":"passed"}` and exits zero, which is criterion #1. The suite is green — 1605
tests, 1604 passed, 1 skipped, 16,785 assertions, the same counts as the previous run — which is
criterion #2. The suite was run as `php -d memory_limit=1G vendor/bin/phpunit`, because card `0147`
is still open and the documented `php artisan test` still dies at the 128M Herd default. There is no
`vendor/bin/pest.bat` in this project; the runner is PHPUnit.

One thing had to be settled before anything could run, and it is the reason this entry exists rather
than being a second copy of the one above. **This worktree started with the entire
`packages/finance-engine` source deleted from its working tree** — 289 tracked files, the directory
present and empty, index and branch untouched. It is carded as **0149** rather than fixed quietly,
because the cause is in the scheduler's worktree tooling and not in this repository, and because the
expensive outcome is a session that does not look at `git status` committing those deletions onto a
card about formatting. Recovery here was `git checkout -- packages/finance-engine` followed by
`composer update retireforecast/finance-engine`, which restored the files and re-created the vendor
link; `composer.lock` is unchanged and no tracked file carries a change from it.

What I could not settle from the repository is what emptied it. The observed state is consistent
with a recursive delete following the Windows junction Composer uses to wire a path package, but the
tooling that would do it lives in `C:\Dev\ProgressBoard` and I did not read it. Card 0149 says
exactly that rather than asserting a cause.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0149-a-worktree-can-start-with-the-engine-source-deleted.md (written by the
previous run, uncommitted on disk when I arrived; committed unchanged with this entry)
OUT-OF-SCOPE: none new — 0149 already covers the fault I hit

Fourth pick-up, and again a verification: the reformat is on disk and committed, no PHP file was
touched, no tick was changed. Both criteria are `manual` and `none`, so there is no failing test to
write for either; the checks are the two commands and both were re-run here rather than read off the
entries above. `vendor/bin/pint --test` over the whole repository on a clean tree reports
`{"tool":"pint","result":"passed"}` and exits zero — criterion #1. The suite is green: 1605 tests,
1604 passed, 1 skipped, 16,785 assertions, identical to the previous two runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`, because card `0147` is still open. This project has no
`vendor/bin/pest.bat`; the runner is PHPUnit, and the scheduler's standing instruction to run Pest
cannot be followed here.

What this run adds is one fact the previous entry could not know: **the engine deletion of card 0149
happened AGAIN in this same worktree, after that run had already recovered from it.** I arrived to
find `packages/finance-engine` emptied a second time and the previous run's docs edits still
uncommitted beside it — which is the exact shape 0149 warns about, a session finding 289 deletions
staged up against a formatting card. Recovery was the two commands 0149 records and they worked
unchanged. I have not edited 0149; the recurrence is noted in HANDOVER, whose bullet previously
implied one recovery settles it.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0150-every-card-session-is-told-to-run-a-test-runner-this-project-does-not-have.md
OUT-OF-SCOPE: 0150

Fifth pick-up, and a verification like the three before it: the reformat is on disk and committed,
no PHP file was touched, no tick was changed. Both criteria are `manual` and `none`, so there is no
failing test to write for either — the checks are the two commands, and both were re-run here rather
than read off the entries above. `vendor/bin/pint --test` over the whole repository, on a tree
`git status` reported clean, gives `{"tool":"pint","result":"passed"}` and exit 0 — criterion #1.
The suite is green: 1605 tests, 1604 passed, 1 skipped, 16,785 assertions, identical to all three
previous runs — criterion #2.

The engine deletion of card `0149` happened a THIRD time, again in this same worktree and again
after a previous run had recovered from it. Recovery was the two commands 0149 records, unchanged
and losing nothing. I have not edited 0149. What I changed is the HANDOVER bullet, which said it had
been seen "TWICE" and so still read as something a recovery might settle: it now says it recurs on
every pick-up, which is what the evidence supports and is the one fact this run adds.

The new card is **0150**. The scheduler's standing instruction tells every card session to run
`.\vendor\bin\pest.bat`; this project has no such file and never has — the runner is PHPUnit. Three
runs of this card each hit that, each substituted something, and each wrote it down only in prose in
this thread, which is why it is now a card rather than a fourth paragraph. It bites harder while
`0147` is open, because the documented fallback `php artisan test` does not finish either, so a
session is left with no command it was told about that works. I could not settle which side should
move — correct the instruction, or adopt Pest — because the template lives in `C:\Dev\ProgressBoard`
and I did not read it; the card says exactly that rather than guessing.

The suite here was run as `php -d memory_limit=1G vendor/bin/phpunit`, for the reason `0147` gives.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0151-the-handover-is-too-big-for-the-session-it-is-written-for.md
OUT-OF-SCOPE: 0151

Sixth pick-up, and a verification like the four before it: the reformat is on disk and committed, no
PHP file was touched, no tick was changed. Both criteria are `manual` and `none`, so there is no
failing test to write for either — the checks are the two commands, and both were re-run here rather
than read off the entries above. `vendor/bin/pint --test` over the whole repository, on a tree
`git status` reported clean, gives `{"tool":"pint","result":"passed"}` and exit 0 — criterion #1.
The suite is green: 1605 tests, 1604 passed, 1 skipped, 16,785 assertions, identical to all four
previous runs — criterion #2. Run as `php -d memory_limit=1G vendor/bin/phpunit`, because `0147` is
still open and there is still no `vendor/bin/pest.bat` (`0150`).

The engine deletion of card `0149` happened a FOURTH time, in this same worktree, again after a
previous run had recovered from it. This run adds no new fact about it: the HANDOVER bullet already
says it recurs on every pick-up, and that is now what the evidence keeps showing rather than
something this entry revises. Recovery was the two commands 0149 records — `git checkout --
packages/finance-engine`, then `composer update retireforecast/finance-engine` — unchanged, losing
nothing, and `composer.lock` untouched. I have not edited 0149. Unlike the previous run I arrived to
a tree carrying nothing but those 288 deletions, so no earlier session's work was left uncommitted
beside them.

The new card is **0151**, and it is the one thing here that was not already carded. The project's
own `SessionStart` hook prints, at the top of every session, that `docs/HANDOVER.md` is over the
budget a fresh session can load — so the first document every agent is told to read cannot be read
in full by the session it briefs. I measured rather than repeated it: the file is 78,981 bytes, and
the unstructured exception list before the first `##` heading is 59,958 of them, 76% of the file
across 42 bullets that nothing prunes. `HandoverHygieneTest` guards the doc's shape but not its
size, which is how it reached twice the budget with the suite green throughout. Six consecutive
pick-ups of this card have now read that hook output and had no scope to act on it, which is why it
is a card instead of a seventh paragraph. I did not fold anything: choosing what is stale is a
judgement about live risk, and a fold nothing holds in place is undone within a few cards.

What I could not settle from the repository is the budget itself. The hook's "~40 KB" is the only
figure stated anywhere here, and no doc or test ratifies it, so 0151 asks for it to be agreed rather
than asserting it.

The one HANDOVER edit is a clause on the existing `0147` bullet recording that `vendor/bin/pest.bat`
does not exist and PHPUnit is the runner. That belongs there because a session is told to run Pest
before it reads anything, and the bullet it now sits on is the one that answers "what gives me a
green". I deliberately added no bullet for 0151: the hook already announces it unprompted at every
startup, and a file carded for being too long is a poor place to spend four more lines saying so.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
TOUCHED: docs/board/todo/0152-a-card-with-every-criterion-ticked-stays-in-in-progress-and-is-picked-up-again.md
OUT-OF-SCOPE: 0152

Seventh pick-up, sixth verification: the reformat is on disk and committed, no PHP file was touched,
no tick was changed. Both criteria are `manual` and `none`, so there is no failing test to write for
either — the checks are the two commands, and both were re-run here rather than read off the entries
above. `vendor/bin/pint --test` over the whole repository, on a tree `git status` reported clean,
gives `{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests,
1604 passed, 1 skipped, 16,785 assertions, identical to all five previous runs — criterion #2. Run
as `php -d memory_limit=1G vendor/bin/phpunit`, because `0147` is still open and there is still no
`vendor/bin/pest.bat` (`0150`).

The engine deletion of card `0149` happened a FIFTH time, and recovery was the two commands that
card records, unchanged and losing nothing; `composer.lock` untouched. The tree carried nothing but
those 288 deletions. This adds no new fact — HANDOVER already says it recurs on every pick-up — so I
changed nothing there. Nothing else in HANDOVER needed an edit either: every bullet a fresh session
would read against what I saw is already accurate, and 0151 has that file carded for being twice the
size a session can load, so a run with no new fact should not be adding lines to it.

The one thing this run adds is card **0152**, and it is about this card rather than about the
project. `0083` was built and both criteria ticked on 2026-09-08 in commit `133a7dc`. Four
re-verification commits followed on 2026-09-20 and mine is the fifth, so five sessions have now been
handed a finished card and produced no work it asked for — each writing a `RESULT: done` entry
saying so in its own words. The thread is longer than the card. Previous runs each recorded the
recurrence as prose here, which is exactly how 0149 and 0150 survived being found repeatedly, so
this time it is a card. It also means `0083` has never reached `ai-review/`, and a card that produced
code has not been adversarially reviewed.

What I could not settle is why the move does not fire. The scheduler's standing instruction says it
reads the ticked acceptance to decide the next lane, and the ticks have said "met" for twelve days;
but that logic lives in `C:\Dev\ProgressBoard` and I did not read it, so 0152 says that rather than
naming a cause. I did not move this card or any other — the scheduler owns lane moves, and an agent
shoving a card across would hide the very mechanism the card is about.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0147, 0149, 0150, 0151, 0152)

Eighth pick-up, seventh verification. The reformat is on disk and committed (`133a7dc`); no PHP file
was touched, no tick was changed, nothing the card asks for was outstanding. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all six previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`, because `0147` is still open and there is still no
`vendor/bin/pest.bat` (`0150`).

The engine deletion of card `0149` happened a SIXTH time — 288 files, the tree carrying nothing else
— and recovery was the two commands that card records, unchanged, losing nothing, `composer.lock`
untouched.

This run adds no fact the board does not already hold, and the honest thing is to say so rather than
manufacture one. Every condition I met on arrival is carded: the deletion (0149), the runner that
runs out of memory (0147), the Pest binary that does not exist (0150), the handover that exceeds the
budget its own hook prints (0151), and this card sitting finished in `in-progress` and being handed
out again (0152). So I raised no new card and edited no other file — not HANDOVER either: its 0149
bullet already says the deletion recurs on every pick-up, which is exactly what I saw, and 0151 has
that file carded for being twice the size a session can load, so a run with nothing new to report
should not be adding lines to it.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Ninth pick-up, eighth verification. Nothing the card asks for was outstanding: the reformat is on
disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all seven previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`, because `0147` is unresolved and there is still no
`vendor/bin/pest.bat` (`0150`).

The engine deletion of card `0149` happened a SEVENTH time — 288 files, the tree carrying nothing
else — and recovery was the two commands that card records, unchanged, losing nothing,
`composer.lock` untouched.

The one observation this run adds is evidence about `0152` rather than a new fault, so it is prose
here and not a tenth card: the scheduler's lane moves ARE working. `0147` and `0148`, raised by the
second pick-up of this card, have both been moved out of `todo/` into `human-review/` since. So
whatever holds `0083` in `in-progress` with every criterion ticked is specific to this card, not a
dead mechanism — which narrows what `0152` is looking for. I have not edited `0152`.

I raised no new card and edited no other file. Every condition I met on arrival is already carded,
and HANDOVER needs no line: its `0149` bullet already says the deletion recurs on every pick-up,
which is exactly what I saw, and `0151` has that file carded for being twice the size a session can
load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Tenth pick-up, ninth verification. Nothing the card asks for was outstanding: the reformat is on disk
and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are `manual`
and `none`, so there is no failing test to write for either — the checks are the two commands, and
both were re-run here rather than read off the entries above. `vendor/bin/pint --test` over the whole
repository, on a tree `git status` reported clean, gives `{"tool":"pint","result":"passed"}` and exit
0 — criterion #1. The suite is green: 1605 tests, 1604 passed, 1 skipped, 16,785 assertions,
identical to all eight previous runs — criterion #2. Run as `php -d memory_limit=1G
vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent (`0150`) rather than taking it
from the thread.

The engine deletion of card `0149` happened an EIGHTH time — the tree carrying nothing but those
deletions — and recovery was the two commands that card records, unchanged, losing nothing,
`composer.lock` untouched. One detail worth a line for whoever works 0149, because it is cheap and
this run could check it: the count I saw was **288** deleted paths, not the 289 the HANDOVER bullet
and the third pick-up's entry both state. I did not chase which file differs, and it changes neither
the recovery nor the card.

Otherwise this run adds no fact the board does not already hold. I checked the lanes rather than
inferring them: `0149`, `0150`, `0151` and `0152` are all still in `todo/`, and `0147` and `0148` are
still in `human-review/` where the ninth pick-up found them, so that run's observation — lane moves
work for other cards, and whatever holds `0083` is specific to it — still stands and is not revised
by anything I saw. I raised no new card and edited no other file, HANDOVER included: its `0149`
bullet already says the deletion recurs on every pick-up, and `0151` has that file carded for being
twice the size a session can load, so a run with nothing new should not be adding lines to it.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Eleventh pick-up, tenth verification. Nothing the card asks for was outstanding: the reformat is on
disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all nine previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a NINTH time — 288 deleted paths, matching the count the
tenth pick-up recorded rather than the 289 the older entries and the HANDOVER bullet state, and the
tree carrying nothing else. Recovery was the two commands that card records, unchanged, losing
nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are where the
tenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and `0148` in
`human-review/`. So the standing observation holds unrevised — lane moves work for other cards, and
whatever keeps handing out this finished card is specific to it, which is what `0152` is for. I
raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says the
deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a session
can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Twelfth pick-up, eleventh verification. Nothing the card asks for was outstanding: the reformat is on
disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all ten previous runs — criterion #2. Run as `php
-d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent (`0150`)
rather than taking it from the thread.

The engine deletion of card `0149` happened a TENTH time — 288 deleted paths, matching the count the
tenth and eleventh pick-ups recorded rather than the 289 the older entries and the HANDOVER bullet
state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the eleventh pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and `0148`
in `human-review/`. So the standing observation holds unrevised — lane moves work for other cards,
and whatever keeps handing out this finished card is specific to it, which is what `0152` is for. I
raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says the
deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a session
can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Thirteenth pick-up, twelfth verification. Nothing the card asks for was outstanding: the reformat is
on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all eleven previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened an ELEVENTH time — 288 deleted paths, the count the
tenth, eleventh and twelfth pick-ups all recorded rather than the 289 the older entries and the
HANDOVER bullet state, and the tree carrying nothing else. Recovery was the two commands that card
records, unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the twelfth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and `0148`
in `human-review/`. So the standing observation holds unrevised — lane moves work for other cards,
and whatever keeps handing out this finished card is specific to it, which is what `0152` is for. I
raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says the
deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a session
can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Fourteenth pick-up, thirteenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all twelve previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a TWELFTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the thirteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Fifteenth pick-up, fourteenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all thirteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a THIRTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the fourteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Sixteenth pick-up, fifteenth verification. Nothing the card asks for was outstanding: the reformat is
on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all fourteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a FOURTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the fifteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Seventeenth pick-up, sixteenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all fifteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a FIFTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the sixteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Eighteenth pick-up, seventeenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all sixteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a SIXTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the seventeenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Nineteenth pick-up, eighteenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all seventeen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a SEVENTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the eighteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-20**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Twentieth pick-up, nineteenth verification. Nothing the card asks for was outstanding: the reformat
is on disk and committed (`133a7dc`), no PHP file was touched, no tick was changed. Both criteria are
`manual` and `none`, so there is no failing test to write for either — the checks are the two
commands, and both were re-run here rather than read off the entries above. `vendor/bin/pint --test`
over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all eighteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened an EIGHTEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the nineteenth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0151, 0152)

Twenty-first pick-up, twentieth verification, and the first on a new date. Nothing the card asks for
was outstanding: the reformat is on disk and committed (`133a7dc`), no PHP file was touched, no tick
was changed. Both criteria are `manual` and `none`, so there is no failing test to write for either —
the checks are the two commands, and both were re-run here rather than read off the entries above.
`vendor/bin/pint --test` over the whole repository, on a tree `git status` reported clean, gives
`{"tool":"pint","result":"passed"}` and exit 0 — criterion #1. The suite is green: 1605 tests, 1604
passed, 1 skipped, 16,785 assertions, identical to all nineteen previous runs — criterion #2. Run as
`php -d memory_limit=1G vendor/bin/phpunit`; I confirmed `vendor/bin/pest.bat` is still absent
(`0150`) rather than taking it from the thread.

The engine deletion of card `0149` happened a NINETEENTH time — 288 deleted paths, the count every
pick-up since the tenth has recorded rather than the 289 the older entries and the HANDOVER bullet
still state, and the tree carrying nothing else. Recovery was the two commands that card records,
unchanged, losing nothing, `composer.lock` untouched.

Otherwise this run adds no fact the board does not already hold, and the honest thing is to say so
rather than manufacture one. I checked the lanes rather than inferring them and they are exactly
where the twentieth pick-up left them: `0149`, `0150`, `0151` and `0152` in `todo/`, `0147` and
`0148` in `human-review/`. So the standing observation holds unrevised — lane moves work for other
cards, and whatever keeps handing out this finished card is specific to it, which is what `0152` is
for. I raised no new card and edited no other file, HANDOVER included: its `0149` bullet already says
the deletion recurs on every pick-up, and `0151` has that file carded for being twice the size a
session can load.

No screen changed and nothing here needs a browser check.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — every fault I hit is already carded (0149, 0150, 0152)

Twenty-second pick-up. This run was a verification only: no PHP was touched and no tick was
changed. Re-run here, not read off the thread: `pint --test` on a clean tree gives `passed` with
exit 0 (#1), and `php -d memory_limit=1G vendor/bin/phpunit` is green at 1605 tests, 1604 passed,
1 skipped and 16,785 assertions (#2). The 0149 deletion (288 paths) happened again and was recovered
with that card's two commands, losing nothing. `pest.bat` is still missing (0150), and the lanes
have not moved. Nothing here is new, so this entry is kept short: 0152 already records that
repeating the same paragraph here is itself the fault.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — already carded (0149, 0150, 0152)

Twenty-third pick-up, verification only; no PHP touched, no tick changed. Re-run here: `pint --test`
passed, exit 0 (#1); `php -d memory_limit=1G vendor/bin/phpunit` green, 1605 tests, 1604 passed,
1 skipped, 16,785 assertions (#2). The 0149 deletion (288 paths) recurred and was recovered with
that card's two commands; `pest.bat` is still absent (0150). Nothing new, see 0152.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — already carded (0149, 0150, 0152)

Twenty-fourth pick-up, verification only; no PHP touched, no tick changed. Re-run here: `pint --test`
passed, exit 0 (#1); `php -d memory_limit=1G vendor/bin/phpunit` green, 1605 tests, 1604 passed,
1 skipped, 16,785 assertions (#2). The 0149 deletion (288 paths) recurred and was recovered with
that card's two commands; `pest.bat` is still absent (0150). Lanes unchanged. Nothing new, see 0152.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — already carded (0149, 0150, 0152)

Twenty-fifth pick-up, verification only; no PHP touched, no tick changed. Re-run here: `pint --test`
passed, exit 0 (#1); `php -d memory_limit=1G vendor/bin/phpunit` green, 1605 tests, 1604 passed,
1 skipped, 16,785 assertions (#2). The 0149 deletion (288 paths) recurred and was recovered with
that card's two commands, losing nothing; `pest.bat` is still absent (0150). Nothing new, see 0152.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — already carded (0149, 0150, 0152)

Twenty-sixth pick-up, verification only; no PHP touched, no tick changed. Re-run here: `pint --test`
passed, exit 0 (#1); `php -d memory_limit=1G vendor/bin/phpunit` green, 1605 tests, 1604 passed,
1 skipped, 16,785 assertions (#2). The 0149 deletion (288 paths) recurred and was recovered with
that card's two commands, losing nothing; `pest.bat` is still absent (0150). Lanes unchanged —
0149/0150/0151/0152 in `todo/`, 0147/0148 in `human-review/`. Nothing new, see 0152.

**2026-09-21**
RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0083-three-tracked-files-are-not-pint-clean.md
OUT-OF-SCOPE: none — already carded (0149, 0150, 0152)

Twenty-seventh pick-up, verification only; no PHP touched, no tick changed. Re-run here: `pint --test`
passed, exit 0 (#1); `php -d memory_limit=1G vendor/bin/phpunit` green, 1605 tests, 1604 passed,
1 skipped, 16,785 assertions (#2). The 0149 deletion (288 paths) recurred and was recovered with
that card's two commands, losing nothing; `pest.bat` is still absent (0150). Lanes unchanged —
0149/0150/0151/0152 in `todo/`, 0147/0148 in `human-review/`. Nothing new, see 0152.
