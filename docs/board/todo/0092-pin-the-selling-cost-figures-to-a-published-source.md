# Pin the selling-cost figures to a published source

## Why
Card 0032 repriced what selling a home costs. Two of its figures move real money on every plan that
sells, and neither is cited to anything published:

- `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` = **400** (4% of the sale price), the all-in
  catch-all the engine charges when the reader itemises nothing. It doubled from 2%.
- `HousingProceeds::CGT_RETURN_FEE_PENCE` = **£750**, the accountant's fee for preparing the 60-day
  capital-gains return, charged only on a disposal that owes tax.

The six itemised figures the builder ships alongside them are in the same position: estate agent
1.5%, leasehold conveyancing £2,000, management pack £500, licence to assign plus notices £700,
removals £1,200, energy certificate £80 (`ScenarioBuilder::defaultSellingCosts()`).

"Nearer 4%" is the property reviewer's judgement in the 2026-08-19 expert review. Everything else in
the list is the building session's own reading of ordinary UK practice.
`docs/spec/ASSUMPTIONS.md` §16 says so out loud.

The 60-day deadline itself is NOT part of this gap. Reporting and paying within 60 days of
completion is statute, and only the price of preparing the return is unsourced.

It matters more than the size of any one figure suggests: selling costs come off the net proceeds,
and the net proceeds are what the whole buy-versus-rent comparison rests on.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0032 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0032` - set these constants, and the itemised builder lines that sit beside them.
- `0085`, `0086`, `0087`, `0091` - the same shape of gap, from the same review and the same missing
  web access. Whoever picks one up can settle all five in one research pass.

## Not this card
The mechanism, the itemisation and the disclosure. All three are card 0032's and are built. This
card only replaces numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the all-in selling-cost rate and the capital-gains return fee in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one, and the disclosure that reads the constant SHALL move with it. proves: `test_the_assumed_selling_cost_rate_is_read_from_the_engine_constant`
<!-- AC:END -->

## Tasks
- [ ] Find a published all-in cost-of-moving or cost-of-selling series (Reallymoving, Compare My
      Move, Which?, an estate-agency fee survey) and check whether it splits leasehold from freehold.
- [ ] Find published figures for the leasehold-specific fees: a managing agent's management pack,
      a licence to assign, and notice of transfer / deed of covenant. The Leasehold Advisory Service
      and the RICS/ARMA service-charge guidance are the likely places.
- [ ] Find an accountancy fee benchmark for a single 60-day UK property capital-gains return.
- [ ] Check whether a whole-of-market rate is even the right shape, or whether the flat fees should
      scale with the sale price.
- [ ] Set the constants in `packages/finance-engine/src/Housing/HousingProceeds.php` and the line
      values in `ScenarioBuilder::defaultSellingCosts()`, or record on this card why the shipped
      figures stand.
- [ ] Update ASSUMPTIONS.md §16, moving it out of the sourcing-gap list, and add the citations.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Both engine figures
are constants in `packages/finance-engine/src/Housing/HousingProceeds.php`, and the results-page
disclosure and the sale waterfall both READ them, so changing one moves the screen with no other
edit. The builder's itemised defaults are `defaultSellingCosts()` in
`app/Livewire/ScenarioBuilder.php`. Fixtures:
`packages/finance-engine/tests/Housing/HousingProceedsReconciliationTest.php` (which reads the rate
constant rather than restating it) and `tests/Unit/Forecast/AssumptionsPanelTest.php`.

**A moved figure needs an `ENGINE_VERSION` bump** in `app/Forecast/ScenarioForecaster.php` and a
re-run of every stored scenario, because these figures change projected money. Run
`php artisan test` and `php artisan scenarios:audit` after.

## Comments
