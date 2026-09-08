# Spendable wealth counts a pension pot as if no tax were due

## Why
"How much you would have left" on the results page adds the pension pot to the cash and investments
at face value. A pension pot is not spendable at face value. Three quarters of it is taxable on the
way out, so a £200,000 pot is worth about £170,000 to a basic-rate household and less to one paying
higher rate.

That figure is not decoration. It is what the safety-buffer warning is measured against, and it is
the terminal-wealth number the buy, rent and stay-put plans are ranked on. Overstating it means the
warning that the money is getting thin fires later than it should, and it favours whichever plan ends
with more of its wealth inside a pension, which since the tool started modelling large pots is a real
difference between the plans rather than a rounding one.

It came from the definition rather than from a decision: `terminalUsableWealth` was written as
"liquid plus pension, excluding the home", to separate spendable money from the house, and the tax on
the pension part was never in scope of that separation.

## Not this card
Total wealth on the wealth chart, which is a stock of assets and is correct gross.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL report spendable wealth net of the tax that would be due on the pension part. proves: `test_spendable_wealth_is_net_of_tax_on_the_pension_part`
- [x] #2 THE APP SHALL state the rate it netted at and where it came from. proves: `test_the_netting_rate_is_disclosed`
- [x] #3 THE APP SHALL keep the tax-free part of a pension pot unnetted, up to what is left of the lump sum allowance. proves: `test_the_tax_free_quarter_is_not_netted`
<!-- AC:END -->

## Tasks
- [x] Net the pension component in `PathProjector`'s `terminalUsableWealth`, at the projected marginal rate
- [x] Check every consumer: the safety buffer, the plan ranking, the Monte Carlo percentiles, the PDF
- [x] Disclose the rate as an assumed figure; re-run `php artisan scenarios:audit`

## Comments

**2026-09-08**
RESULT: done
TESTS: +5 new, all green
TOUCHED:
packages/finance-engine/src/Forecast/YearResult.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Forecast/ForecastResult.php
packages/finance-engine/src/MonteCarlo/Simulator.php
packages/finance-engine/src/MonteCarlo/SimulationResult.php
packages/finance-engine/src/Dto/SpendingGuardrail.php
packages/finance-engine/tests/Forecast/SpendableWealthNetOfPensionTaxTest.php
packages/finance-engine/tests/Forecast/WealthReconciliationTest.php
packages/finance-engine/tests/Forecast/PathProjectorTest.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
tests/Unit/Forecast/SpendableWealthNettingDisclosureTest.php
tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
tests/Feature/Livewire/ScenarioCompareTest.php
docs/DECISIONS.md
docs/DATA-MODEL.md
docs/HANDOVER.md
docs/HANDOVER-ARCHIVE.md
docs/board/todo/0142-the-usable-wealth-labels-do-not-say-the-pension-tax-has-been-taken-off.md
OUT-OF-SCOPE: 0142

`YearResult::usableWealth()` is the one home of the figure: liquid + pension less
`pensionTaxIfDrawn()`. `PathProjector::pensionTaxIfDrawn()` fills it, per person, per pot, reusing
`ufplsSplit` and `lsaHeadroom` so the tax-free split has one home and an inherited or already
crystallised pot gets no second quarter here either; the allowance ledger it starts from is the
member's own `state['lsaUsed']`, so cash they have already taken has already spent it. Every
consumer now reads that one method: the ladder column and the safety-buffer check, the burndown
line, the Monte Carlo per-year usable series, `terminalUsableWealth` (so the percentiles, the plan
ranking in `Interpretation`, Compare, the assistant and the PDF follow without touching them).

Watched fail first, and for the right reason. #1 failed at £338,078 against £308,078, exactly the
£30,000 of tax on a £200,000 pot. #3 failed at £0 against £30,000 once the accessor existed. #2
failed with no disclosure at all where one was required. Four existing tests then reddened, all
legitimate drift from the deliberate change, and each was updated to assert the new invariant rather
than relaxed: two engine reconciliations now carry the tax as an explicit leg (usable + tax + home
equity == total), the burndown reconciliation reads `usableWealth()`, and one disclosure test that
asserted "no notes at all" now asserts the exact one note the household should carry.

**Assumed, and recorded in DECISIONS 2026-09-08:** the taxable balance is charged at the member's
projected MARGINAL rate applied flat, not at what encashing the whole pot in one year would cost.
That is what the card's own worked example describes (a £200,000 pot worth about £170,000 to a
basic-rate household) and pricing it the other way would understate the pot by tens of thousands.
Its ceiling is the same simplification read backwards: a member whose projected income sits inside
the personal allowance nets nothing. That is disclosed in the note rather than hidden. The rate is
measured over a £1,000 probe of extra pension income, because the marginal charge is the difference
of two rounded whole-income computations and a smaller probe is swamped by that rounding once
thresholds index.

**Deliberately NOT changed:** total wealth (the card excludes it, and a pot is worth face value
until drawn); `availableCapital` / `pensionCapital`, the spendable-view pair, which mean something
else; and the spending guardrail's funded ratio, which is gross by design and whose netting would
move a projection rather than a report. All three are stated in the code where a reader meets them.

`ENGINE_VERSION` is `finance-engine/spendable-wealth-net-of-pension-tax` and the **stored-scenario
re-run is owed**: the stored spendable figure is what is wrong. `GoldenMasterTest` did not redden
and needs no re-pin. `php artisan scenarios:audit` still exits 1 on 123 problems, all of them
pre-existing and none from this card: 120 runs with no integrity stamp and three assumption sets
missing card 0064's shipped figures.

Built in a worktree, so the new results note **has not been seen in a browser**.
