---
needs: 0026
---
# A freehold seller is charged leasehold fees by default

## Why
Card 0032 added the fees a leasehold sale really pays: the managing agent's management pack (£500)
and the licence to assign plus the notice and deed-of-covenant fees (£700). They ship as editable
lines with figures in them, on every new forecast.

Somebody selling a **freehold house** owes neither. If they do not notice and clear those two
lines, their sale is charged £1,200 that will never be billed, so their sell plans look worse than
they are and the comparison the tool exists to make is tilted against selling. On a £300,000 sale
that is 0.4% of the price, which is the same order as the gap between the leading plans.

There is no way for the app to know which it is. `Property` has fifteen fields and none of them is
tenure, so the builder cannot ask "is this leasehold?" and the engine cannot branch on the answer.
Card 0032 shipped the leasehold figures anyway, because the adverse default is the cautious one and
the tool's own subject property is a flat, and put a note on the builder telling a freeholder to
clear the two lines. A note somebody has to read is weaker than a control that does the right thing.

Card 0028 hit the same wall and worked around it the same way, using a positive `while_owning_home`
expense bucket as a leasehold proxy.

## Links

**Blocked by**
- `0026` - it is the card that adds tenure to `Property` (with the lease term). Adding a second,
  separate tenure flag here would give the model two answers to the same question.

**Relates to**
- `0032` - added the leasehold lines and the note that currently stands in for the gate.
- `0028` - the same missing tenure field, worked around with the service-charge proxy.
- `0092` - re-sourcing the fee figures themselves; this card only decides when they are charged.

## Not this card
Re-sourcing the fee figures. That is card 0092. This card only decides **when** they are charged.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a property is entered as freehold, THE APP SHALL leave the management pack and licence-to-assign lines at nil rather than charging them. proves: `test_a_freehold_sale_is_not_charged_the_leasehold_fees`
- [ ] WHEN a property is entered as leasehold, THE APP SHALL charge them and say that is why. proves: `test_a_leasehold_sale_is_charged_the_leasehold_fees`
<!-- AC:END -->

## Tasks
- [ ] Read the tenure field card 0026 adds to `Property`, and carry it through
      `HouseholdAssembler` to the housing step.
- [ ] Gate the two leasehold lines in `ScenarioBuilder::defaultSellingCosts()` on it, and delete
      the builder note that currently asks the reader to do this by hand.
- [ ] Decide what an EXISTING saved scenario does. Its lines already carry figures, so the gate
      must not silently rewrite a reader's own entry.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The two lines are `management_pack` and
`licence_to_assign` in `defaultSellingCosts()` in `app/Livewire/ScenarioBuilder.php`; the note to
delete is the second paragraph under "Selling costs" in
`resources/views/livewire/scenario-builder.blade.php`. The fixture is
`tests/Feature/Livewire/ScenarioBuilderTest.php`
(`test_the_housing_step_itemises_the_leasehold_sale_fees_separately_from_conveyancing`).

Adding a builder field means moving four things together, or a what-if child gets a spurious delta:
the blank-state default, the `rules()` entry, the `loadState` backfill, and
`Tests\Support\BuilderStateFixture::full()`.

No `ENGINE_VERSION` bump: this changes what a scenario is built with, not what the engine does with
it. A stored freehold scenario re-run after the gate lands will move, so say so when it does.

## Comments
