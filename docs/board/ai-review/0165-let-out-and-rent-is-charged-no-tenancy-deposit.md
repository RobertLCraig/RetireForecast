# "Let out & rent elsewhere" is charged no tenancy deposit

## Why
Found while building card 0031 #1, on 2026-10-05. A sell-and-rent plan is charged the tenancy
deposit as a year-0 one-off (`HousingComparison::rentVariant`), and a forced sale is charged it in
the sale year (card 0090). The "Let out & rent elsewhere" what-if (`QuickWhatIf::letOutAndRent`)
also starts a tenancy, but it pays its rent as a spend line on a stay-put plan, so it is charged no
deposit and shown no `TENANCY_UP_FRONT_COST` notice. Card 0031 gave it the referencing flag only,
through `ExpenseProfile::tenantRent()`.

## Links

**Relates to**
- `0031` - charged the deposit on the other two rent paths, and flagged this one's referencing.
- `0090` - the forced-sale deposit.

## Not this card
- The referencing flag on this plan, which card 0031 built.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a "Let out & rent elsewhere" plan is modelled, THE APP SHALL charge the tenancy deposit at the start of the tenancy and state it, as it does for a sell-and-rent plan. proves: `test_the_let_out_and_rent_plan_is_charged_the_tenancy_deposit`
<!-- AC:END -->

## Plan
Size it with `Tenancy::deposit()` on `ExpenseProfile::tenantRent()` and charge it as the
`Tenancy::UP_FRONT_LABEL` one-off. `PathProjector::tenancyUpFrontWarnings` is gated on the
sell-and-rent rent, so it needs the same fallback the referencing call got. A stored let-out result
moves, so `ENGINE_VERSION` needs a bump.

## Comments

**2026-10-21** RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: packages/finance-engine/tests/Housing/TenancyReferencingTest.php
TOUCHED: tests/Feature/Forecast/RentReferencingNoticeTest.php
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

PathProjector now charges Tenancy::deposit() on ExpenseProfile::tenantRent() (nominal at year 0) as a Tenancy::UP_FRONT_LABEL one-off in year 0, unless a deposit is already in that year's one-offs (so a sell-and-rent variant of a let-out child is not charged twice). tenancyUpFrontWarnings gets the same tenant-rent fallback the referencing call has. ENGINE_VERSION bumped to finance-engine/let-out-and-rent-pays-a-tenancy-deposit; the stored re-run is owed for let-out-and-rent plans. Golden master did not move.

Both tests watched red first for the right reason: the engine test saw year-0 spend 32,000.00 with no deposit; the feature test saw tenancyUpFront null on the let-out child's ladder. test_the_let_out_and_rent_plan_is_charged_the_tenancy_deposit (engine) proves the exact year-0 charge and the single notice; test_the_let_out_and_rent_ladder_states_the_tenancy_deposit proves it reaches the ladder through the real QuickWhatIf path.

Assumed: the let-out tenancy starts in year 0, because the what-if adds its rent line from the start. Built in a worktree, so the notice still needs a browser check.
