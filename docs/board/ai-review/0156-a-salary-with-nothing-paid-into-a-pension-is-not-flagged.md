# A salary with nothing paid into a pension is not flagged

## Why
A person can be entered as employed, earning a salary for years to come, with no money going into
any pension: no member payment and no employer payment. The result page says nothing about it. A
reader cannot tell a real nil from a field nobody filled in.

It matters because most employees are auto-enrolled. If the payment exists and is not entered, the
forecast understates that person's pension and its tax relief, and every figure built on it.

Card 0002 found no stored scenario recording any contribution. The answer on 0002 explains part of
it: several scenarios model the working partner as already past retirement, so they earn nothing
and pay nothing in. Those are already told so by the `no_salary` note. The scenarios where the
partner still earns have no note at all, so their zero is a figure the reader cannot see, which the
"no invisible figures" rule in `CLAUDE.md` forbids.

## Links

**Relates to**
- `0002` - its answer showed the zero is only explained where the worker is modelled as retired,
  leaving the still-earning scenarios silent.
- `0157` - asks for the real payslip rates; this card makes the gap visible until they are entered.

## Not this card
- Assuming or defaulting any contribution rate. Nothing is invented; the note only names the gap.
- Entering the real couple's rates into a stored scenario. That waits on card 0157.
- A new `scenarios:audit` check. The note is enough; an audit check is a separate card if wanted.
- The relief-method note (`no_relief_method`), which already exists and is unchanged.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a living person has a salary in at least one projected year AND every DC pension they own has a zero member and a zero employer contribution (or they own no DC pension), THE APP SHALL add an input note of kind `no_pension_contribution` naming that person and saying no pension contribution is modelled while they earn. proves: `test_a_salary_with_no_pension_contribution_is_flagged`
- [x] WHEN that person's DC pension has a positive member OR employer contribution, THE APP SHALL NOT add the `no_pension_contribution` note. proves: `test_a_salary_with_a_pension_contribution_raises_no_contribution_note`
- [x] WHEN the person's planned retirement age is at or below their current age (the `no_salary` case), THE APP SHALL NOT add the `no_pension_contribution` note. proves: `test_a_person_past_retirement_raises_no_contribution_note`
- [x] THE APP SHALL show the note on both the results page and the PDF, because both read `ResultPresenter::inputNotes()`. proves: `test_a_salary_with_no_pension_contribution_is_flagged`
<!-- AC:END -->

## Tasks
- [ ] Add the `no_pension_contribution` branch to `ResultPresenter::inputNotes()`.
- [ ] Add the three tests to `tests/Unit/Forecast/InputNotesTest.php`.
- [ ] Run `php artisan test` from the project root; green is required.

## Plan
Work in this repository, on the card's branch, running PHP through PowerShell (see `CLAUDE.md`).

1. Open `app/Forecast/ResultPresenter.php` and find `inputNotes()`. Read two existing branches
   first: the `no_salary` note (raised when the retirement age is at or below current age) and the
   `no_relief_method` note (block "(b2)", which loops `$household->pensions` and skips anything
   that is not a `DcPension`). The new branch sits beside (b2) and copies its shape.
2. "Has a salary in a projected year" means: `employmentStatus` is `Employed` or `SelfEmployed`,
   `grossSalary` is positive, and the person is not in the `no_salary` case. Reuse whatever test the
   `no_salary` branch already uses for that last part rather than writing a second age check.
   Read the branch to find it.
3. A contribution is `DcPension::$ongoingContribution` (member) plus `$employerContribution`, both
   `Money`, matched to the person by `ownerId`.
4. Suggested wording, one idea per sentence: "No pension contribution is modelled for {name} while
   they earn. If they or their employer pay into a workplace pension, enter it on the pension, or
   the forecast understates it." Use `self::personLabel()` for the name, as (b2) does.
5. Copy the test fixtures from `test_an_employed_person_with_no_retirement_age_is_flagged` in
   `InputNotesTest.php`. For the "has a contribution" case, add a DC pension row; read
   `tests/Support/BuilderStateFixture.php` for the DC row's field names.
6. It worked when the three new tests pass and the whole suite is green. No figure moves, so no
   `ENGINE_VERSION` bump and no stored re-run is owed.

## Comments

**2026-10-21** RESULT: done
TESTS: +3 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
tests/Unit/Forecast/InputNotesTest.php
docs/board/todo/0210-the-mpaa-warning-fires-after-workplace-contributions-stop.md
OUT-OF-SCOPE: 0210

Built: a new branch (b1) in the person loop of `ResultPresenter::inputNotes()`, beside (a) and (b) so it reuses their `$working`, `$currentAge` and `$deathYear`. It raises `no_pension_contribution` when the person is employed or self-employed with a positive salary, their retirement age is blank or above their current age (the inverse of the `no_salary` test), they are not modelled to die in the base year (the 'living' in the criterion), and no DcPension they own has a positive member or employer contribution. Wording is the card's suggested text. Nothing is assumed or defaulted, and no figure moves, so no ENGINE_VERSION bump.

Watched fail: `test_a_salary_with_no_pension_contribution_is_flagged` went red on 'zero DC pot: Failed asserting that an array contains no_pension_contribution' before the code. The two negative tests were green before the code by their nature (they assert a note's absence), so they guard against over-firing only.

Criterion 4 (results page and PDF) is met through the shared `inputNotes()`; the test proves the note, not a rendered page. Built in a worktree, so the note is NOT seen in a browser or a printed PDF.

One existing test legitimately drifted: `test_a_sensible_household_raises_no_notes` has an earner with no pension, which this card now flags. I re-examined it rather than loosened it: it now asserts the exact note list is ['no_pension_contribution']. Giving the earner a DC pot instead was tried; a pot brings its own pension-tax and MPAA disclosures, so that route cannot reach an empty list. The test name is kept because card 0179 cites it.

Raised 0210: while trying that pot, the MPAA warning fired in 2033 for a member who retired in 2032 and whose only contribution was employer/net-pay, which stops at retirement. `mpaaWarnings()` reads the set contribution, not what is paid that year.
