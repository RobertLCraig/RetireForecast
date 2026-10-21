---
needs: 0212, 0213
---
# The builder hides what a layperson does not need, and stops promising a three-way run

## Why
Found by the full review on 2026-10-21. The builder asks about 155 inputs over 5 steps, 45 on step 1.
It shows unexplained terms: "UFPLS" (`scenario-builder.blade.php:780`), "Tax-free lump (PCLS)"
(`:779`), "how much is already in drawdown" (`:767`), "Base tax year" and "Assumption set: Engine
default" (`:225-246`).

Its help text says "Primary option: All three are run and compared" (`:~240`), but the results page
shows one strategy; others must be built as what-ifs. The help text is false.

## Direction
- Move base tax year and assumption set under "Advanced".
- Rename UFPLS to "Take lump sums (a quarter tax-free each time)" with one line of help; PCLS to
  "Take the tax-free quarter in one go".
- Fold the advanced pension and annuity fields into "More options", closed by default.
- Change the primary-option help to "Pick one. Make a what-if to compare another."
- Defaults stay as they are; hiding a field must not change its value (`BuilderStateFixture` round-trip).

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL not show "UFPLS" or "PCLS" as a label without the plain name beside it. proves: `test_the_builder_names_pension_options_in_plain_words`
- [ ] #2 THE APP SHALL not say the builder runs all three options. proves: `test_the_primary_option_help_does_not_promise_three_runs`
- [ ] #3 THE APP SHALL keep every hidden field's value when the scenario is saved. proves: `test_folded_builder_fields_keep_their_values`
<!-- AC:END -->
