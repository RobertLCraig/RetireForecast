# An inherited pension is taxed in full even when it should be tax-free

## Why
When somebody dies and leaves their pension pot to a partner, how the partner is taxed on what they
draw from it turns on one fact: how old the person was when they died.

- Died **under 75**: the partner draws it as **tax-free income**. No income tax at all.
- Died at **75 or over**: the partner pays income tax on it at their own marginal rate.

The forecast charges full income tax in both cases. So a household where the first death is early is
shown paying tax it would not really pay, on money that is often the largest single asset the
survivor is left with. On a mid-sized pot over a survivor's remaining years that is tens of
thousands of pounds of tax that does not exist, and it makes every plan that leans on an inherited
pot look worse than it is.

This is not a rule anybody chose. `PathProjector::settleEstates` folds the deceased's pots into one
inherited pot for the heir and **does not record how old they were when they died**, so by the time
the pot is drawn the fact that decides its tax treatment is gone. Charging full tax was the cautious
side of a distinction the model could not make. It is recorded as "not settled" in
[DECISIONS.md](../../DECISIONS.md), 2026-08-19 decision 7.

The same date is already known one line away: `recordDeathInServiceBenefit` stashes `ageAtDeath` for
exactly this reason, and `collectDeathInServiceBenefit` splits an employer death lump sum on it. So
the engine already encodes the under-75 rule for the lump-sum form of the same money, and the
drawdown form of it does not ask.

## Not this card
The estate panel for deaths at or after 75 (card 0057). The April 2027 change bringing unused
pension funds into the estate for Inheritance Tax, which is a separate charge and already modelled.
Whether an inherited pot should trigger the heir's MPAA or carry a tax-free quarter: both settled on
card 0007 (it does neither).

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL record the deceased's age at death on the pot the heir inherits. proves: `test_an_inherited_pot_carries_the_age_at_which_its_owner_died`
- [ ] #2 WHEN the member died under 75, THE APP SHALL treat a draw from the inherited pot as tax-free income. proves: `test_a_draw_from_a_pot_inherited_from_someone_who_died_under_75_is_tax_free`
- [ ] #3 WHEN the member died at 75 or over, THE APP SHALL tax the draw as the heir's income, as now. proves: `test_a_draw_from_a_pot_inherited_from_someone_who_died_at_75_or_over_is_taxed`
- [ ] #4 THE APP SHALL tell the reader which treatment applied and why. proves: `test_the_inherited_pension_tax_treatment_is_disclosed`
<!-- AC:END -->

## Tasks
- [ ] Stash the age at death on the inherited pot in `PathProjector::settleEstates`, the way
      `recordDeathInServiceBenefit` already stashes it for the lump-sum route
- [ ] Read it in both draw closures in `PathProjector::fundShortfall`, so the treatment cannot
      depend on the draw order
- [ ] Verify the two rules against HMRC's Pensions Tax Manual and record source + verified-on, as
      `collectDeathInServiceBenefit` already does for the lump-sum form
- [ ] Re-run every stored scenario and `php artisan scenarios:audit`; bump `ENGINE_VERSION` if
      stored figures move
