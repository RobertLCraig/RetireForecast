# Pin the single-property volatility multiple to a published source

## Why
The engine now models the household's own home over **twice** the index house-price volatility:
18% real a year on the default set instead of 9% (`AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`,
built by card 0029). That figure widens the fan on every plan that keeps or buys a home, so it moves
the success probability, the capacity-for-loss headroom and the ranked comparison between staying put
and selling.

The 2.0 is the property reviewer's judgement in the 2026-08-19 expert review. It is **not a published
statistic**. Every other figure in `docs/spec/ASSUMPTIONS.md` cites a primary or fetchable secondary
source; this one and the CPI + 3% property-cost escalator (card 0085) are the two that do not, and
ASSUMPTIONS.md §13 says so out loud.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0029 could ship the mechanism and disclose the figure but could not go and check it.

## Links

**Relates to**
- `0085` - the same shape of gap, from the same review and the same missing web access, on the
  property-cost escalator. Whoever picks one up can settle both in one research pass.
- `0029` - built the mechanism and disclosed this figure, but had no web access to check it, which
  is why the gap is a card of its own.

## Not this card
Changing the mechanism. How the uplift is applied, disclosed and edited is card 0029's and is built.
This card only replaces the number and its citation, which is one constant and one doc section.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the single-property volatility multiple in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN the sourced figure differs from 2.0, THE APP SHALL use the sourced one and the disclosure SHALL move with it. proves: `test_the_uplift_scales_the_index_figure_rather_than_replacing_it`
<!-- AC:END -->

## Tasks
- [ ] Find a published estimate of UK single-property (idiosyncratic) house-price dispersion against
      an index. The repeat-sales literature is the likely home for it; Nationwide and Halifax publish
      index methodology that may quantify the residual.
- [ ] Set `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE` to what the source says, or record on
      this card why 2.0 stands.
- [ ] Update ASSUMPTIONS.md §13, moving it out of the sourcing-gap list, and add the citation to the
      source list at the foot of that file.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if the figure moves, and note that stored runs need
      re-running.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. The figure is one
constant in `packages/finance-engine/src/Dto/AssumptionSet.php`; the disclosure and the assumptions
panel both READ it, so changing it moves every screen with no other edit. Run
`php artisan test --testsuite=Engine` after, then the full suite.

## Comments
