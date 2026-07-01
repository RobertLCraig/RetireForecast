# PLAN: model a forced sale in place (sell at the redemption year, then rent)

> A spec for the last remaining Lane-B deferred item. A fresh agent should be able to build it end
> to end, BUT it carries real modelling decisions (see "Open questions" — get Rob's answers first).
> Read `HANDOVER.md` (orient) then this. Follows the doc standard + `CLAUDE.md` engine rules
> (framework-free engine, integer pence, reconciliation invariants, tests green on every commit).

**Stage:** design-spec — **decisions resolved by Rob 2026-07-01, ready to build** (not yet built).
**Owner lane:** B (forced-housing-event workstream). The last open Lane-B item after the
mortgage-payment-stop shipped ([docs/PLAN-mortgage-payment-stop.md](PLAN-mortgage-payment-stop.md)).
The real couple this models is captured in `docs/SCENARIO-V2.local.md` (gitignored, private).
_Last updated: 2026-07-01 (Rob's decisions folded in)_

> **Framing (Rob's decision 2):** the **base scenario is "stay put"** — the couple **find ~£100k to pay
> down the capital and stay** (that is `RepayFromCapital`, already built). The forced sale is therefore a
> **what-if**, not base behaviour: a child scenario with `mortgageMaturityAction = ForcedSale`. The
> projector event below fires for that what-if; the base is unchanged. So this is additive — it makes the
> `ForcedSale` *what-if* realistic, and the "weigh the alternatives on Compare" note on the base stays.

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

## Decisions (resolved by Rob, 2026-07-01)
1. **Rent after a forced sale is a USER INPUT, and its variations are separate what-ifs.** Use the
   scenario's entered `HousingAction::annualRent` (+ `rentInflationReal`); do **not** invent a default —
   rent is highly variable and the user drives it. The point of running several (e.g. £950/mo vs
   £1,700/mo, or rent +4%/yr) is exactly what the **what-if** machinery is for, so no special handling is
   needed beyond starting the entered rent at the sale year. If no rent is entered on a `ForcedSale`
   what-if, treat it as £0 and **surface an input-sanity note** ("a forced sale needs a rent to be
   meaningful") rather than guessing.
2. **This is a WHAT-IF, not base behaviour.** The base is "stay put — find £100k, pay down, stay"
   (`RepayFromCapital`). So the forced-sale event fires only for a **child scenario** with
   `mortgageMaturityAction = ForcedSale`; the base projection is untouched and its Compare note stays.
   (See the Framing note at the top.)
3. **CGT is computed on the grown, sale-year value** — reuse `CgtPrivateResidenceCalculator` on the
   sale-year gain with the `CgtHistory` occupation split (Rob: "yes, unless you have a better
   suggestion" — no better one; this matches how a real disposal is taxed). £0 for a lived-in-throughout
   home; partial-PRR for an ever-let one (the V2 flat).
4. **The freed proceeds are investable liquid wealth — modelling where they go and how they grow/shrink
   IS the point of the tool.** Put the net proceeds into an **investable account** (default GIA, invested
   per the run's assumptions, drawn per the drawdown strategy), so the forecast then shows how that money
   is stored, invested, grows and is drawn down. Let the user steer the wrapper (ISA/GIA/cash) through
   the scenario's accounts where possible; GIA is the sensible default (taxable dividends + CGT on
   disposal, like any unwrapped investment). Renter's council tax / bills already sit in the spend lines,
   so add **rent only**, not a second running-costs bucket.

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
  different product; not this. **Confirmed with Rob 2026-07-01:** "in place" means modelled *within the
  projection timeline* (sell at the redemption year, then rent a new home), not staying in the same home.
- Repossession-specific costs (forced-sale discount, fees) beyond ordinary selling costs — model as a
  higher selling-cost rate if needed, don't hard-code.
- Multi-property forced sales — Lane D territory (`docs/PLAN-multi-property.md`).
