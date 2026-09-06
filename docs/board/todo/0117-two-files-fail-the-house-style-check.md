# Two files fail the house style check, and the `--dirty` convention hides them

## Why
Found while running the style gate for card 0048. `vendor/bin/pint --test` over the whole repo
exits non-zero on two files that card 0048 never touched:

- `app/Forecast/LumpSumTaxShock.php` (`fully_qualified_strict_types`, `unary_operator_spaces`,
  `braces_position`, `not_operator_with_successor_space`, `single_line_empty_body`,
  `ordered_imports`)
- `packages/finance-engine/src/Pension/TaxFreeCashCalculator.php` (`fully_qualified_strict_types`,
  `braces_position`, `single_line_empty_body`, `ordered_imports`)

They are not new: `git log` puts both files' last touch well before this card. They stay unfixed
because the house convention is `vendor/bin/pint --dirty`, which only ever looks at files a session
changed, so a file that drifted once is never looked at again.

The cost is small but it is real: the style gate cannot be run repo-wide, which means it cannot gate
anything, and a session that DOES touch one of those two files gets a diff carrying unrelated style
churn it did not write.

**One caution before running pint on the engine file.** `fully_qualified_strict_types` is the fixer
that hoisted a docblock `{@see \App\...}` into a real `use App\...` on a previous card, which breaks
the framework-free rule `EngineIsolationTest` guards. Read the diff on
`TaxFreeCashCalculator.php` rather than trusting the exit code.

## Not this card
Changing the `--dirty` convention, or adding a repo-wide pint step to any gate. Fix the two files
first; whether the gate should widen is a separate call.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN `vendor/bin/pint --test` is run over the whole repository, THE APP SHALL exit zero. proves: manual
- [ ] THE APP SHALL keep the engine framework-free after the fix. proves: `test_no_engine_source_file_imports_the_app_or_the_framework`
<!-- AC:END -->

## Tasks
- [ ] `vendor/bin/pint app/Forecast/LumpSumTaxShock.php packages/finance-engine/src/Pension/TaxFreeCashCalculator.php`
- [ ] Read the resulting diff, especially the engine file's `use` block.
- [ ] `php artisan test` — no behaviour should move, so a red test means pint changed meaning.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. This is mechanical and should be one commit of its
own, so the churn is not mixed into a card that changed behaviour. The isolation guard is
`packages/finance-engine/tests/Architecture/EngineIsolationTest.php`.

## Comments
