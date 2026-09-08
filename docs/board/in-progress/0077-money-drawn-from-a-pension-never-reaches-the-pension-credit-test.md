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
- [x] #1 WHEN a household on Pension Credit draws taxable pension money to cover a shortfall, THE APP SHALL reduce that year's award by what the means test would take. proves: `test_an_ad_hoc_pension_draw_reduces_the_pension_credit_award`
- [x] #2 THE APP SHALL leave the tax-free part of a draw out of the assessment, because it is capital and not income. proves: `test_the_tax_free_part_of_a_draw_is_not_assessed_as_income`
- [x] #3 THE APP SHALL settle to a single consistent figure for the year, with the award, the shortfall and the draw agreeing. proves: `test_the_award_and_the_draw_reconcile_in_the_same_year`
<!-- AC:END -->

## Tasks
- [x] Iterate the award and the shortfall to a fixed point in `PathProjector::projectYear`, with a bounded loop
- [x] Keep the capital tariff separate from the income assessment; only the taxable part is income
- [x] Re-run `php artisan scenarios:audit`; any low-income scenario's figures will move

## Comments

**2026-09-08**
RESULT: done
TESTS: +3 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/PensionCreditDrawAssessedTest.php
TOUCHED: packages/finance-engine/tests/Forecast/PathProjectorTest.php
TOUCHED: packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/HANDOVER-ARCHIVE.md
TOUCHED: docs/board/todo/0143-the-pension-credit-aware-draw-order-spreads-the-claw-back-instead-of-concentrating-it.md
OUT-OF-SCOPE: 0143

Everything from the Support for Mortgage Interest block to the end of the drawdown in
`PathProjector::projectYear` is now one pass at a fixed point, re-run against a restored state. The
state and the six running totals are snapshotted before the loop and put back on every retry, so a
pass is never charged twice for the support it met, the care it was assessed for or the assets it
drew. The award itself is asked for through a closure over the state as it stood BEFORE the loop, so
every re-ask sees the same capital: the forced sale, the drawdown and the banked surplus all move
assets, and an award assessed on a later state would be assessed on a household this one is not.

It settles when re-assessing the means test on what the pass drew leaves the award where the pass
had it. That equality is the test rather than a tolerance in pence, because the award is quantised
to whole weekly pence and a penny of wobble in the draw cannot move it. A household with no award,
and one that draws nothing taxable out of a pension, settle on the first pass and are byte-identical
to the pre-card engine.

The step between passes is a secant, not a plain substitution. Three quarters of a draw is taxable,
so substituting closes only a quarter of the gap each pass and the worked household would have taken
about forty passes to land on the penny. The gap between what a pass assessed and what it drew is a
straight line in the assessed figure, so the secant through the last two passes lands on its root at
once; `MAX_PENSION_CREDIT_PASSES` bounds it at eight and the last pass stands if that runs out,
which is the pre-card answer and is documented at the break.

One thing the card did not name and the build had to decide: the DRAW ORDER is settled once, before
the year draws anything, and does not move with the claw-back. Letting each pass read its own
clawed-back award put the iteration on the wrong one of two self-consistent answers, and
`PathProjectorTest::test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact` caught it.

Two tests moved and neither was moved to make it pass.
`GoldenMasterTest` was re-pinned: the frozen household reaches Guarantee Credit late in life and
draws a pension there, so essentials success falls from 0.5150 to 0.4850 and terminal wealth falls
at every percentile above p10. `PIN_REVISION` was already today's date, so its companion test could
not demand the DECISIONS entry; it is written anyway.
`test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact` lost its second assertion,
which claimed the Pension-Credit-aware order keeps more credit across the whole plan. It does not,
any more, and the reason is this card: the claw-back is capped at the year it happens in, so a few
large draws cost less credit than the same money in small amounts over many years, and deferring the
pension until the capital is gone is what spreads it. Measured on that household, the aware order
keeps £201,131 of credit against £213,940 for the order it is meant to beat, and ends with more
unmet spend. The assertion is replaced by the rule the order actually states, tested in the years it
holds, and the finding is card 0143 rather than a fix made in passing.

`ENGINE_VERSION` is `finance-engine/pension-draw-assessed-for-pension-credit` and the
stored-scenario re-run is owed for any plan holding an award and drawing taxable pension money.
`scenarios:audit` was re-run: it still exits non-zero, on the 120 missing-integrity-stamp lines and
the three unseeded assumption-set figures the handover already records, and on nothing this card
introduced. No screen changed, so there is nothing here that needs a browser.

What could not be settled from the repository: the assessment adds the draw as GROSS taxable income,
which is the convention `$taxablePerPerson` already carries into this means test for every other
income. Pension Credit is assessed on income net of tax. The two agree for the households this
matters most to, whose income sits inside the personal allowance, and where they differ the gross
reading takes MORE credit away, which is the cautious side. Correcting it is a change to how every
income reaches the test, not to this card's draw.
