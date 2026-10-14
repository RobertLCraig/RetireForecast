# The buy-price sweep drops the bought home's own running costs and growth

## Why
`BuyPriceLever::apply` rebuilds the `HousingAction` field by field to change the buy price, and it
leaves out `buyRunningCosts` and `buyGrowthOverride`. So every point the sweep prices runs the bought
home on derived upkeep and the assumption set's house growth, not the figures the reader entered.
For a park home that is the 1%-of-value proxy in place of a flat pitch fee, and bricks-and-mortar
growth in place of depreciation: the sweep reads a depreciating home as better than the plan does.

Found while building card 0112, which added another field to `HousingAction`.

## Links

**Relates to**
- `0112` - added `rentCouncilTax` to `HousingAction`; the lever drops that too, harmlessly, as it only
  reads the buy variant.

## Not this card
Any other lever, and what the sweep is for.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN the buy-price sweep prices a buy price, THE APP SHALL use the bought home's entered running costs and growth. proves: `test_the_buy_price_sweep_keeps_the_bought_homes_own_costs_and_growth`
<!-- AC:END -->

## Plan
Copy the action with only the buy price changed, so a field added later cannot be dropped either.
`packages/finance-engine/src/Sweep/Lever/BuyPriceLever.php`.

## Comments
