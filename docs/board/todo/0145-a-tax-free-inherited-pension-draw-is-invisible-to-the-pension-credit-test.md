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
- [ ] #1 THE APP SHALL assess a draw from a tax-free inherited pension as income for Pension Credit. proves: `test_a_tax_free_inherited_pension_draw_is_assessed_for_pension_credit`
- [ ] #2 THE APP SHALL keep the tax-free QUARTER of an ordinary draw out of that assessment, as now. proves: `test_the_tax_free_quarter_of_an_ordinary_draw_is_still_not_assessed`
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
