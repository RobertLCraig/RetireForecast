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
- [x] #1 WHEN an unplanned pension draw includes a tax-free part, THE APP SHALL report that part on the tax-free lump sum line and the rest on the drawdown line. proves: `test_an_ad_hoc_draws_tax_free_part_is_reported_as_a_lump_sum`
- [x] #2 THE APP SHALL keep the two lines summing to the money that left the pots. proves: `test_the_split_draw_lines_reconcile_to_the_total_drawn`
<!-- AC:END -->

## Tasks
- [x] Return the tax-free and taxable parts separately from `PathProjector::fundShortfall`
- [x] Credit them to `pension_lump_sum` and `pension_drawdown` at the caller
- [x] Extend the existing cashflow reconciliation test to cover the split

## Comments

**2026-09-08**
RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/tests/Forecast/PathProjectorTest.php, app/Forecast/ScenarioForecaster.php, docs/DATA-MODEL.md, docs/HANDOVER.md
OUT-OF-SCOPE: none

`fundShortfall` now carries a `fromPensionTaxFree` accumulator alongside `fromPension`, fed only by
`$drawPensionUfpls` from the `$taxFree` leg of `ufplsSplit` that closure already computes, so the
split rule keeps its one home and no arithmetic is restated. `$drawPension` never adds to it: a draw
taken that way is taxable in full, which is why it stays zero under `TaxEfficient` and
`PensionAware`. At the caller the tax-free part goes to `pension_lump_sum` and the gross LESS that
part to `pension_drawdown`, so the two are one gross split in two and cannot double count.

Both criteria were watched failing. #1 failed on the reported reason (`pension_lump_sum` not
positive on a `FillBands` household whose shortfall is met from a pot) before the caller changed.
#2 cannot fail on the ORIGINAL defect, because filing everything on one line still reconciles; it is
a guard against a bad split, so it was watched failing against a deliberately double-counting
caller (`pension_drawdown += fromPension` with the lump sum credited as well): 2013875 against
1611100. The fixture reads what left the pots as the opening pot balance less the closing
`pensionWealth` under flat assumptions, which is exact because that household has no growth and no
contributions.

`ENGINE_VERSION` is bumped to `finance-engine/ad-hoc-draw-reports-its-tax-free-part` and the
**stored-scenario re-run is owed** for any plan that makes an ad-hoc pension draw under
fill-the-bands. No figure moves: tax, wealth, depletion and the odds are unchanged and the whole
suite is green with no re-pin. What moves is the stored LADDER, which is the defect, and without a
bump a stored run keeps showing the old labelling with nothing to say so.

Built in a worktree, so the relabelled cashflow rows **have not been seen in a browser**.
