# The Attendance Allowance what-if can pay a disability benefit twice

## Why
Raised by card 0044 (its review and the 2026-09-28 manager pass). `QuickWhatIf::claimAttendanceAllowance()`
picks its claimants on the `receivesDisabilityBenefit` flag alone. A person who already has a tax-free
`disability_benefit` income stream but left the flag unticked is treated as a new claimant, so the
what-if adds a second benefit stream on top of the first and the child plan counts the money twice.

## Links
**Relates to**
- `0044` - built the preset.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL NOT add an Attendance Allowance stream for a person who already has a `disability_benefit` income stream. proves: `test_the_attendance_allowance_preset_does_not_stack_a_second_disability_stream`
<!-- AC:END -->

## Tasks
- [ ] Exclude a person with an existing `disability_benefit` stream from the claimants in `QuickWhatIf::claimAttendanceAllowance()`

## Comments

**2026-10-23** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/QuickWhatIf.php
TOUCHED: tests/Feature/Forecast/QuickWhatIfTest.php
OUT-OF-SCOPE: none
Claimants in QuickWhatIf::claimAttendanceAllowance() now exclude a member who owns a disability_benefit income row, as well as a member with the flag ticked. The test was watched red first (streams added for p1 and p2) and is green after the fix.
Assumption: an excluded member is left fully alone. Their receivesDisabilityBenefit flag is NOT ticked by the preset, per the card's task line. Ticking it would open the Pension Credit addition for a stream they already have; that is a separate question if wanted.
No engine change, so no ENGINE_VERSION bump and no re-run owed. Not seen in a browser (worktree).
