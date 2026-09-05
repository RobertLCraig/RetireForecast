# A house sale happens instantly and costs nothing to bridge

## Why
From the expert panel, 2026-08-19 (property finding 3, adviser finding 9). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`HousingComparison` sells at year 0 and `withoutPropertyCosts()` strips the mortgage and the
service charge from year 0 entirely. The in-projection forced sale does the same from the sale
year. There is no time-to-sell, no chain, no void, no bridging and no dual running anywhere in the
engine.

A realistic instruction-to-completion on a leasehold flat is months, not days, and the seller pays
the mortgage, the service charge and the council tax throughout. A gap between sale and purchase
adds storage, a short let and a second council tax bill. On a sell plan that is a meaningful
fraction of the modelled net proceeds, and every sell plan carries it.

A forced sale is also not a willing sale. Repossession and deadline sales clear below open market,
and the engine sells at full modelled value.

## Not this card
Modelling a failed sale as its own branch. That is card 0069.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a home is sold, THE APP SHALL charge a disclosed transition cost covering the months between instruction and completion.
- [ ] #2 THE APP SHALL expose the assumed months to sell as an editable input with a sourced default.
- [ ] #3 WHEN a sale is forced by a mortgage maturity, THE APP SHALL apply a sourced, editable forced-sale discount to the price achieved.
<!-- AC:END -->

## Tasks
- [ ] Add a transition-cost one-off in the sale year, derived from the months-to-sell input
- [ ] Add a `forcedSaleDiscount` to `MortgageMaturityAction::ForcedSale`, sourced and dated
- [ ] Disclose both through `ResultPresenter::assumedFigures()`
- [ ] Tests for both, then re-run every stored scenario

## Comments

**2026-09-05**
RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0027-a-sale-is-instant-and-costless.md
OUT-OF-SCOPE: none

All three criteria turn on a figure that has to come from outside this repository, and this
session cannot reach one. `WebSearch`, `WebFetch` and the Playwright browser tool were each
tried and each refused in an unattended card session. Nothing in the tree carries the numbers
either: `docs/spec/ASSUMPTIONS.md`, `docs/research/` and `docs/spec/METHODOLOGY.md` say nothing
about how long a sale takes or what a forced sale clears, and the panel report this card cites
(`docs/REVIEW-PANEL-2026-08-19.local.md`) is gitignored, so it is not in the worktree. Two
numbers are missing, both of them ranking-movers:

1. **Months from instruction to completion**, England and Wales, split leasehold vs freehold if
   the source gives it. #1 and #2 both hang on it.
2. **The forced-sale discount** to open market value on a repossession or deadline sale. #3
   hangs on it.

Each needs a named source and a `verified_on` date, the way
`HousingComparison::HOME_MAINTENANCE_RATE_BPS` carries Checkatrade 2023. I did not fill either
in from memory: an unsourced constant here reshapes every sell plan, which is the one thing the
no-magic-numbers rule exists to stop. I also did not build the mechanism against a zero default
— that leaves the engine still saying a sale is instant, ticks nothing, and the disclosure copy
has to be rewritten around the real figures anyway.

What I did settle by reading, so the next session does not redo it:

- **The sale is costless in exactly two places.** `HousingComparison::withHousing()` calls
  `ExpenseProfile::withoutPropertyCosts()`, dropping the mortgage line and the
  `while_owning_home` bucket from year 0 of both sell variants
  (`packages/finance-engine/src/Housing/HousingComparison.php:333`). `PathProjector` drops the
  same two from the sale year on (`packages/finance-engine/src/Forecast/PathProjector.php:1089`
  and `:1096`).
- **So the transition cost is those two buckets for N months**: mortgage payment plus the
  property-costs bucket, times months/12. Council tax needs no separate charge — it is in
  neither stripped bucket, so it keeps being charged, which is right (they pay it somewhere
  either way). Storage and a short let belong to a gap between sale and purchase, which the
  acceptance does not cover and which card 0069 owns.
- **The charge has a home already.** `ExpenseProfile::withOneOffCost()` is the mechanism, and
  `withHousing()` already calls it for the unfunded purchase gap, so a second one-off in the
  sale year is an append rather than new plumbing.
- **The discount goes on the gross price** — `$state['propertyWhole']` at
  `PathProjector.php:1048`, before `HousingProceeds::compute()`. That is "the price achieved",
  and it correctly drags CGT down with it.
- **Where the inputs live**: `HousingAction::$monthsToSell` plus `ForecastSettings::$monthsToSell`
  (the projector holds no `HousingAction`, the same reason `$sellingCosts` is duplicated onto
  settings), and `Property::$forcedSaleDiscount`. Any new `Property` field must also be carried
  through `Property::withCurrentValue()` or `AssetWitherTest` fails. The builder field moves four
  things together — blank default, validation rule, `loadState` backfill and
  `BuilderStateFixture::full` — or child what-if deltas break.

Two other things the next attempt needs. No criterion here carries a `proves:` name, which
`docs/board/README.md` requires and the build loop enforces, so those need writing first. And
the last task, re-running every stored scenario, cannot be done from a worktree at all: the live
database is not reachable from here, which is why card 0024's re-run is still owed.
