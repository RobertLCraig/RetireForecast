# Tax-free cash is listed as taxable drawdown on the cashflow ladder

## Why
When the forecast takes money out of a pension to cover a shortfall, a quarter of it usually arrives
free of tax, while the lump sum allowance lasts. The tax is computed on that basis and the totals are
right.

The year-by-year cashflow table does not say so. Every pound of an unplanned pension draw is printed
on the `pension_drawdown` line, which the page labels as taxable pension income. A reader adding up
their taxable income from the table gets a figure a third too big, and cannot reconcile it against
the tax the same row shows. Somebody checking the tool's arithmetic - which is the whole purpose of
publishing the ladder - is checking it against the wrong number.

Nothing decided this. `PathProjector::fundShortfall` returns one `fromPension` total, so the split
the engine already computes internally has nowhere to go on the way out.

## Not this card
Planned withdrawal instructions, which already report their tax-free part separately.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN an unplanned pension draw includes a tax-free part, THE APP SHALL report that part on the tax-free lump sum line and the rest on the drawdown line. proves: `test_an_ad_hoc_draws_tax_free_part_is_reported_as_a_lump_sum`
- [ ] #2 THE APP SHALL keep the two lines summing to the money that left the pots. proves: `test_the_split_draw_lines_reconcile_to_the_total_drawn`
<!-- AC:END -->

## Tasks
- [ ] Return the tax-free and taxable parts separately from `PathProjector::fundShortfall`
- [ ] Credit them to `pension_lump_sum` and `pension_drawdown` at the caller
- [ ] Extend the existing cashflow reconciliation test to cover the split
