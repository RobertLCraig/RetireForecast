# A capital gain is rate-banded against income the person no longer has

## Why
Capital Gains Tax stacks ABOVE income: only the basic-rate band left after a person's income fills
at 18%, the rest at 24%. `PathProjector::capitalGainsTax` is handed `$taxablePerPerson` to make that
split, and that array is the year's **non-savings income before any shortfall was funded**. It is
the wrong income on two counts at once:

- **It excludes savings and dividends.** Both are taxable income and both fill band space, so a
  retiree with interest and share income has more of their gain charged at 18% than they really do.
  The `capitalGainsTax` docblock flags this as a v1 simplification, but nothing carries it.
- **It excludes this year's pension drawdown.** The gains are realised INSIDE `fundShortfall`, by
  the very draws that also take pension income, and the band split still reads the figure from
  before any of it. So the household that sells holdings to fund a big withdrawal is the one whose
  gain is banded lowest.

Both errors run the same way: too much of the gain is charged at the lower rate, so the tax is too
low, the household keeps money it would not have, and its wealth and success odds are too
favourable. It bites hardest on the plan the tool exists to price, a household selling investments
and drawing a pension in the same year.

Card 0037 fixed exactly this shape of fault for the pension draw's own income tax and left the CGT
band alone, because that was outside its acceptance.

## Links

**Relates to**
- `0037` - fixed the sibling fault (the marginal income tax on a drawdown draw was priced against
  non-savings income only, and restarted from the pre-drawdown figure on a second pass). Its
  reconciliation test covers income tax only, and deliberately builds a fixture with no gains.

## Not this card
Capital losses, which `capitalGainsTax` also does not relieve. That is a separate gap and needs its
own input before it can be modelled.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a person realises a gain, THE APP SHALL band it against their whole income for the year, savings and dividends included. proves: `test_a_gain_is_banded_against_savings_and_dividend_income_too`
- [ ] WHEN a gain is realised funding a pension draw, THE APP SHALL band it against the income that draw leaves the person on. proves: `test_a_gain_realised_beside_a_pension_draw_is_banded_above_it`
<!-- AC:END -->

## Tasks
- [ ] Feed `capitalGainsTax` the post-drawdown income. `fundShortfall` already keeps a running
      per-person non-savings total (`$drawnTaxable`, added by card 0037); the savings and dividend
      arrays it now receives are the other two legs.
- [ ] Watch the ordering: the CGT top-up draw at the end of `fundShortfall` itself adds pension
      income, and the existing comment says the small extra gain from funding the tax is
      deliberately not re-taxed. Decide and write down whether the band follows the same rule.
- [ ] Remove the v1-limit sentence in the `capitalGainsTax` docblock once it is no longer true.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: any plan realising a gain beside other income pays
      more tax under the fix.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Everything is in
`packages/finance-engine/src/Forecast/PathProjector.php`: `capitalGainsTax`, the public static
`cgtOnGain` it calls (whose band split is already unit-tested directly), and the call site at the
foot of `fundShortfall`. The fixtures are
`packages/finance-engine/tests/Forecast/GiaCapitalGainsTaxTest.php` and
`DrawdownMarginalTaxTest.php`. Run `php artisan test --testsuite=Engine`, then the full suite.

## Comments
