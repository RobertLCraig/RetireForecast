# Modelling and sourcing remainder

## Why
From the expert panel, 2026-08-19 (adviser findings 11 and 14). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. Small items, each of which the adviser would fix before
signing a report.

**Annuity rate and tax defects.** The rate is a single free-text field defaulted to a level
joint-life figure and coupled to nothing. Tick index-linked increases and you get an index-linked
annuity at a level annuity's rate - substantially too generous, silently. And there is no tax-free
lump sum interaction: the pot falls by the full amount and all the income is taxable, when in
reality a quarter comes out tax-free and the rest is annuitised. That under-rates annuitising
against drawdown.

**Chattels capital gains are not modelled.** A sale of personal possessions is chargeable above a
threshold per item or set. Where a plan turns on selling art or jewellery, the tax is missing
entirely and the plan is optimistic by the whole bill.

**Selling costs default too low** for a leasehold sale plus a move. See card 0032 for the
itemisation; this is the engine-wide default.

**The published methodology contradicts itself** on whether house prices and salaries have
year-to-year volatility in the Monte Carlo. One section says they do not and another says they do.
It is a user-facing page.

**Economic assumptions carry prose, not per-figure sources.** Statutory figures are properly sourced
and dated. The economic assumptions - which move the answer far more - sit behind a single prose
note with no machine-checkable freshness, and the freshness command does not cover them.

## Not this card
Buying an annuity with non-pension money, which is card 0060.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN an annuity's age, escalation basis or joint-life setting changes, THE APP SHALL recalculate the rate from a sourced table rather than leaving a typed figure standing.
- [ ] #2 WHEN an annuity is bought from a pension pot, THE APP SHALL take the tax-free lump sum first and annuitise the balance.
- [ ] #3 WHEN personal possessions are sold above the chargeable threshold, THE APP SHALL compute the capital gains tax.
- [ ] #4 THE APP SHALL carry a source URL and a verified-on date for every economic assumption, checked by the freshness command.
<!-- AC:END -->

## Tasks
- [ ] Add a sourced annuity rate matrix by age, single or joint, level or index-linked
- [ ] Split an annuity purchase into tax-free lump sum plus annuitised balance
- [ ] Add chattels capital gains to the disposal handling, sourced and dated
- [ ] Raise the default selling-cost rate; fix the methodology contradiction
- [ ] Extend `figures:freshness` to the economic assumptions
