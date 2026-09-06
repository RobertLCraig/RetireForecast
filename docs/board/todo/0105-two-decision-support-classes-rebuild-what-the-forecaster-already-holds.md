# Two decision-support classes rebuild what the forecaster already holds

## Why
`ScenarioForecaster::variantInputs()` is the one memoised answer to "this plan's household, settings
and assumptions, as the housing choice actually leaves it". Card 0042 put the memo behind it.

Four decision-support classes need that answer. Two ask for it and two build their own:

- `ProtectionGap::forScenario()` and `CapacityForLoss::forScenario()` call
  `$this->forecaster->variantInputs($scenario, $strategy)`.
- `SustainableSpend::forScenario()` (line 69) and `AdviceCostComparison::variantInputs()` (line 164)
  read the variant off `effectiveBuilderState()` themselves, then call
  `housingComparison($scenario)->variantInputs($scenario->toHousehold(), ..., $scenario->toHousingAction())`
  by hand. `toHousehold()` and `toHousingAction()` each decrypt the base form-state, decrypt the
  parent's and deep-merge the overrides — the exact chain the memo exists to run once — and the
  three-variant sale/purchase decomposition is rebuilt beside the memoised copy.

The two hand-rolled copies also **disagree with the shared one on an unknown variant**. The
forecaster ends `?? $all['stay_put']`, deliberately, because reading a sell plan off the wrong path
is a standing trap in this codebase. The hand-rolled `[$variant]` subscript has no fallback: an
unrecognised string yields null and the next line errors.

This is the "one definition, one home" rule in CLAUDE.md, not a speed complaint. Measured on
`ScenarioFixture::rich` (in-memory SQLite, so indicative only), a full affordability row costs about
64 ms and the sustainable-spend bisection is about 51 ms of it, so the duplicated derivation is a
small share of the time. The reason to close it is that a quantity has two homes and they have
already drifted apart on one case.

## Links

**Relates to**
- `0042` - built the request-scoped memo these two classes route around, and named the
  sustainable-spend bisection as part of the affordability screen's cost.

## Not this card
Memoising the bisection itself, any cache that outlives the response (0042's own exclusion), and
`ProtectionGap` / `CapacityForLoss`, which already read the shared answer.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the sustainable-spend search and the advice-cost comparison resolve a plan's inputs, THE APP SHALL read the forecaster's memoised answer rather than deriving the household a second time. proves: `test_the_decision_support_classes_derive_the_household_once`
- [ ] #2 WHEN a scenario names a housing variant the ladder does not know, THE APP SHALL fall back to stay-put rather than erroring. proves: `test_an_unknown_variant_falls_back_to_stay_put`
<!-- AC:END -->

## Tasks
- [ ] Replace the hand-rolled block in `SustainableSpend::forScenario()` with
      `$this->forecaster->variantInputs($scenario)`
- [ ] Replace `AdviceCostComparison::variantInputs()` with the same call, keeping its `$strategy`
      argument
- [ ] Check the assumptions each one then uses still come from `ScenarioForecaster::assumptions()`,
      which is the one place overrides are applied

## Plan
Work in `C:\Dev\RetireForecast`; run `php artisan test` from PowerShell (PHP is Laravel Herd and is
not on the Git Bash PATH).

For #1, counting derivations needs a probe on the model, because `ScenarioForecaster` is `final` and
cannot be subclassed to count. An anonymous subclass of `Scenario` that overrides `toHousehold()` to
increment a counter, raw-attributed from a saved fixture row, sees the second derivation: it counts
two today (once by the class under test, once inside `ScenarioForecaster::settings()`) and one after.
Watch it fail at two before writing the fix.

For #2, write a raw `builder_state` carrying a variant string outside `ScenarioVariant`, which the
builder's own validation will not produce but a hand-edited or migrated row can.

No figure should move: the inputs are the same objects, only resolved once. `GoldenMasterTest` and
the sustainable-spend tests pin that.
