# PLAN (DRAFT) — multiple properties (buy-to-let / second homes / inherited-and-let)

> **Status: DRAFT proposal, not a decision.** To be reviewed and refined by Rob, then
> folded into docs/PLAN.md (backlog) + a DECISIONS.md entry once the open questions are
> settled. Nothing here is built. Dated 2026-06-30.

## Why / motivating case

The tool models exactly one property: the **main residence** the buy-vs-rent comparison
sells or swaps. A household that owns **additional** property — a buy-to-let, a second home,
or (the case that surfaced this) a property **inherited and then let out** — cannot represent
that property as an asset. Today the only lever is a free-floating `rental` income stream,
which captures a rent figure but **not** the capital value, its growth, the mortgage, the
running/letting costs, the eventual sale (proceeds + CGT), or its place in the IHT estate.

Goal: let a household hold **an arbitrary number** of properties (not a cap of two), each a
first-class asset that grows, may produce rent, may carry a mortgage and costs, can be sold
during the forecast (releasing cash and triggering CGT), and counts in net worth and the
estate — without disturbing the main-residence buy-vs-rent machinery.

## What's special about the main residence (why we keep it separate)

`primaryResidence` is not just "property #1". It has behaviours an investment property does
*not*:

- it is the thing `HousingComparison` sells / swaps in stay-put / buy / rent;
- it gets **full Private Residence Relief** (no CGT) when lived in throughout;
- it qualifies for the **residence nil-rate band** in IHT (when passing to descendants);
- its running costs are **essential spend** (the renter's-rent counterpart);
- while occupied it is **exempt** from means-tested-benefit capital.

An additional property has the mirror-image profile: rent *income*, full CGT (little/no PRR),
**no** RNRB, costs that are a business expense not household essential spend, and it **is**
assessable capital. So the recommendation is **keep `primaryResidence` as-is and add a
separate list of additional properties**, rather than unifying everything into one list with a
"primary" flag (considered, see Open questions).

## Current single-property touch-points (the map to change)

| Concern | Where it lives today | Assumes one home |
|---|---|---|
| DTO | `Dto/Household::primaryResidence` (one `?Property`) | yes |
| Property shape | `Dto/Property` (has `isPrimaryResidence`, `everLet`, `cgtHistory`, `ownershipShare`) | partly ready |
| Projection | `Forecast/PathProjector` — `state['property']` is **one int**, grown by `houseGrowthReal`; `propertyWealth`/`totalWealth`; running costs → essential spend | yes |
| Rental income | `Dto/IncomeStream` type `rental` — free-floating, not linked to any asset | n/a |
| Sale + CGT | `Housing/HousingComparison::saleProceeds` (one home) + `Property/CgtPrivateResidenceCalculator` + `Dto/CgtHistory` | yes |
| Buy-vs-rent variants | `Housing/HousingComparison::variantInputs` / `withHousing` | yes |
| IHT estate | `Iht/InheritanceTaxCalculator` (estate value + RNRB on the home to descendants) | yes |
| Benefits / care capital | `Benefits/…`, `Care/…` means-test (occupied home exempt) | yes |
| Form-state | `Livewire/ScenarioBuilder` (`property` map + `hasProperty` bool; `incomeStreams[]`) | yes |
| Assembly | `Forecast/HouseholdAssembler::household()` (`property()`, `cgtHistoryFrom()`) | yes |
| Results | `Forecast/ResultPresenter` sale waterfall, wealth breakdown, income-by-source | yes |
| Storage | `builder_state` (single `property` key); delta-child what-ifs already support add/remove rows | ready for a list |

## Target data shape

Add to `Household`:

```php
/** @param list<Property> $additionalProperties */
public readonly array $additionalProperties = [],
```

Each entry is a `Property` with `isPrimaryResidence: false`. Reuse the existing `Property`
DTO (it already carries value, ownership, mortgage, running costs, growth override,
ownership share, `everLet`, `cgtHistory`). Add the fields a let property needs that a
residence does not — **two candidate shapes, pick one in review:**

- **Option A — extend `Property`** with the let-only fields:
  `?Money $grossAnnualRent`, `?Money $lettingCosts` (or fold into `runningCosts`),
  `?int $plannedDisposalYear`, `?AcquisitionType $acquisition` (purchased | inherited | gifted).
  Pro: one DTO, reuses CGT/value/mortgage; the `isPrimaryResidence` flag already discriminates.
  Con: a residence-role `Property` carries nonsense rent fields and vice-versa.
- **Option B — a dedicated `InvestmentProperty` DTO** that *composes* a value/growth/mortgage
  core + rent + disposal + `CgtHistory`. Pro: explicit, no nonsense fields. Con: duplicates
  the value/growth/mortgage/CGT plumbing.

Recommendation: **Option A** — the existing `Property` already anticipated non-residence use
(`isPrimaryResidence`, `everLet`, `cgtHistory` exist and are otherwise idle), and CGT/value/
mortgage are identical concerns. Keep the rent/disposal fields nullable so a residence simply
leaves them null.

## Behaviours each additional property participates in

1. **Capital growth** — grow each property's value yearly by `houseGrowthReal` (or its own
   `growthAssumptionOverride`). `PathProjector` state moves from one `property` int to a
   **list** of property values; `propertyWealth` = primaryResidence + Σ additional.
