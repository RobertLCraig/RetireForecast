# A forced sale starts a tenancy and is charged nothing to start it

## Why
Found while building card 0031.

Card 0031 charges the tenancy deposit as a year-0 one-off on the sell-and-rent VARIANT, in
`HousingComparison::rentVariant()`. That is the only place a tenancy is known to begin.

A plan can also start renting part-way through: the forced-sale leg (the mortgage redemption year
with `MortgageMaturityAction::Sell`, and the in-place forced sale) clears the home mid-projection
and pays `ForecastSettings::annualRent` from that year on. That household signs a tenancy too, and
it pays the same deposit and the same first month up front, in the year of the move rather than in
year 0. Nothing charges it.

The referencing flag card 0031 added does NOT have this gap: it is raised in `PathProjector`
wherever rent is charged, so a forced-sale year that fails a reference is already flagged. It is
only the up-front cost that is variant-only, so a forced-sale plan is one deposit cheaper than it
should be, and its reader is not told what moving in costs.

The amount is small against a whole projection. It is worth a card rather than a shrug because it
is a *silent* asymmetry between two paths that are meant to model the same thing, which is the
class of defect this board keeps finding.

## Links

**Relates to**
- `0031` - built the deposit charge and the disclosure for the year-0 rent variant.

## Not this card
The referencing flag, which already covers every rented year including a forced-sale one.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a plan starts renting part-way through after a sale, THE APP SHALL charge the tenancy deposit in the year the tenancy starts. proves: `test_a_forced_sale_is_charged_the_tenancy_deposit_in_the_sale_year`
- [ ] THE APP SHALL state what that tenancy costs up front on the result, as it does for a year-0 rent plan. proves: `test_a_forced_sale_result_states_the_up_front_tenancy_cost`
<!-- AC:END -->

## Tasks
- [ ] Decide where the charge belongs. `HousingComparison` cannot see a mid-projection sale, so the
      likely home is `PathProjector`, on the year `$state['homeSold']` first flips true, using
      `Tenancy::deposit()` on the year's nominal rent.
- [ ] Keep one definition: the year-0 variant and the forced-sale path must charge the same figure
      off the same constant, not two copies of the arithmetic.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and note that stored forced-sale scenarios need
      re-running.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The rent charge and the two card-0031 warning builders
are in `packages/finance-engine/src/Forecast/PathProjector.php` (`rentReferencingWarnings`,
`tenancyUpFrontWarnings`); the year-0 charge is in
`packages/finance-engine/src/Housing/HousingComparison.php`. The figures live in
`packages/finance-engine/src/Housing/Tenancy.php`.
`packages/finance-engine/tests/Forecast/ForcedSaleTest.php` is the fixture to extend, beside
`packages/finance-engine/tests/Housing/TenancyReferencingTest.php`. Run
`php artisan test --testsuite=Engine`, then the full suite.

## Comments
