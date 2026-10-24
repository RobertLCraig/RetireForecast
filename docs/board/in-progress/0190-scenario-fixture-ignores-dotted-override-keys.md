# `ScenarioFixture::rich` silently ignores a dotted override key

## Why
Found while building card 0061 #3.

`ScenarioFixture::richState()` merges its overrides with `array_replace`, and
`Scenario::effectiveBuilderState()` returns a base's `builder_state` as stored. So a key such as
`'expenseLines.ess1.amount' => '250000'` on a BASE scenario lands as a flat top-level key that no
reader looks at. The forecast runs on the fixture's own figures.

A dotted key does work on a what-if child, because `BuilderStateDelta::merge` reads child
overrides as paths. That is why the same spelling looks right in both places.

Twelve test files pass `'expenseLines.ess1.amount'` alone; other dotted keys may add more, and the
ones that go to `ScenarioFixture::rich` (not to a child's overrides) are the broken ones. Each such test is checking the
fixture's default household, not the case its name and comments describe. Example:
`AffordabilityTest::test_working_plans_are_ordered_strongest_first` means its base to spend
£6,000 a year on essentials; it spends the fixture default.

## Links

**Relates to**
- `0061` - found this while building its #3, whose test relied on a dotted override reaching a base.

## Not this card
- Changing what any test asserts, beyond making its override reach the forecast.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a test passes a dotted override key to `ScenarioFixture::rich`, THE FIXTURE SHALL either apply it as a path into the builder state or fail loudly, never store it as a dead top-level key. proves: `test_a_dotted_override_reaches_the_base_scenarios_builder_state`
- [ ] #2 WHEN the fixture is fixed, THE SUITE SHALL stay green with every existing dotted-key test now running the case it describes, or the drifted test is corrected and named in the comment. proves: none
<!-- AC:END -->

## Comments
