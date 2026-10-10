# Housing Benefit and Council Tax Reduction are awarded for the whole qualifying year

## Why
Found building card 0097. Pension-age Housing Benefit and Council Tax Reduction are gated on the
same test as Pension Credit: every living member at State Pension age. `PathProjector` reads that
gate as "the Pension Credit award is not null", and both supports then annualise a weekly figure
over `weeksPerYear` (`housingBenefitNominal`, `councilTaxNominal`). So in the year the last member
reaches State Pension age they are paid for all 52 weeks, where only the weeks after the date
qualify. Card 0097 fixed this for Pension Credit itself with `pensionCreditAwardPeriod`; these two
still pay the whole year, and the error runs the household's way.

## Links

**Relates to**
- `0097` - made the Pension Credit award run only from the qualifying date. Its period helper is
  the one to reuse here.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a pension-age renter reaches qualifying age part way through a year, THE APP SHALL meet only the rent of the part of the year after that date. proves: `test_housing_benefit_is_awarded_only_from_the_qualifying_age_date`
- [ ] #2 WHEN a household reaches qualifying age part way through a year, THE APP SHALL reduce only the council tax of the part of the year after that date. proves: `test_council_tax_reduction_is_awarded_only_from_the_qualifying_age_date`
<!-- AC:END -->

## Tasks
- [ ] Pass the award period to `housingBenefitNominal` and `councilTaxNominal` and scale the support by it.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`.
