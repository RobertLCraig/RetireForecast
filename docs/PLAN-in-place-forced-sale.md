# PLAN: model a forced sale in place (sell at the redemption year, then rent)

> A spec for the last remaining Lane-B deferred item. A fresh agent should be able to build it end
> to end, BUT it carries real modelling decisions (see "Open questions" — get Rob's answers first).
> Read `HANDOVER.md` (orient) then this. Follows the doc standard + `CLAUDE.md` engine rules
> (framework-free engine, integer pence, reconciliation invariants, tests green on every commit).

**Stage:** design-spec (not built) — **has open decisions for Rob (do not build past them without answers).**
**Owner lane:** B (forced-housing-event workstream). The last open Lane-B item after the
mortgage-payment-stop shipped ([docs/PLAN-mortgage-payment-stop.md](PLAN-mortgage-payment-stop.md)).
_Last updated: 2026-07-01_

## What this is, and the gap it closes
`MortgageMaturityAction::ForcedSale` means "the home cannot be kept — it must be sold" (BTL breach,
a forced redemption with no refinance). Today it is **a no-op in the projection**: the projector
handles only `RepayFromCapital` ([PathProjector.php:461](../packages/finance-engine/src/Forecast/PathProjector.php#L461));
`ForcedSale`'s enum doc says _"v1 directs this to the sell variants"_
([MortgageMaturityAction.php:18-20](../packages/finance-engine/src/Dto/MortgageMaturityAction.php#L18-L20)),
and the results page just shows an **input note** telling the user to weigh the sell-and-rent /
buy-cheaper what-ifs on Compare
([ResultPresenter.php:899](../app/Forecast/ResultPresenter.php#L899)).

So a base / "stay put" projection with `ForcedSale` **keeps the home forever** — physically
impossible, the exact plausible-but-wrong outcome the project guards against. The existing sell
variants sell at **year 0**; they cannot express *own the home and pay the mortgage until the
redemption year, then be forced to sell*. "In place" = model the forced sale **within the projection
timeline, at the redemption year**, rather than only as a separate year-0 variant.

**When it matters:** `mortgageRedemptionYear > baseYear` (own for a few years, then forced out). When
`mortgageRedemptionYear == baseYear` (V2's case), the year-0 sell variants already approximate it — so
this generalises the timing, and makes a `ForcedSale` base scenario self-contained (no hand-built
what-if needed to see the realistic path).

## The event (design)
Add a **forced-sale event** in `PathProjector::projectYear`, parallel to the RepayFromCapital block
([PathProjector.php:451-468](../packages/finance-engine/src/Forecast/PathProjector.php#L451-L468)),
that fires once at the redemption year when `mortgageMaturityAction === ForcedSale`. It sells the home
and flips the household onto a renting footing **from that year on** via a new `state['homeSold']`
flag:

At the sale year:
1. **Sale price = the grown property value** this year (`state['property']`, which `growState`
   appreciates each year — [PathProjector.php:1320](../packages/finance-engine/src/Forecast/PathProjector.php#L1320)),
   NOT the year-0 `HousingAction::salePrice`.
2. **Net proceeds = sale price − outstanding mortgage − selling costs − CGT.** Reuse the engine's
   single source where possible: selling costs at the engine default (2%) or the entered rate; CGT via
   `CgtPrivateResidenceCalculator` on the gain (full PRR / £0 for a main home lived in throughout — the
   common case; partial PRR when a `CgtHistory` is present). **Note the sale price differs from
   `HousingComparison::saleProceeds` (which uses the year-0 price), so this needs a redemption-year
   variant of that decomposition — keep it reconcilable (parts sum to net).**
3. **Clear the debt + property:** `state['mortgageOutstanding'] = 0`, `state['property'] = 0`,
   `state['mortgageRepaid'] = true`, `state['homeSold'] = true`.
4. **Free the net proceeds into liquid wealth** — a GIA (or cash) balance for the first living person
   (drawable now the estate-inheritance fix has landed). Set its cost basis = proceeds (no latent gain).

From the sale year on (gate on `state['homeSold']`):
5. **Stop the property running costs** ([PathProjector.php:493-494](../packages/finance-engine/src/Forecast/PathProjector.php#L493-L494))
   and the `propertyCosts` + `mortgageCosts` contingent spend (the home is gone) — reuse the
   `while_mortgaged` / `while_owning_home` drop machinery just added.
6. **Charge rent** from that year (see Open question 1 for the rent level). The rent leg today keys off
   `settings->annualRent` for the whole projection
   ([PathProjector.php:485-486](../packages/finance-engine/src/Forecast/PathProjector.php#L485-L486)) —
   generalise it to also start when `homeSold` becomes true mid-projection.
7. **The freed proceeds are assessable capital** for Pension Credit from the sale year (they are no
   longer the exempt main residence) — the same rule the `isLet` case already applies
   ([PathProjector.php:619](../packages/finance-engine/src/Forecast/PathProjector.php#L619)); extend
   that condition to `|| homeSold`.

## Open questions (decisions for Rob — resolve before building)
1. **Rent after a forced sale.** What rent does the household pay once forced out? Options: (a) the
   scenario's `HousingAction::annualRent` if set, else a **% of the sale price** (a sourced gross-yield
   proxy, e.g. the ~5% the "let out & rent" preset uses); (b) require the user to enter it, and flag if
   missing. Recommendation: (a) with the entered rent taking precedence, a sourced default otherwise,
   surfaced in the assumptions/input notes.
2. **Base behaviour vs a generated what-if.** Should a `ForcedSale` scenario model the sale **in the
   base projection** (the base becomes realistic on its own), or should it keep directing to a
   **generated "forced sale → rent" what-if** (like the buy-vs-rent / let-out presets), leaving the
   base as-is? Recommendation: make the base realistic (the whole point of `ForcedSale` is that staying
   is impossible), and drop / soften the "weigh the alternatives on Compare" note accordingly.
3. **CGT on the grown value.** Confirm CGT is computed on the **sale-year** value (grown), with the
   `CgtHistory` occupation split as today. For a main residence lived in throughout it is £0, so this
   only bites for an ever-let home — acceptable to reuse the existing calculator on the grown gain?
4. **Where the proceeds land + running costs of renting.** GIA (taxable dividends/CGT) vs cash
   (interest)? And do we add a renter's running costs, or is rent-only sufficient (council tax etc.
   already sit in the spend lines)?

## Touch-points (once the decisions are made)
- **State** — add `state['homeSold'] = false` in `initialState()`
  ([PathProjector.php:225-236](../packages/finance-engine/src/Forecast/PathProjector.php#L225-L236)).
- **Event** — the forced-sale block in `projectYear` (after the RepayFromCapital block).
- **`ForecastSettings` / rent** — let rent start mid-projection when `homeSold` (not only the year-0
  `annualRent` leg).
- **Sale decomposition** — a redemption-year sale calc (grown price − mortgage − costs − CGT); consider
  a small engine value object mirroring `HousingProceeds` so the figure is reconcilable and surfaceable.
- **Pension Credit capital** — extend the assessable-capital condition to `homeSold`.
- **App layer** — `ResultPresenter`: replace/soften the ForcedSale input note (it will now be modelled,
  not deferred); optionally surface the forced-sale year on the cashflow ladder + as a chart milestone
  (there is already a `house_sale` milestone kind — reuse it at the redemption year).
- **`MortgageMaturityAction` enum doc** — update the ForcedSale paragraph (no longer "directs to the
  sell variants").

## Reconciliation invariants (assert — CLAUDE.md hard rule)
- **Wealth conserved across the sale:** at the sale year, `property` drops to £0 and liquid rises by
  **exactly** net proceeds (sale − mortgage − costs − CGT); total wealth changes only by the costs +
  CGT paid, nothing else.
- Net proceeds decomposition sums to the total (parts == net), like `HousingProceeds`.
- From the sale year: no `mortgageCosts` / `propertyCosts` / running costs; rent charged; the freed
  equity appears in the Pension Credit capital assessment (benefit erodes / crosses the £16k cliff).
- `Refinance` / `RepayFromCapital` / no-redemption paths are **unchanged** (regression-guarded).

## Tests
- Engine (extend `ContingentCostsTest` or a new `ForcedSaleTest`): a `ForcedSale` home with a future
  `mortgageRedemptionYear` — before the year, property wealth is positive and the mortgage is paid;
  **at** the year, property → £0, liquid jumps by net proceeds, and from then on rent is charged and no
  housing costs are; wealth is conserved across the boundary (± costs/CGT).
- Pension Credit: after a forced sale the freed proceeds count as capital (award drops), mirroring the
  `isLet` test.
- CGT: an ever-let forced-sale home is charged partial-PRR CGT on the grown gain; a lived-in-throughout
  home is £0.
- Update `InputNotesTest` (the ForcedSale note changes) and any test asserting the current
  "directs to sell variants" wording.

## Coordination + acceptance
- **Re-check `git status` + `git log` before starting and before committing** (shared tree, though the
  other lanes are currently closed — confirm). Additive; commit only Lane-B files; **do not push**.
- `PathProjector` is the contention surface — the event block is additive (a new `if` after the
  redemption block), so it rebases cleanly if line numbers move.
- **Acceptance:** a `ForcedSale` base scenario shows the home sold at the redemption year, the equity
  freed and drawn on, rent charged after, Pension Credit eroded by the freed capital — no
  keep-the-home-forever path. Full suite green; DATA-MODEL + DECISIONS + the enum doc updated; this plan
  marked BUILT.

## Out of scope (leave deferred, flag if tempted)
- **Sale-and-rent-back / equity release** (sell but keep living in the *same* home as a tenant) — a
  different product; not this. If Rob's "in place" meant *that*, stop and re-spec (Open question 2 is
  the fork).
- Repossession-specific costs (forced-sale discount, fees) beyond ordinary selling costs — model as a
  higher selling-cost rate if needed, don't hard-code.
- Multi-property forced sales — Lane D territory (`docs/PLAN-multi-property.md`).
