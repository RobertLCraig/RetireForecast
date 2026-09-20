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
