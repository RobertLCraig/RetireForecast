# A let property may be counted twice in the Pension Credit means test

## Why
A let home is assessed twice over, once as an asset and once as what the asset earns.

`PathProjector::meansTestAssessableCapital` adds a let property's equity to assessable capital, so it
generates tariff income (`Property::$isLet`, added 2026-07-01, and correct on its own: a let home is
not the exempt main residence). Separately, the rent is an ordinary taxable income stream, so it
lands in `$taxablePerPerson` and goes straight into the Pension Credit assessable income.

The means-test rules generally treat income derived from an asset that is already assessed as
capital as capital, not as a second stream of income, precisely to stop this. If that reading holds,
the engine is charging the household twice for one property and **under-awarding** Guarantee Credit
on every plan that lets a home, which is one of the two headline strategies this tool compares.

It is written as "may" because the rule needs a citable source before anything is changed. Getting it
backwards would over-award instead, and the whole point of the means-test work is that these figures
survive scrutiny.

## Links

**Relates to**
- `0051` - found it in the expert-panel review and could not settle it without web access.
- `0048` - property capital is already valued net of the costs of selling; this is the same asset,
  assessed on the income side.

## Not this card
The capital treatment of the equity itself, which is settled.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL assess a let property once: either its equity as capital or its rent as income, not both. proves: `test_a_let_property_is_not_assessed_as_capital_and_income_at_once`
- [ ] THE APP SHALL record which treatment it applies, with its source. proves: none
<!-- AC:END -->

## Tasks
- [ ] Settle the rule from a citable source. **Needs web, so not an unattended card.**
- [ ] Apply one treatment in `pensionCreditAward`, excluding the rent from assessable income the way
      a death-in-service lump sum is already excluded (`$excludedFromAssessable`), if capital wins.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario: any plan with a let home in a Pension
      Credit year moves.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php`: `meansTestAssessableCapital` holds the
capital half and `pensionCreditAward` the income half, and the mechanism for taking a receipt out of
assessable income while leaving it taxable already exists there. The existing guard is
`test_a_let_home_counts_as_assessable_capital_and_erodes_pension_credit` in
`packages/finance-engine/tests/Forecast/PathProjectorTest.php`.

## Comments
