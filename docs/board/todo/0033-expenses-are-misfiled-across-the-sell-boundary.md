# Selling a flat deletes utilities the household still has to pay

## Why
From the expert panel, 2026-08-19 (property findings 11 and 12). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Three categorisation errors, all of which flatter a result.

**Utilities hidden inside a service charge.** Where a service charge includes water and
electricity, `withoutPropertyCosts()` strips the whole charge on every sell variant while the
household keeps only whatever separate energy line was entered. A house or a park home still needs
energy and water, so every sell and buy plan is optimistic by that amount. A park home on bottled
gas is worse.

**Home insurance categorised as discretionary.** Buildings cover on a leasehold flat sits inside
the service charge, so a separate insurance line is contents plus something else. Whatever it is,
it is not discretionary while a lender requires cover. Being outside the essential floor flatters
the "essentials always met" probability on every scenario.

**Running costs as a percentage of value.** A blank `buyRunningCosts` derives 1% of value. A roof,
a boiler and a rewire cost the same in a cheap area as an expensive one, so a percentage of value
is a proxy for stock quality, not a cost driver, and it understates at the low end.

## Not this card
Major works and the service-charge escalator, which is card 0028.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a home whose service charge includes utilities is sold, THE APP SHALL add a replacement utilities cost to the new housing situation.
- [ ] #2 THE APP SHALL treat buildings and contents insurance as essential spend wherever cover is required.
- [ ] #3 WHEN a purchase running cost is derived rather than entered, THE APP SHALL disclose it as a computed figure with the rule that produced it.
<!-- AC:END -->

## Tasks
- [ ] Add a "service charge includes utilities" flag, and carry a replacement cost across a sale
- [ ] Reclassify insurance as essential; check `AssumedFiguresDisclosureTest` still passes
- [ ] Review `HOME_MAINTENANCE_RATE_BPS` against a flat-rate alternative, sourced
- [ ] Re-run every stored scenario, then `php artisan scenarios:audit`
