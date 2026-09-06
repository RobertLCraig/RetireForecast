# A carer who is still earning is credited with the carer addition anyway

## Why
Tick "cares for their partner" on somebody who earns £60,000 a year and the forecast awards the
Pension Credit carer addition in every year of the plan, working years included.

It should not. The addition follows UNDERLYING ENTITLEMENT to Carer's Allowance, and Carer's
Allowance has a weekly earnings limit: net earnings above it fail a condition of entitlement, so
there is no underlying entitlement, so no addition. A household where the carer is still working is
credited with money it would not receive, and the years it is credited in are the years the plan is
usually tightest.

What it costs: the award is £48.15 a week at 2026/27 rates, so about £2,500 a year per working year,
and it lands as tax-free income that also lifts the household's assessable income against the
guarantee. It only bites where somebody both cares and earns, which the app could not express until
2026-09-06, so no stored scenario carries it yet.

It came to be this way because `Person::caresForPartner` was wired into
`PathProjector::meansTestedBenefitNominal()` in July 2026 as a straight boolean, when no screen could
set it and the household it was written for had nobody earning. Card 0044 made it a builder input
and flagged the interaction beside the retirement-age lever, but flagging is what that card's
acceptance asked for; suppressing the award is a projection change and is this card.

## Links

**Relates to**
- `0044` - exposed the flag, and added the warning on the retirement-age lever that this card would
  make unnecessary as a caveat and turn into an explanation of a figure that actually moves.
- `0106` - the earnings limit this needs is one of the figures that card pins to a published source.
  Build against the constant either way; the swap is one call site.

## Not this card
Paid Carer's Allowance. The engine models underlying entitlement only, deliberately: paid Carer's
Allowance overlaps with the State Pension and would reduce the cared-for partner's own award, which
is a larger piece of modelling nobody has asked for.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a carer's earnings in a projected year exceed the Carer's Allowance weekly earnings limit, THE APP SHALL award no Pension Credit carer addition for that year. proves: `test_earnings_above_the_carer_limit_block_the_carer_addition`
- [ ] WHEN that carer's earnings stop, THE APP SHALL award the addition from that year on. proves: `test_earnings_above_the_carer_limit_block_the_carer_addition`
<!-- AC:END -->

## Tasks
- [ ] Read the carer's own earnings for the year in `meansTestedBenefitNominal()` and compare them
      against `BenefitsParameters::$carersAllowanceEarningsLimitWeekly` annualised.
- [ ] Decide, and write on this card, whether the comparison uses gross or net earnings. The
      statutory test is net of tax, National Insurance, half of any pension contribution and some
      care costs; the engine has all but the care costs.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario.
- [ ] Revisit the retirement-age lever warning in `ThresholdPresenter::leverCaveat()`: once the
      projector subtracts the addition, the warning stops being a caveat about a missing effect and
      becomes an explanation of one the sweep now shows.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The rule lives in one block of
`packages/finance-engine/src/Forecast/PathProjector.php`, the loop over `$living` that sets `$carer`
in `meansTestedBenefitNominal()`. The earnings the year actually paid are computed higher up in
`projectYear()` and are prorated in a retirement year, so pass them down rather than re-deriving
them from the salary on the DTO. The existing coverage to extend is
`packages/finance-engine/tests/Forecast/PathProjectorTest.php`,
`test_a_partner_who_cares_for_a_disabled_partner_unlocks_the_carer_addition`.

Every stored plan with a working carer is too favourable, so this needs an `ENGINE_VERSION` bump in
`app/Forecast/ScenarioForecaster.php` and a stored-scenario re-run. Run `php artisan test` and
`php artisan scenarios:audit` after. Expect `MonteCarlo\GoldenMasterTest` to stay green: its frozen
household has no carer.

## Comments
