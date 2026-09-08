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
- [x] #1 THE APP SHALL let the reader say how much of a pension pot is already in drawdown. proves: `test_a_pot_can_be_entered_as_partly_crystallised`
- [x] #2 WHEN a pot is entered as already in drawdown, THE APP SHALL charge full income tax on that part of it and give no tax-free quarter. proves: `test_a_draw_from_an_already_crystallised_pot_takes_no_tax_free_quarter`
- [x] #3 WHERE the reader does not say, THE APP SHALL disclose which answer it assumed and what that answer costs them. proves: `test_the_assumed_crystallised_share_is_disclosed`
<!-- AC:END -->

## Tasks
- [x] Add the field to `DcPension` and the builder beside "Tax-free cash already taken", defaulting
      to today's behaviour so no stored scenario moves until the reader answers
- [x] Move all four things a new builder field needs together: the blank default, the validation,
      the `loadState` backfill and `BuilderStateFixture::full`
- [x] Seed `PathProjector`'s `crystallised` key from it and delete the v1 note there
- [ ] Decide, and record, whether an unanswered pot should keep assuming wholly uncrystallised (the
      generous side) or infer a crystallised share from `pclsTakenToDate` (the adverse side, and a
      guess). Rob's call: it moves every stored scenario that has ever taken tax-free cash
- [ ] Re-run every stored scenario and `php artisan scenarios:audit`; bump `ENGINE_VERSION` if
      stored figures move

## Comments

**2026-09-08**
RESULT: partial
TESTS: +8 new, all green
TOUCHED: packages/finance-engine/src/Dto/DcPension.php
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/StartingCrystallisedPotTest.php
TOUCHED: app/Forecast/HouseholdAssembler.php
TOUCHED: app/Forecast/ResultPresenter.php
TOUCHED: app/Forecast/WhatIfChanges.php
TOUCHED: app/Livewire/ScenarioBuilder.php
TOUCHED: resources/views/livewire/scenario-builder.blade.php
TOUCHED: tests/Feature/Livewire/ScenarioBuilderTest.php
TOUCHED: tests/Support/BuilderStateFixture.php
TOUCHED: tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
TOUCHED: docs/DATA-MODEL.md
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none

All three criteria are met. The card is partial only because its fourth Task is a call for Rob, set
out below.

`DcPension::$crystallisedValue` is the fact, read through `crystallisedValue()` (clamped to the pot,
so the capacity-for-loss stress cannot leave a crystallised balance bigger than the pot it sits in)
and `crystallisationIsAssumed()`. `PathProjector` seeds its `crystallised` key from it, replacing
the `=> 0` and its v1 note. Nothing else in the engine changed: the draw order inside a pot,
`ufplsSplit`, `lsaHeadroom` and `pensionTaxIfDrawn` were already written against that key.

Each criterion was watched failing for its own reason. #2 first failed with a tax-free
`pension_lump_sum` of £10,000 paid out of a pot stated as wholly in drawdown; #1 with the entered
£80,000 reaching the engine as 0; #3 with no disclosure mentioning the assumption at all. The engine
test carries a control (an untouched pot still gets its quarter), so it cannot pass on a projector
that pays no tax-free cash at all.

Builder: the four things moved together (blank default, validation, `loadState` backfill and
`BuilderStateFixture::full`, blank in all four fixture pensions to match the backfill), plus the
assembler, the money-formatting list in `WhatIfChanges` and the form input with its own help text.

Assumed: nothing about how much of a pot is crystallised. Unanswered stays wholly uncrystallised,
which is what Task 1 asked for, so no stored figure moves: **no `ENGINE_VERSION` bump and no stored
re-run is owed** and `GoldenMasterTest` did not redden. `ResultPresenter::assumedFigures()`
discloses the assumption with the tax-free cash it still grants, read off the pots and off the tax
year's own PCLS rate and lump sum allowance rather than restated. The note is emitted only where run
settings are passed, which every real surface does (the results page, the PDF and the audit all pass
them) and which is how the pension disclosures beside it are already gated.

Could not settle from the repository: **the card's fourth Task**, whether an unanswered pot should
instead INFER a crystallised share from `pclsTakenToDate`. The card names it as Rob's, it would move
every stored scenario that has ever taken tax-free cash, and the repository holds nothing saying
which pot that cash came out of. It is left open here and recorded as open in DECISIONS 2026-09-08.

The last Task is left open too. `php artisan scenarios:audit` still exits non-zero, but on the
pre-existing stale-stamp class alone (runs predating the integrity column, and the re-runs owed by
cards 0076 to 0079). Nothing in its output mentions crystallisation, and this card moves no stored
figure, so it owes no re-run of its own.

Built in a worktree, so the new builder input and the new results note **have not been seen in a
browser**.
