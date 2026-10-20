# A tax-free inherited pension draw is invisible to the Pension Credit test

## Why
Card 0079 made a draw from a pot inherited from a member who died under 75 tax-free, which is right
for income tax. It reports that draw through `fundShortfall`'s `fromPensionTaxFree` channel, which
exists for the tax-free QUARTER of an ordinary draw, and the tax-free quarter is deliberately kept
out of the Pension Credit means test: it is capital in the claimant's hands, not income.

An inherited pension income is not capital. It is income that happens to carry no income tax, and
Pension Credit assesses income whether or not it is taxable. So a survivor drawing thousands a year
out of an inherited pot is assessed on none of it, and keeps a credit the means test would have
reduced pound for pound. That is the same shape of fault card 0077 fixed for an ordinary draw, on
the one household most likely to be claiming: a survivor living on an inherited pot.

The workaround was deliberate and is recorded in DECISIONS 2026-09-08: the alternative needed a
third channel through the whole funding path (money that is income for the means test but not for
income tax), which was more than card 0079 had decided.

## Links

**Relates to**
- `0079` - made the draw tax-free and chose the reporting channel.
- `0077` - settled that a pension draw must reach the Pension Credit means test, and how.
- `0074` - owns the tax-free line the draw currently rides.

## Not this card
Whether the draw should be tax-free at all (settled, card 0079). The care means test, which assesses
income on its own rules and needs its own reading. The cashflow ladder's LINE for the draw: filing
it as tax-free money on the ladder is correct whatever the means test does with it, so if the two
have to part company, part them at the means test and not on the screen.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL assess a draw from a tax-free inherited pension as income for Pension Credit. proves: `test_a_tax_free_inherited_pension_draw_is_assessed_for_pension_credit`
- [x] #2 THE APP SHALL keep the tax-free QUARTER of an ordinary draw out of that assessment, as now. proves: `test_the_tax_free_quarter_of_an_ordinary_draw_is_still_not_assessed`
<!-- AC:END -->

## Tasks
- [ ] Verify against the Pension Credit income rules that a beneficiary drawdown income is assessed
      in full whatever its tax treatment, and record source + verified-on.
- [ ] Give `fundShortfall` a way to report money that is tax-free but assessable, and read it where
      `$taxablePensionDrawn` is computed in `PathProjector::projectYear`.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and note the owed re-run; expect
      `MonteCarlo\GoldenMasterTest` red and re-pin it with its DECISIONS entry.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The three seams: `PathProjector::fundShortfall`
returns the array the year reads, `PathProjector::projectYear` computes `$taxablePensionDrawn` from
it (search for the comment about the tax-free quarter being capital), and the Pension Credit fixed
point around it is card 0077's work, so read that loop before changing what feeds it.

Run `php artisan test` and `php artisan scenarios:audit` after.

## Comments

**2026-10-20** RESULT: done
TESTS: +3 new (1 test x 2 draw orders, 1 test), all green
TOUCHED:
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/InheritedPensionDrawPensionCreditTest.php
packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php
app/Forecast/ScenarioForecaster.php
docs/DECISIONS.md
docs/HANDOVER.md
docs/board/todo/0208-a-taxed-draw-with-no-tax-free-quarter-has-no-pension-credit-settling-test.md
OUT-OF-SCOPE: 0208

No third channel was needed: fundShortfall already returned fromInheritedTaxFree apart from fromPensionTaxFree. projectYear now assesses the draw less the tax-free quarter only ($taxablePensionDrawn renamed $assessablePensionDrawn). The ladder line is unchanged. The card 0143 top-up condition now counts the inherited draw towards taking the whole award; the top-up itself still draws taxable pots only.

Watched failing: #1 failed for the card's reason (the survivor kept 753,948p of credit while drawing the inherited pot, under both TaxEfficient and FillBands). With only the assessment change, #1 STILL failed: when every pound drawn is assessed, the award loop's secant flew off on near-parallel gaps and the 8 passes ran out on the unreduced award. So the step now goes to a nil award when the gap's slope is not negative. Checked: with the old step back, #1 goes red again. #2 was green before the change (it guards behaviour that must not move); it was watched failing by temporarily assessing the quarter too.

The loop fix also changes an ordinary taxed draw under TaxEfficient, which had the same non-settling fault. That case has no test of its own: card 0208.

ENGINE_VERSION is finance-engine/inherited-draw-assessed-for-pension-credit; re-run owed for Pension Credit plans drawing an inherited or taxed pot. GoldenMaster re-pinned (only terminalWealth.p90 moved, by 138.66 pounds) with its DECISIONS entry.

NOT DONE: task 1 (cite the Pension Credit income rule with source + verified-on). WebFetch was refused in this unattended session, so the rule is not cited; DECISIONS says so. scenarios:audit exits non-zero on old database state only (runs with no integrity stamp, assumption sets not re-seeded, as in the handover), nothing from this change. No UI changed, so no browser check is owed.
