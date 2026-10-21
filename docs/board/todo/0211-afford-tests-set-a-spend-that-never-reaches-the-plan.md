# The /afford tests set a spend that never reaches the plan

## Why
`ScenarioFixture::rich()` merges its overrides with `array_replace`, so a dotted key such as
`'expenseLines.ess1.amount' => '8000'` is stored as a new top-level key and changes nothing. Seven
base scenarios in `tests/Feature/Livewire/AffordabilityTest.php` pass one (the base in
`test_it_separates_plans_that_work_from_plans_that_do_not`, and lines 81, 123, 176, 198, 218, 241).
Each runs on the fixture's own £28,000 essentials, not the figure its comment states. The tests still
pass because the rich household lasts either way, but `test_working_plans_are_ordered_strongest_first`
then says "roomy at £6,000" while the base spends £28,000. Found while building card 0158, which
had to set the line on the state array directly.

Dotted keys DO work on a what-if child (`childOf` stores them as a `BuilderStateDelta` override), so
this is only the base.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a test passes a dotted override key to `ScenarioFixture::rich()`, THE FIXTURE SHALL either apply it to the nested field or fail loudly, so no base scenario silently ignores a stated figure. proves: `test_a_dotted_override_reaches_the_nested_builder_field`
<!-- AC:END -->

## Plan
Test support only. Either apply dotted keys with `data_set` in `richState()` (the list is keyed by
position, so map `ess1` to its index) or throw on a key containing a dot, then fix the seven call
sites. No app or engine change.

## Comments
