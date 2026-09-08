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
- [ ] #1 THE APP SHALL report spendable wealth net of the tax that would be due on the pension part. proves: `test_spendable_wealth_is_net_of_tax_on_the_pension_part`
- [ ] #2 THE APP SHALL state the rate it netted at and where it came from. proves: `test_the_netting_rate_is_disclosed`
- [ ] #3 THE APP SHALL keep the tax-free part of a pension pot unnetted, up to what is left of the lump sum allowance. proves: `test_the_tax_free_quarter_is_not_netted`
<!-- AC:END -->

## Tasks
- [ ] Net the pension component in `PathProjector`'s `terminalUsableWealth`, at the projected marginal rate
- [ ] Check every consumer: the safety buffer, the plan ranking, the Monte Carlo percentiles, the PDF
- [ ] Disclose the rate as an assumed figure; re-run `php artisan scenarios:audit`
