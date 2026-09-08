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