2. **Rental income** — net rent (gross − letting costs) is **taxable property income** for the
   owner, fed through the existing per-person income-tax pass. Inflation-linked optionally.
   See the **one-home-for-rent** concern below — rent must have a single source.
3. **Running / letting costs** — a **business expense against the rent**, *not* household
   essential spend (unlike the residence's running costs). So they reduce taxable rental
   profit, they do not raise the household's spend floor.
4. **Mortgage** — v1: a static outstanding balance reducing net equity, interest as an annual
   cost. Note the **Section 24** rule: BTL mortgage interest is not deductible from profit but
   earns a 20% basic-rate tax credit — flag as a v1 simplification (treat net rent as taxable,
   document the gap). Repayment-mortgage amortization is later.
5. **Disposal (optional planned sale)** — at `plannedDisposalYear`, convert the property's
   then-current value to liquid wealth: `value − outstanding mortgage − selling costs − CGT`,
   remove it from property wealth, add net proceeds to a GIA/cash account. This is a
   **per-property generalisation of the existing `saleProceeds`** logic.
6. **CGT on disposal** — reuse `CgtPrivateResidenceCalculator` + `CgtHistory`. A never-lived-in
   BTL has `mainResidenceMonths = 0` → no PRR, no final-9-month exemption → whole gain taxed,
   per-owner £3,000 allowance and rate. **Inherited base cost = probate (market) value at the
   date of death; acquisition year = the inheritance year** — this is exactly the user's case
   and falls out of the existing `CgtHistory(purchasePrice, acquisitionYear, mainResidenceMonths=0)`.
7. **IHT** — additional properties add to `estateExcludingPensions` but get **no** residence
   nil-rate band (only the main home passing to descendants does). Ensure they are summed into
   the estate value and **excluded** from `homePassingToDescendants`.
8. **Means-tested benefits / care** — a second property **is** assessable capital (the
   occupied home is not). Wire into the benefits/care capital figure. (Likely Phase 2.)
9. **Liquidity / drawdown** — decision: does an unsold BTL count as **usable** wealth (it's
   sellable) or only `totalWealth` until actually disposed? Default proposal: in `totalWealth`
   but **not** `usableWealth` until sold (mirrors the home), with a possible "available to fund
   retirement" flag later that lets the drawdown strategy sell it when liquid wealth runs low.

## The one-home-for-rent reconciliation concern (hard rule)

Per the data-integrity rule (one definition, one home; no double-count; no silent drop —
Rob's past burn), **rent must have exactly one source.** Two ways to enter a let property's
income would otherwise double-count:

- rent entered **on the property** (the new, asset-linked way), vs
- the existing standalone **`IncomeStream` of type `rental`** (a bare figure, no asset).

Proposal: property-linked rent is derived **from the property**; the standalone rental
`IncomeStream` remains only for "I want a rent figure without modelling the asset." The UI
must make these mutually exclusive in intent, and a **reconciliation/completeness test** must
assert (a) a let property's net rent reaches the income-tax pass exactly once, and (b) no path
counts both an asset-linked rent and a standalone rental stream for the same property.

## Buy-vs-rent interaction

Additional properties are **held constant** across the stay-put / buy / rent variants of the
*main-residence* decision — selling your home doesn't touch your BTLs. So
`HousingComparison::withHousing()` must **carry `additionalProperties` through unchanged**.
Low effort; mostly a matter of not dropping the field when rebuilding the variant household.

## Storage + what-if synergy

- `builder_state` gains a `properties` (additional) array — each a map mirroring the residence
  property map + rent / disposal fields — persisted in the single encrypted `builder_state`.
- **Free synergy:** delta-child what-ifs already represent **added rows (stored whole at the id
  path) and removed rows (a `REMOVED` sentinel)** via `BuilderStateDelta` (2026-06-30 work). So
  "what if we sell the BTL" / "what if we buy another" works as an ordinary add/remove delta —
  no new storage machinery. Call this out as a reason the feature is cheaper now than it looks.

## UI (builder)

- A repeatable **"Other properties (buy-to-let / second homes)"** section: **+ Add property**,
  each row with value, mortgage, ownership share, growth override, **gross annual rent**,
  letting costs, joint-ownership + higher-rate toggles, the existing **CGT-on-sale wizard**
  (purchase/probate value, year acquired, lived-in-vs-let timeline — defaulting to *all let*
  for a pure BTL), and an optional **"plan to sell in year ___"** control.
- Reuse the existing CGT wizard partial per property.
- **Inherited affordance** (directly answers the question that started this): an
  `acquisition: purchased | inherited | gifted` selector that **relabels** the cost field —
  "Probate / market value when inherited" + "Year inherited" for inherited; "Market value at
  gift" for gifted — so the user enters the correct CGT base cost without having to know the
  rule. Purely a labelling/help nicety over the same `CgtHistory.purchasePrice/acquisitionYear`.

## Results / presentation

- Wealth breakdown lists each property; income-by-source shows rental income.
- A **per-property sale waterfall** when disposed (reuse `saleExplainer`).
- IHT panel reflects the larger estate (and the absent RNRB on lets).

## Tax nuances to get right (and flag where simplified)

- **CGT base cost on inherited / gifted property** = market value at death / gift, not the
  deceased's original cost. (Already supported by `CgtHistory`; surface via the UI labels.)
- **No PRR / no final-9-month exemption** on a never-occupied let. (Already correct in the
  calculator when `mainResidenceMonths = 0`.)
- **Section 24** mortgage-interest restriction (20% tax credit, not a deduction) — v1 likely
  treats net rent as taxable; flag the gap.
- **£1,000 property allowance**; rental losses carried forward — out of v1 scope, flag.
- **SDLT additional-property surcharge** — only relevant if we model *acquiring* a property
  mid-forecast (the engine's `SdltCalculator` currently skips the surcharge); the held-property
  case doesn't need it. Defer acquisition to a later phase and source the current surcharge
  rate then.

## Tests (the project's reconciliation / completeness bar)

- **Per-source completeness:** a held property's value reaches `totalWealth` and the IHT
  estate; its **net rent** reaches the income-tax pass; its **disposal proceeds** reach liquid
  wealth; **CGT on disposal** is charged. Each demonstrably contributes (the DLA-drop lesson).
- **Reconciliation:** Σ per-property values == reported `propertyWealth`; disposal proceeds
  reconcile (value − mortgage − costs − CGT == cash added); **rent counted exactly once** (no
  asset-linked + standalone double-count).
- **Golden fixture:** a realistic multi-property household (e.g. residence + one inherited-let +
  one BTL with a mortgage), not just a synthetic happy path.
- **Engine isolation** stays intact (no `App\…` / `Illuminate\…` in the engine).

## Phasing (so it ships in trustworthy slices)

- **Phase 1 (MVP — covers the inherited-let case fully):** hold N additional properties as
  growing capital assets with net rental income, running/letting costs, IHT estate inclusion,
  and **CGT on an optional planned disposal**. No change to main-home buy-vs-rent (just carry
  the list through). Disposal optional.
- **Phase 2:** liquidity/drawdown integration (sell a BTL to fund retirement when cash runs
  low), repayment-mortgage amortization, means-tested capital + care, Section 24 precision.
- **Phase 3:** acquiring property mid-forecast (SDLT surcharge, the buy decision), property
  allowance / loss carry-forward, refinancing.

## Open questions for Rob

1. **Option A (extend `Property`) vs Option B (new `InvestmentProperty` DTO)?** Recommend A.
2. **Keep `primaryResidence` separate + add a list** (recommended), or unify into one
   `properties` list with a primary flag (more invasive, touches every consumer)?
3. **Usable vs total wealth:** does an unsold BTL count toward "spendable" wealth, or only once
   disposed? (Affects the does-the-money-last verdict.)
4. **Rent's single home:** retire the standalone `rental` IncomeStream in favour of
   asset-linked rent, or keep both with a guard? (Reconciliation rule pushes toward one.)
5. **How far into v1** do we go — is Phase 1 (hold + rent + IHT + disposal CGT) the right line
   to stop at for personal use, deferring drawdown-funded sales and Section 24?
