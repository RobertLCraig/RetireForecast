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

## Not this card
Changing the Pint ruleset, or making Pint a CI gate. This is only bringing three files up to the
ruleset already in force. Found while working card 0027, which changed no PHP.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN `vendor/bin/pint --test` runs over the whole repository on a clean tree, THE APP SHALL exit zero. proves: manual — Pint is the formatter, not something the suite runs; the check is the command itself.
- [ ] #2 THE APP SHALL keep the test suite green across the reformat, since the change is cosmetic only. proves: none — no single test names this; it is the whole suite, run before and after.
<!-- AC:END -->

## Tasks
- [ ] Run `vendor/bin/pint` (no `--dirty`) from the project root
- [ ] Read the diff and confirm every hunk is formatting, not behaviour
- [ ] Run `php artisan test` and confirm it is still green

## Comments
