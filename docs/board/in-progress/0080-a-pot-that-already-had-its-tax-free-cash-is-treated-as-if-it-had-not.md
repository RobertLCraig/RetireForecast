# A pot that already had its tax-free cash is treated as if it had not

## Why
A pension pot has two halves, and only one of them still has tax-free cash in it.

- **Uncrystallised** money has not been touched. A quarter of anything drawn out of it is tax-free.
- **Crystallised** money has already been through that step. It is in drawdown, it has had its
  quarter, and every pound drawn out of it is taxed as income.

Card 0007 taught the forecast the difference **for a lump sum taken during the projection**: instruct
£100,000 of tax-free cash and the model now knows the £300,000 left behind it is crystallised, so a
later draw does not take a second quarter of the same money.

It cannot do the same for a pot the reader **starts** with. The builder asks "Tax-free cash already
taken (£)", and that figure is used, but only as an allowance ledger: `DcPension::$pclsTakenToDate`
is defined as lump-sum-allowance use **across all of the member's pensions**, so it does not say
which pot the cash came out of or how much of that pot was crystallised to pay it. The projector
therefore starts every pot as wholly uncrystallised (`PathProjector`, the `crystallised` key).

For anyone who has already taken tax-free cash, the forecast gives them a second quarter of it. On a
mid-sized pot drawn over a retirement that is thousands of pounds of tax the reader would really pay,
missing from the answer — and it is silent, because the pot and the allowance ledger both look right.

The reader knows the answer. The model does not ask.

## Links

**Relates to**
- `0007` - it taught the forecast the tax-free quarter, the lump sum allowance and crystallisation
  for a lump sum taken during the projection; this card carries the same distinction backwards, to
  a pot that was already crystallised before the projection starts.
- `0079` - how an inherited pot is taxed, which is the neighbouring case and a card of its own.

## Not this card
The tax-free quarter itself, the lump sum allowance, and crystallisation of a lump sum taken during
the projection: all built on card 0007 (DECISIONS 2026-08-19 item 11). How an inherited pot is taxed
is card 0079.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL let the reader say how much of a pension pot is already in drawdown. proves: `test_a_pot_can_be_entered_as_partly_crystallised`
- [ ] #2 WHEN a pot is entered as already in drawdown, THE APP SHALL charge full income tax on that part of it and give no tax-free quarter. proves: `test_a_draw_from_an_already_crystallised_pot_takes_no_tax_free_quarter`
- [ ] #3 WHERE the reader does not say, THE APP SHALL disclose which answer it assumed and what that answer costs them. proves: `test_the_assumed_crystallised_share_is_disclosed`
<!-- AC:END -->

## Tasks
- [ ] Add the field to `DcPension` and the builder beside "Tax-free cash already taken", defaulting
      to today's behaviour so no stored scenario moves until the reader answers
- [ ] Move all four things a new builder field needs together: the blank default, the validation,
      the `loadState` backfill and `BuilderStateFixture::full`
- [ ] Seed `PathProjector`'s `crystallised` key from it and delete the v1 note there
- [ ] Decide, and record, whether an unanswered pot should keep assuming wholly uncrystallised (the
      generous side) or infer a crystallised share from `pclsTakenToDate` (the adverse side, and a
      guess). Rob's call: it moves every stored scenario that has ever taken tax-free cash
- [ ] Re-run every stored scenario and `php artisan scenarios:audit`; bump `ENGINE_VERSION` if
      stored figures move
