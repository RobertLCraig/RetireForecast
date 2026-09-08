# Money drawn from a pension never reaches the Pension Credit test

## Why
Pension Credit tops a low-income pensioner household up to a minimum weekly figure, and every extra
pound of income takes a pound of it away. Money drawn out of a pension counts as income for that
test.

In the forecast it does not. The award for each year is worked out from the income the household is
already receiving, and only then does the projector go and take money out of a pot to cover whatever
is still unpaid. Nothing goes back to recalculate the award, in that year or any later one. So a
household can draw £15,000 out of a pension and keep every penny of a credit that in life would have
been reduced to nothing.

The result is a forecast that overstates income for exactly the households least able to absorb the
error, and a comparison that makes drawing a pension look free for them when it is the most expensive
money they can spend.

It came from the order of the calculation. The award has to be known before the shortfall can be
worked out, because the award is part of the income that covers the spending, and the draw has to be
known before the award can be right. Nobody chose the current order; it is just the one that does not
need solving twice.

## Links

**Relates to**
- `0046` - how secure Pension Credit is treated as being, and the claim prompt, are that card; this
  one only changes what income the test sees.

## Not this card
How secure Pension Credit is treated as being, and the missing claim prompt, which are card 0046.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a household on Pension Credit draws taxable pension money to cover a shortfall, THE APP SHALL reduce that year's award by what the means test would take. proves: `test_an_ad_hoc_pension_draw_reduces_the_pension_credit_award`
- [ ] #2 THE APP SHALL leave the tax-free part of a draw out of the assessment, because it is capital and not income. proves: `test_the_tax_free_part_of_a_draw_is_not_assessed_as_income`
- [ ] #3 THE APP SHALL settle to a single consistent figure for the year, with the award, the shortfall and the draw agreeing. proves: `test_the_award_and_the_draw_reconcile_in_the_same_year`
<!-- AC:END -->

## Tasks
- [ ] Iterate the award and the shortfall to a fixed point in `PathProjector::projectYear`, with a bounded loop
- [ ] Keep the capital tariff separate from the income assessment; only the taxable part is income
- [ ] Re-run `php artisan scenarios:audit`; any low-income scenario's figures will move
