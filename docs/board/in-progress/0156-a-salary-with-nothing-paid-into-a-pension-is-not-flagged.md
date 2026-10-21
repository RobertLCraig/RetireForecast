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
- [ ] WHEN a living person has a salary in at least one projected year AND every DC pension they own has a zero member and a zero employer contribution (or they own no DC pension), THE APP SHALL add an input note of kind `no_pension_contribution` naming that person and saying no pension contribution is modelled while they earn. proves: `test_a_salary_with_no_pension_contribution_is_flagged`
- [ ] WHEN that person's DC pension has a positive member OR employer contribution, THE APP SHALL NOT add the `no_pension_contribution` note. proves: `test_a_salary_with_a_pension_contribution_raises_no_contribution_note`
- [ ] WHEN the person's planned retirement age is at or below their current age (the `no_salary` case), THE APP SHALL NOT add the `no_pension_contribution` note. proves: `test_a_person_past_retirement_raises_no_contribution_note`
- [ ] THE APP SHALL show the note on both the results page and the PDF, because both read `ResultPresenter::inputNotes()`. proves: `test_a_salary_with_no_pension_contribution_is_flagged`
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
