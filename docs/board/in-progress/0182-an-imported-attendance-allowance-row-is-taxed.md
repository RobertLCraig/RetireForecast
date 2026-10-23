# An imported Attendance Allowance row is taxed and never reaches the care means test

## Why
`App\Import\Profiles\PayAndExpenditures::incomeBlock()` decides a row is tax-free only when its label
contains "dla" or "disability". A row labelled "Attendance Allowance" matches neither, so it is
imported as a TAXABLE `other` income stream. Attendance Allowance is tax-free, and in a care
placement it is treated like the DLA care component (card 0050): assessable for a self-funder,
stopped 28 days into a funded placement. So an imported award is over-taxed every year and missed
on both sides of the care assessment.

Found while building card 0050's import fix, which kept to the DLA keywords.

## Links

**Relates to**
- `0050` - made a DLA row land on the care or mobility component.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN an imported income row names Attendance Allowance, THE APP SHALL record it as a tax-free disability care component. proves: `test_an_imported_attendance_allowance_row_is_a_tax_free_care_component`
<!-- AC:END -->

## Plan
One line in `incomeBlock()`: treat "attendance" like "dla". Extend
`tests/Unit/Import/PayAndExpendituresTest.php`, whose `workbook()` takes extra income rows.

## Comments
