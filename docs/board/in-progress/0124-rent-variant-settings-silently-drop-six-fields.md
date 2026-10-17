# The rent plan is run with six settings the reader never chose

## Why
`HousingComparison::rentSettings()` rebuilds `ForecastSettings` BY HAND, listing eight of its
fourteen fields. The six it does not list fall back to their defaults, silently, for the rent
variant only:

- `modelIht` reverts to **false**, so the sell-and-rent plan models no Inheritance Tax at all, even
  when the reader turned the toggle on. Found while building card 0053, whose end-to-end test could
  not read the residence nil-rate band off the rent leg's own settings and had to supply its own.
- `useIsaAllowance` reverts to true, which happens to match the default but is not the reader's
  choice if they turned it off. The docblock on that field says its absence UNDERSTATES exactly the
  sell-and-invest plans this variant models.
- `sellingCosts`, `homeToDescendants`, `statePensionUprating` and `tripleLockUntilYear` likewise.

So the one leg of the comparison that is most sensitive to policy settings is the one leg that does
not receive them, and nothing reports it. This is the same fault class as card 0107 (`Person`
rebuilt by hand with no reflection guard) and the one `Household::copy()` plus `HouseholdWitherTest`
were built to end.

`ForecastSettings::withModelCareCost()` has the same shape and DOES list all fourteen, so the class
already has one hand-listing that works and one that does not, which is precisely the drift a
single rebuild site prevents.

## Links

**Relates to**
- `0053` - found it there; that card's test documents the workaround it had to take.
- `0107` - the same fault in `Person`, already carded.

## Not this card
Whether the rent leg SHOULD carry a different care or drawdown setting from the other two legs.
This is about fields nobody decided to drop.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry every forecast setting the reader chose into the rent variant, changing only the rent itself. proves: `test_the_rent_variant_keeps_every_setting_it_was_given`
- [ ] THE APP SHALL fail a test when a field is added to `ForecastSettings` and not carried by a rebuild, rather than dropping it from a forecast. proves: `test_every_forecast_settings_field_survives_a_rebuild`
<!-- AC:END -->

## Tasks
- [ ] Give `ForecastSettings` ONE private `copy()` and express `withModelCareCost()` and the rent
      transform through it, the way `Household::copy()` is the one rebuild site.
- [ ] Add the reflection guard, modelled on `HouseholdWitherTest`, so a new field is covered the
      moment it is declared.
- [ ] Decide whether the fix moves any stored figure: a stored rent plan whose scenario has the IHT
      toggle ON has been run without it, so it needs `ENGINE_VERSION` and a re-run.
