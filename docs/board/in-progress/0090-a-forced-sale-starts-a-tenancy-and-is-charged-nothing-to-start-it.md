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
- [x] WHEN a plan starts renting part-way through after a sale, THE APP SHALL charge the tenancy deposit in the year the tenancy starts. proves: `test_a_forced_sale_is_charged_the_tenancy_deposit_in_the_sale_year`
- [x] THE APP SHALL state what that tenancy costs up front on the result, as it does for a year-0 rent plan. proves: `test_a_forced_sale_result_states_the_up_front_tenancy_cost`
<!-- AC:END -->

## Tasks
- [x] Decide where the charge belongs. `HousingComparison` cannot see a mid-projection sale, so the
      likely home is `PathProjector`, on the year `$state['homeSold']` first flips true, using
      `Tenancy::deposit()` on the year's nominal rent.
- [x] Keep one definition: the year-0 variant and the forced-sale path must charge the same figure
      off the same constant, not two copies of the arithmetic.
- [x] Bump `ScenarioForecaster::ENGINE_VERSION` and note that stored forced-sale scenarios need
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

**2026-09-28**
RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/src/Housing/Tenancy.php
TOUCHED: packages/finance-engine/tests/Forecast/ForcedSaleTest.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0154-a-care-forced-sale-charges-rent-to-someone-living-in-a-care-home.md
OUT-OF-SCOPE: 0154

Both tests were watched failing first: the sale year cost the same as the next year (no deposit), and
no `TENANCY_UP_FRONT_COST` note was raised. The charge is in `PathProjector::projectYear`, appended to
the year's one-offs in the maturity forced-sale year, sized with `Tenancy::deposit()` on that year's
rent (same `rentFactor` the rent line uses) and filed under `Tenancy::UP_FRONT_LABEL`. So the existing
`tenancyUpFrontWarnings()` states it with no new copy, and the year-0 variant and this path share one
definition. The year-0 rent variant cannot trigger it (it has no home, so `homeSold` never flips).

Assumed: the CARE-forced sale (card 0056, last borrower into permanent care) is NOT charged a deposit.
That person moves into a care home, not a tenancy. While checking this I found the rent line has no
care exclusion either, so that route is charged rent on top of the care fee: raised as 0154.

Re-examined one test: `test_a_forced_sale_frees_the_equity_and_conserves_wealth` reddened by exactly
the deposit (£1,153.85 on £12,000 rent). It now expects the net proceeds less the deposit in the sale
step. That is the change this card asked for, not a loosening.

`ENGINE_VERSION` is now `finance-engine/forced-sale-tenancy-deposit`. Stored plans with a forced sale
and a rent figure need re-running (one deposit too cheap). `GoldenMasterTest` did not redden. The
results note is not new copy, but it now shows on forced-sale plans, and that still needs a browser
check from `C:\Dev\RetireForecast` (not possible from this worktree).
