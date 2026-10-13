# A Person is rebuilt by hand in three places, and no test notices a dropped field

## Why
`Person` is copied in three places and every one of them lists its fields by hand:
`Person::withPlannedRetirementAge()`, `Person::withLongevity()` and
`ProtectionGap::withoutEmployerCover()`. Add a field to the DTO, forget one of the three, and that
copy silently reverts the field to its default. Nothing goes red.

That is not hypothetical. Card 0044 added `disabilityBenefitFromAge` and all three had to be edited
by hand; the `ProtectionGap` one was found by reading, not by a failing test. Had it been missed,
the protection-gap comparator would have started the disability benefit at age 0 instead of the age
the reader entered, inflating the survivor's income in exactly the panel that tells them how big
their protection shortfall is.

Every other DTO of this shape is already guarded. `Household` has a private `copy()` behind named
withers and `HouseholdWitherTest` enumerates its properties by reflection; `ExpenseProfile` has the
same pair; `Property`, `Account` and `DcPension` are covered by `AssetWitherTest`. `Person` was
simply never brought into that pattern, and it is the DTO with the most fields of the lot.

## Links

**Relates to**
- `0044` - added the field that exposed the gap, and fixed the one hand rebuild it broke.
- `0041` - gave `ExpenseProfile` the private `copy()` and its reflection guard, after
  `withoutPropertyCosts()` had already lost a field the same way. The same fix applies here.

## Not this card
Any change to what the copies MEAN. `ProtectionGap::withoutEmployerCover()` deliberately nulls the
death-in-service cover; that is its whole job and stays.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL copy a Person through one private method, so a new field is carried by every copy without editing each one. proves: `test_every_person_wither_carries_every_field`
- [ ] WHEN a Person copy drops a field, THE APP SHALL fail a test that enumerates the DTO's own properties by reflection. proves: `test_every_person_wither_carries_every_field`
<!-- AC:END -->

## Tasks
- [ ] Give `Person` a private `copy()` and route both withers through it, as `Household` and
      `ExpenseProfile` do.
- [ ] Replace the hand rebuild in `ProtectionGap::withoutEmployerCover()` with a
      `withoutDeathInServiceCover()` wither on the DTO.
- [ ] Add `PersonWitherTest`, modelled on `ExpenseProfileWitherTest`: enumerate the constructor's
      promoted properties by reflection, set each to a non-default value, copy, and assert every
      one survives.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The DTO is
`packages/finance-engine/src/Dto/Person.php`; the pattern to copy is
`packages/finance-engine/src/Dto/ExpenseProfile.php` plus
`packages/finance-engine/tests/Dto/ExpenseProfileWitherTest.php`. The one app-layer caller is
`app/DecisionSupport/ProtectionGap.php`. Nothing about a projection changes, so **no
`ENGINE_VERSION` bump and no stored re-run are owed**. Run `php artisan test`; green with no figure
moving is the pass.

## Comments
