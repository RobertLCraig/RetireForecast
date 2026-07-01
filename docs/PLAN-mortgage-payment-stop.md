# PLAN: stop the bundled mortgage payment after a repay-from-capital redemption

> A ready-to-build spec for one deferred Lane-B correctness fix. A fresh agent should be able to
> execute this end to end. Read `HANDOVER.md` first (orient), then this. Follows the project doc
> standard and the engine rules in `CLAUDE.md` (framework-free engine, integer pence, reconciliation
> invariants, tests green on every commit).

**Stage:** ✅ BUILT (2026-07-01) — kept as the build record. See DECISIONS 2026-07-01
("Mortgage payment stops after a repay-from-capital redemption").
**Owner lane:** B (forced-housing-event workstream). This was the last open Lane-B correctness item;
only the in-place forced-sale model now remains deferred.
_Last updated: 2026-07-01 (built)_

> **Built as specified**, with two integration points the build surfaced beyond the original spec:
> (1) `ExpenseProfile::withoutPropertyCosts()` was widened to strip `mortgageCosts` too (kept the sell
> variants correct); (2) `ResultPresenter::plsaBenchmark()` now excludes `mortgageCosts` as well as
> `propertyCosts` (PLSA's outright-ownership basis). Drifted tests updated: `ContingentCostClassificationTest`
> (mortgage → `while_mortgaged`) and `ScenarioBuilderTest` (the Auto hint). Suite green.

## Goal & the bug it fixes
When a home's mortgage is **redeemed from capital** at maturity (`MortgageMaturityAction::RepayFromCapital`),
the engine repays the outstanding balance as a one-off outflow **but keeps charging the ongoing mortgage
*payment*** entered as an expense line. So the household pays off the £X balance **and** keeps paying the
monthly mortgage for the rest of the plan: a double-count that understates a "repay and stay" plan.

The gap is called out in code as a v1 simplification at
[PathProjector.php:456-457](../packages/finance-engine/src/Forecast/PathProjector.php#L456-L457):
_"the ongoing mortgage payment in the spend is not separately stopped after repayment (it is bundled with the
other property costs)."_ This plan removes that simplification.

**Why it isn't already fixed by the sell-variant logic:** a mortgage payment line auto-classifies to the
`while_owning_home` condition, so it is stripped when the home is **sold** (the buy/rent variants call
`ExpenseProfile::withoutPropertyCosts()`). But a **repay-and-keep** path does not sell — the home is still
owned — so `while_owning_home` keeps the payment on. The mortgage payment needs a **stricter** condition than
"while owning": it should stop when the mortgage ends, whether by redemption **or** sale.

**Scope note (why it still matters for V2 indirectly):** V2 itself uses `ForcedSale`, where the sell variants
already strip the payment, so V2's headline is unaffected. This fixes the **stay-put + repay-from-capital**
case (someone with the savings to clear the mortgage at term and keep the home) which is a valid, currently
mis-modelled option. Keep expectations honest in the commit message.

## Canonical data shape change
One new field on the engine DTO `ExpenseProfile` and one new expense-line **condition** value. Both are
additive; nothing else in the shape changes. Update `DATA-MODEL.md`'s `ExpenseProfile` entry (the contingent-costs
paragraph) in the same commit.

- **New condition value `while_mortgaged`** for an expense line (alongside `always`, `while_owning_home`,
  `while_working`). Charged only while the mortgage is outstanding — stops at redemption **or** sale.
- **`ExpenseProfile::mortgageCosts` (`?Money`)** — the sum of `while_mortgaged` lines, a **marked subset of
  essential** exactly like `propertyCosts` (not a second total; it is already inside
  `essentialAnnualSpend`). Null = none.

The distinction from `propertyCosts`: service charge / ground rent / factor fee stay `while_owning_home`
(they continue while you own the flat, redemption or not); only the **mortgage** moves to `while_mortgaged`.

## Design (mirror the existing contingent-cost machinery)
The pattern already exists for `employmentCosts` (charged only while someone works, dropped by the projector
in retirement) and `propertyCosts` (stripped by the sell variants). Add `mortgageCosts` as a third marked
subset with its own stop condition.

## Touch-points (exact, current anchors)

