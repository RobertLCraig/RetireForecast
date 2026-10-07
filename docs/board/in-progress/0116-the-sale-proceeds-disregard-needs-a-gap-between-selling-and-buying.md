# The sale-proceeds disregard cannot bite until a plan can sell in one year and buy in another

## Why
Card 0048's criterion #2 asked for the statutory disregard of the proceeds of a former home held
with the intention of buying another, and was left open twice, on 2026-09-06 and again on the
resumed run. The reason is not that the rule was skipped. It is that no state in this engine can
hold those proceeds for a single modelled day.

`Dto\HousingAction` carries a sale price, a buy price and the costs, and **no year, month or date**.
`Housing\HousingComparison` therefore applies the whole housing transform BEFORE year 0:
`buyVariant` sells, buys and banks only the surplus in one step. The money that buys the new home is
never assessable capital, and the surplus that IS assessed from year 0 is money the household kept,
not money it holds in order to buy with. The other two states that hold proceeds, `rentVariant` and
the mid-projection forced sale, both rent from that year on, so neither carries an intention to buy.

So the disregard has no reachable state, and a test written for it today would have to construct the
state by hand and would prove nothing about the projection. Making it reachable means modelling a
purchase that completes some months after the sale. That is a new modelling capability, not a bug
fix, and card 0048 was right not to grow into it.

**Whether it is worth having is Rob's call, and that is the first acceptance line.** The disregard
runs for 26 weeks from the sale (longer where the local authority judges it reasonable), so it can
only ever move a plan whose sale and purchase straddle a year boundary AND whose capital in that one
year is near a limit. Against that: the capital limits bite on the means-tested benefits the tool
now models, and a chain that breaks is exactly the risk a downsizing plan carries.

## Links

**Relates to**
- `0048` - raised this; its comment thread carries the full reasoning against reaching the state.
- `0102` - the other `HousingAction` shape gap (a couple cannot own 70/30).

## Not this card
The pension-age Housing Benefit calculation and the notional costs-of-sale deduction, both built by
card 0048. The 12-week property disregard in the CARE assessment, which is a separate rule and a
documented v1 simplification.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL record a decision from Rob on whether a gap between selling and buying is worth modelling at all, with the reasoning, in docs/DECISIONS.md. proves: none
- [ ] IF it is, WHEN a plan's purchase completes in a later year than its sale, THE APP SHALL hold the proceeds as capital in the intervening years. proves: `test_a_deferred_purchase_holds_the_proceeds_as_capital`
- [ ] IF it is, WHEN proceeds of a former home are held with the intention of buying another, THE APP SHALL disregard them from the means-tested capital assessment for the statutory period, sourced and dated. proves: `test_proceeds_held_to_buy_are_disregarded_for_the_statutory_period`
<!-- AC:END -->

## Tasks
- [ ] Put the question to Rob first. The other two lines are dead if the answer is no.
- [ ] If yes: give `HousingAction` a completion offset, and split `HousingComparison::buyVariant`
      into a sale leg and a purchase leg the projector fires in different years.
- [ ] If yes: source the disregard period (Regulation 10 and Schedule 10 of the pension-age Housing
      Benefit regulations govern it) with a `verified_on` date, and add it to
      `Benefits\CapitalAssessment` beside the tariff.
- [ ] If yes: bump `ScenarioForecaster::ENGINE_VERSION` and re-run every stored scenario. Every
      buy-outright plan changes shape, not just the ones the disregard touches.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Read `Housing\HousingComparison` end to end before
proposing anything: it builds both variants before the projection starts, and every caller assumes
the household it hands back is already in its post-move shape.

The means-test seam is `Benefits\CapitalAssessment`, which card 0048 left as the one definition of
assessable capital, and `PathProjector::meansTestAssessableCapital`, its only caller.

Run `php artisan test` and `php artisan scenarios:audit` after.

## Comments