### Engine (framework-free — no `App\`/`Illuminate\`)
1. **`ExpenseProfile`** ([src/Dto/ExpenseProfile.php](../packages/finance-engine/src/Dto/ExpenseProfile.php))
   - Add constructor param `?Money $mortgageCosts = null` (after `employmentCosts`, keep it trailing/defaulted
     so named-arg construction elsewhere is unaffected).
   - Add accessor `mortgageCosts(): Money` returning `$this->mortgageCosts ?? Money::zero()`.
   - **Extend `withoutPropertyCosts()`** so the sell variants remove **both** property **and** mortgage costs
     (a sold home has neither a service charge nor a mortgage). It currently only subtracts `propertyCosts`
     and passes `employmentCosts` through — make it also subtract `mortgageCosts` from the essential floor and
     set `mortgageCosts: null`. Update its doc + the class doc-comment (the contingent-costs paragraph) to name
     the third subset. **This is load-bearing:** without it, moving the mortgage out of `propertyCosts` would
     regress the sell variants (they'd keep paying a sold home's mortgage). Consider renaming the method to
     `withoutHomeOwnershipCosts()` for accuracy, but only if you update its one caller
     (`HousingComparison::withHousing`) and any test that names it — otherwise keep the name and just widen it.

2. **`PathProjector::projectYear`**
   ([src/Forecast/PathProjector.php](../packages/finance-engine/src/Forecast/PathProjector.php))
   - The redemption block at
     [L458-468](../packages/finance-engine/src/Forecast/PathProjector.php#L458-L468) already sets
     `$state['mortgageRepaid'] = true` in the redemption year.
   - **After** that block and **before** `$spendNominal` is computed at
     [L470](../packages/finance-engine/src/Forecast/PathProjector.php#L470), drop the mortgage payment once
     the mortgage is gone — **mirror the employment-cost drop** at
     [L445-449](../packages/finance-engine/src/Forecast/PathProjector.php#L445-L449):
     ```php
     // Once the mortgage is redeemed its ongoing payment stops (unlike service charge / ground
     // rent, which continue while the home is owned). Drop the while_mortgaged spend from the
     // redemption year on. (Sold-home variants already removed it via withoutPropertyCosts.)
     if ($state['mortgageRepaid']) {
         $mortgagePay = $household->expenseProfile->mortgageCosts()->pence;
         $targetPence = max(0, $targetPence - $mortgagePay);
         $essentialPence = max(0, $essentialPence - $mortgagePay);
     }
     ```
   - Update the v1-simplification comment at
     [L456-457](../packages/finance-engine/src/Forecast/PathProjector.php#L456-L457) — it is now handled, not
     deferred.
   - `Refinance` leaves `mortgageRepaid` false, so the payment correctly continues; a stay-put home with no
     redemption year also keeps `mortgageRepaid` false, so nothing changes there.

3. **`HousingComparison::withHousing`**
   ([src/Housing/HousingComparison.php](../packages/finance-engine/src/Housing/HousingComparison.php)) — no
   change needed **if** you widen `withoutPropertyCosts()` in place (it already calls it). If you rename the
   method, update the call here. Verify the sell variants still drop the mortgage (the existing
   `ContingentCostsTest` asserts sell-variant property costs are £0 — see Tests).

### App layer
4. **`HouseholdAssembler`** ([app/Forecast/HouseholdAssembler.php](../app/Forecast/HouseholdAssembler.php))
   - `autoCondition()`
     ([L241-256](../app/Forecast/HouseholdAssembler.php#L241-L256)): move the **`mortgage`** keyword out of the
     `while_owning_home` group into a new `while_mortgaged` classification. Keep `service charge`, `ground
     rent`, `factor fee` on `while_owning_home`.
   - `lineCondition()` allowed-list
     ([L226](../app/Forecast/HouseholdAssembler.php#L226)): add `'while_mortgaged'` to the `in_array(...)`.
   - `expenseProfile()`
     ([L186-212](../app/Forecast/HouseholdAssembler.php#L186-L212)): sum `while_mortgaged` lines into
     `mortgageCosts` (alongside the existing `propertyCosts` / `employmentCosts` sums) and pass it to the
     `ExpenseProfile` constructor.

5. **`ScenarioBuilder` validation** ([app/Livewire/ScenarioBuilder.php](../app/Livewire/ScenarioBuilder.php))
   - `expenseLines.*.condition` rule at
     [L258](../app/Livewire/ScenarioBuilder.php#L258): add `'while_mortgaged'` to `Rule::in([...])`.

6. **Builder blade** ([resources/views/livewire/scenario-builder.blade.php](../resources/views/livewire/scenario-builder.blade.php))
   - The condition `<select>` at
     [L859-864](../resources/views/livewire/scenario-builder.blade.php#L859-L864): add
     `<option value="while_mortgaged">Only while the mortgage runs</option>`. The existing "Auto" hint
     (`$conditionHints`) reads `HouseholdAssembler::autoCondition()`, so a "Mortgage" line will now hint
     "while the mortgage runs" automatically — check the hint text maps the new value to readable copy
     (search the component for where `conditionHints` is built / labelled and add the label).

## Reconciliation invariants (assert in tests — CLAUDE.md hard rule)
- `mortgageCosts` is a **subset** of `essentialAnnualSpend`, never an addition: with a `while_mortgaged` line
  of £M, `targetAnnualSpend()` is unchanged vs. classifying it `always`; only the **stop** differs.
- Stay-put + `RepayFromCapital`: the year-on-year essential/target spend **falls by exactly £M** (real terms)
  from the redemption year, and by nothing else that year beyond the existing behaviour.
- Sell variants: `mortgageCosts` is £0 after `withoutPropertyCosts()` (the sold home pays no mortgage), so
  the sell-variant target is `stay − propertyCosts − mortgageCosts`.
- `Refinance` (or no redemption year): the mortgage payment is charged **every** year (no drop).

## Tests to add / update
- **New engine test** (extend `packages/finance-engine/tests/Forecast/ContingentCostsTest.php`, or a new
  `MortgagePaymentStopTest.php`):
  - A stay-put household with a `while_mortgaged` cost + `mortgageRedemptionYear` + `RepayFromCapital`:
    essential spend includes the payment **before** the redemption year and **excludes** it from the redemption
    year on (assert the drop equals the payment, allowing pence of deflation rounding — mirror
    `test_employment_costs_stop_when_the_earner_retires`).
  - `Refinance`: the payment persists past the maturity year.
  - `withoutPropertyCosts()` removes `mortgageCosts` (sell variant pays no mortgage).
- **Update `tests/Unit/Forecast/ContingentCostClassificationTest.php`** (and any `HouseholdAssemblerTest`
  case) that asserts a **"mortgage"** label classifies to `while_owning_home` — it now classifies to
  `while_mortgaged`. This is legitimate drift from a deliberate change; update the expectation, do not weaken it.
- Run `php artisan test` — must be green (currently ~550; this adds a few). Watch the
  `ContingentCostsTest` reconciliation cases and any provenance/ladder test.

## Coordination (READ — shared tree)
`PathProjector`, `HouseholdAssembler` and the builder are the **contention surface**: Lane A (care-cost) and
Lane C (PCLS timing / drawdown optimiser) both target `PathProjector`, and Lane A also touches
`HouseholdAssembler` + the builder. Per `HANDOVER.md` "Multi-agent coordination" and `[[concurrent-session-split]]`:
- **Re-check `git status` + `git log` immediately before you start and again before you commit.** If HEAD moved,
  re-read the exact `PathProjector::projectYear` spend region (line numbers here will have shifted) and rebase
  your edit onto it — the change is additive (one new `if` block after the redemption block), so it slots in
  regardless of what moved.
- Keep it additive; **commit only this lane's files** (no blanket `git add -A`).
- **Do not push** — pushing to `master` is gated on Rob's explicit go-ahead.
- Commit green, in one focused commit (engine + app + tests + the `DATA-MODEL.md` note together).

## Acceptance criteria
- A stay-put scenario with a mortgage payment line + `mortgageRedemptionYear` + `RepayFromCapital` stops
  charging the payment from the redemption year, and the cashflow ladder shows the essential spend fall that
  year (no more double-count of repay + ongoing payment).
- Sell variants and `Refinance`/no-redemption scenarios are **unchanged** (regression-guarded by the existing
  `ContingentCostsTest`).
- `DATA-MODEL.md` documents `mortgageCosts` + the `while_mortgaged` condition; `DECISIONS.md` 2026-07-01
  ("deferred-refinement resolutions") notes this item is now built (append a short line, do not rewrite the
  entry). Full suite green.

## Out of scope (leave deferred, flag if tempted)
- The **in-place forced-sale** model (sell but stay/rent in place) — the other open Lane-B refinement.
- A **partial-year** mortgage payment in the redemption year (annual granularity is the existing convention;
  the payment stops for the whole redemption year, matching how the redemption one-off is modelled).
- Refinance **rate/term** modelling (the payment simply continues at the entered figure).
