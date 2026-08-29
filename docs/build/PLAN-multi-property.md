# PLAN — multiple properties (buy-to-let / second homes / inherited-and-let)

> A ready-to-build spec. A fresh agent should be able to execute Phase 1 end to end. Read
> `docs/HANDOVER.md` first (orient), then this. Follows the project doc standard and the engine
> rules in `CLAUDE.md` (framework-free engine, integer pence, reconciliation invariants,
> completeness tests, no invisible figures, tests green on every commit).

**Stage:** **SETTLED, NOT STARTED (2026-08-29).** Drafted 2026-06-30 as Lane D; left DRAFT under
card 0019, which is now the backlog entry for it. The five open questions are answered below, each
from what the repository already decides. No code has been written against it.
_Last updated: 2026-08-29 (scope settled; the spec below is refreshed against the engine as it now
is, which has moved a long way since the draft — see "What changed since the draft")._

> **Two gates before anyone starts building.** They are not paperwork, they are the reason this is
> cheap later and expensive now.
>
> 1. **Cards 0029 and 0030 land first** (`needs:` on card 0019). 0030 builds the letting-cost model
>    — management, void, maintenance, the service charge as a deductible letting expense — and 0029
>    changes what a per-property growth override *means* in the Monte Carlo. Phase 1 needs both.
>    Building this first means writing the letting-cost model a second time and then reconciling
>    two copies of it, which is the exact failure the one-definition-one-home rule exists to stop.
> 2. **Somebody confirms there is a second property to model.** The draft's motivating case is "a
>    property inherited and then let out". Nothing in the repository records one: the household this
>    tool was built for owns a single flat, which they live in, on a buy-to-let mortgage
>    (DECISIONS 2026-06-30). That is not a design question and no amount of reading settles it, so
>    it is on card 0019 for Rob. If the answer is no, discard the card and keep this file as the
>    record of why the design landed where it did.

## Why / motivating case

The tool models exactly one property: the **main residence** the buy-vs-rent comparison sells or
swaps. A household that owns **additional** property — a buy-to-let, a second home, or a property
inherited and then let out — cannot represent it as an asset. The only lever today is a free-floating
`rental` income stream, which captures a rent figure but **not** the capital value, its growth, the
mortgage, the running/letting costs, the eventual sale (proceeds + CGT), or its place in the IHT
estate.

Goal: let a household hold **an arbitrary number** of properties (not a cap of two), each a
first-class asset that grows, may produce rent, may carry a mortgage and costs, can be sold during
the forecast (releasing cash and triggering CGT), and counts in net worth and the estate — without
disturbing the main-residence buy-vs-rent machinery.

## What's special about the main residence (why we keep it separate)

`primaryResidence` is not just "property #1". It has behaviours an investment property does *not*:

- it is the thing `HousingComparison` sells / swaps in stay-put / buy / rent;
- it gets **full Private Residence Relief** (no CGT) when lived in throughout;
- it qualifies for the **residence nil-rate band** in IHT (when passing to descendants);
- its running costs are **essential spend** (the renter's-rent counterpart);
- while occupied it is **exempt** from means-tested-benefit capital.

An additional property has the mirror-image profile: rent *income*, full CGT (little/no PRR), **no**
RNRB, costs that are a letting expense not household essential spend, and it **is** assessable
capital.

## What changed since the draft (read this before trusting anything below)

Four things the 2026-06-30 draft said are no longer true, and each one makes this feature smaller:

| The draft said | What is true now |
|---|---|
| "Section 24 — v1 likely treats net rent as taxable; flag the gap" | The projector **already applies the buy-to-let finance-cost reducer** (DECISIONS 2026-07-09, `PathProjector` ~L955-980). Card 0030 confirms it is correct. Nothing to build; an additional property inherits it. |
| "Mortgage — v1: a static outstanding balance … repayment amortization is later" | `Property` now carries **three** mortgage shapes: static, rolled-up lifetime (`mortgageRollUpRate` + `mortgageOverpaymentAnnual`) and **amortising** (`RepaymentMortgageTerms`), with a constructor invariant that they are exclusive. Option A inherits all three free. |
| Rent has no asset link at all | `Property::isLet` exists (DECISIONS 2026-07-02) and already drives the means-test treatment of a let home and the s24 reducer. It is the right discriminator for "this property produces rent". |
| Letting costs are unmodelled and unpriced | **Card 0030** is building them (management, void, maintenance, service charge reclassified as a letting expense), and **cards 0027, 0028, 0032** are fixing sale friction, lumpy holding costs and leasehold selling costs on the residence. Additional properties **reuse** all of it. |

## The five open questions, answered

Each answer is settled from the repository or from a rule the project has already written down, per
`docs/board/README.md` ("a decision belongs to a person only when the answer turns on something no
amount of reading can settle"). What was left for Rob is the second gate above, and only that.

### 1. Extend `Property` (Option A), or a new `InvestmentProperty` DTO (Option B)? → **A**

`Property` already carries every field an investment property needs: value, ownership,
`ownershipShare`, three mortgage shapes, `runningCosts`, `growthAssumptionOverride`,
`isPrimaryResidence`, `everLet`, `isLet`, `cgtHistory`, `mortgageRedemptionYear` +
`MortgageMaturityAction`. A second DTO would duplicate the mortgage triple **and** its exclusivity
invariant, which is the largest and most safety-critical block in the file — two copies of an
invariant is one copy that will drift.

The draft's objection to A was that a residence would carry nonsense rent fields. That is fixed by
making **`isLet` the discriminator**, not `isPrimaryResidence`: rent fields require `isLet`, which
is already true of the let-to-let residence. Enforce it in the constructor beside the existing
mortgage invariant.

Added to `Property`, all nullable so every existing scenario is byte-identical:

```php
?Money   $grossAnnualRent        // requires isLet
?int     $plannedDisposalYear    // calendar year; null = held to death
AcquisitionType $acquisition = AcquisitionType::Purchased   // purchased | inherited | gifted
```

`lettingCosts` is deliberately **absent**: card 0030 owns that model, and this feature reads it
rather than declaring a second one.

### 2. Keep `primaryResidence` separate and add a list, or unify into one list? → **Keep separate**

`primaryResidence` has 43 references across 11 files, 16 of them inside `PathProjector`, and the
projector's property state is **scalar throughout**: `state['property']`, `state['mortgageOutstanding']`,
`state['repaymentSchedule']`, `state['propertyGrowthReal']`, `state['ownershipShare']`,
`state['mortgageRollUpRate']`, `state['propertyWhole']`. Unifying rewrites the hot loop's state for
no behaviour gain, and turns the residence's five special behaviours into per-row flags that every
consumer must then re-test. Add:

```php
/** @param list<Property> $additionalProperties */
public readonly array $additionalProperties = [],
```

Each entry has `isPrimaryResidence: false`.

### 3. Does an unsold second property count as usable (spendable) wealth? → **No, only total, until sold**

Two independent reasons, and they agree:

- It is **exactly how the main residence is already treated** — `SimulationResult::$usableWealthPercentiles`
  is documented as "the spendable part (excl. the home)". No new rule, no new explanation to a reader.
- Rob's standing modelling rule: where several figures are plausible, take the **most adverse** and
  expose it as an editable control. Counting an unsold flat as spendable is the optimistic side, and
  it would flatter the does-the-money-last verdict, which is a headline output.

A per-property "sell this to fund retirement if cash runs low" flag is **Phase 2** and must be an
explicit user choice, never a default.

### 4. Retire the standalone `rental` IncomeStream, or keep both? → **Keep both, and audit the overlap**

Retiring it would break a live scenario. `PathProjector::rentalIncomeNominal()` sums
`IncomeStreamType::Rental` streams, and that sum is the base of the s24 finance-cost reducer for the
let-to-let strategy (card 0021's scenario 43). So:

- **`Property::grossAnnualRent` is null** → the standalone stream is the source. Today's behaviour,
  unchanged, no migration.
- **`grossAnnualRent` is set** → the property is the source for that property.

A household may legitimately have both — one modelled let property and one bare rent figure for an
asset it does not want to model — so the engine must **not** throw. It must make the overlap
**visible**, which is the project's standing answer to "a mismatch must be a visible failure, not a
silent one":

- an **input-sanity note** at entry when a scenario has property-linked rent and a standalone
  `rental` stream, showing both figures and asking the user to confirm one is not the other;
- a new **`php artisan scenarios:audit` check** reporting the same across every stored scenario.

### 5. How far into v1? → **Phase 1 only, and behind cards 0029 and 0030**

Phase 1 as defined below and nothing else. Phases 2 and 3 each depend on machinery that is either
being rebuilt right now (letting costs, sale friction, growth-override semantics) or not started
(mid-forecast acquisition, SDLT surcharge). The project ships in trustworthy slices, and the head of
`docs/board/todo/` is a reviewed defect backlog whose first four cards change which plan the
comparison ranks first — a new feature does not go in front of those.

## Single-property touch-points (the map to change)

| Concern | Where it lives today | Phase 1 work |
|---|---|---|
| DTO | `Dto/Household::primaryResidence` | add `additionalProperties` |
| Property shape | `Dto/Property` | add the three fields of Q1 + the `isLet` invariant |
| **DTO rebuild** | `Household::copy()` (the ONE rebuild site) **and** `HousingComparison::withHousing()` (a second one, unguarded) | both must carry the list; `withHousing` has no reflection guard, so this is where a field goes missing |
| Wither guards | `HouseholdWitherTest`, `AssetWitherTest` (enumerate DTO properties by reflection) | they will fail until the new fields are carried through — that is them working |
| Projection | `Forecast/PathProjector` — scalar property state, `propertyWealth`, growth in `growState` | add a parallel per-property list; do **not** unify with the residence's scalars |
| Rental income | `PathProjector::rentalIncomeNominal()` sums `IncomeStreamType::Rental` | add property-linked rent as a second source, per Q4 |
| Letting costs | **card 0030** | read it; do not redeclare |
| Sale + CGT | `Housing/HousingProceeds` + `Property/CgtPrivateResidenceCalculator` + `Dto/CgtHistory` | reuse `HousingProceeds::compute()` per property |
| Buy-vs-rent variants | `Housing/HousingComparison::variantInputs` / `withHousing` | carry the list through **unchanged** — selling your home does not touch your BTLs |
| IHT estate | `Iht/EstateValuer::value()` (liquid + home equity) | add Σ additional equity to `estateExcludingPensions`, **excluded** from `homePassingToDescendants` (no RNRB) |
| Benefits / care capital | `PathProjector` ~L1390 (a let home's equity is assessable) | Phase 2 |
| Form-state | `Livewire/ScenarioBuilder` (`property` map, `hasProperty` bool) | add a repeatable `properties` list |
| Assembly | `Forecast/HouseholdAssembler::property()` (hardcodes `isPrimaryResidence: true`) | a second mapper for the list |
| Results | `Forecast/ResultPresenter` wealth breakdown, income-by-source, sale waterfall | per-property rows |
| Disclosure | `ResultPresenter::assumedFigures()` | any new engine-side default must appear here, reading the constant that owns it |
| Storage | `builder_state` single encrypted array; delta-children already do add/remove rows | a new `properties` key — see the fixture gotcha below |

## Behaviours in Phase 1

1. **Capital growth** — each property grows yearly by `houseGrowthReal` or its own
   `growthAssumptionOverride`, with the override treated as the **mean, not a replacement for the
   draw** (card 0029's rule — inherit it, do not re-derive it). `propertyWealth` = residence + Σ
   additional.
2. **Rental income** — net rent is taxable property income for the owner, through the existing
   per-person income-tax pass, with letting costs deducted per card 0030. Single source per Q4.
3. **Running / letting costs** — a **letting expense against the rent**, *not* household essential
   spend. They reduce taxable rental profit; they do not raise the spend floor.
4. **Mortgage** — all three existing shapes, inherited from `Property`. The s24 finance-cost reducer
   already applies.
5. **Disposal (optional)** — at `plannedDisposalYear`, run `HousingProceeds::compute()` for that
   property, remove it from property wealth, add net proceeds to a GIA account. Sale friction and
   leasehold selling costs come from cards 0027 and 0032, not from a second implementation here.
6. **CGT on disposal** — `CgtPrivateResidenceCalculator` + `CgtHistory`. A never-lived-in BTL has
   `mainResidenceMonths = 0` → no PRR, no final-nine-month exemption, whole gain taxed, per-owner
   annual exempt amount and rate. **Inherited base cost = probate (market) value at the date of
   death, acquisition year = the year of inheritance** — already expressible today; the work is the
   UI label, not the calculator.
7. **IHT** — additional properties add to `estateExcludingPensions` and get **no** RNRB.
8. **Liquidity** — in `totalWealth`, never in usable wealth until sold (Q3).

## Buy-vs-rent interaction

Additional properties are **held constant** across stay-put / buy / rent — selling your home does
not touch your BTLs. `HousingComparison::withHousing()` must carry `additionalProperties` through
unchanged. It rebuilds `Household` with named arguments outside `copy()` and has no reflection
guard, so this is the single most likely place for the field to be silently dropped. Add an explicit
test.

## Storage + what-if synergy

- `builder_state` gains a `properties` array — each a map mirroring the residence property map plus
  rent / disposal / acquisition.
- **Free synergy:** delta-child what-ifs already store an **added row whole** and a **removed row**
  as a `REMOVED` sentinel (DECISIONS 2026-06-30). "What if we sell the BTL" is an ordinary remove
  delta. No new storage machinery.
- **Gotcha, and it has bitten before:** a new builder field means moving **four** things together —
  the blank default, the validation rules, the `loadState()` backfill, and `BuilderStateFixture::full`.
  A non-empty default breaks child what-if deltas until the fixture is updated. `properties` defaults
  to `[]`, which is the safe case, but the per-row fields inside it are not.

## UI (builder)

- A repeatable **"Other properties (buy-to-let / second homes)"** section: **+ Add property**, each
  row with value, mortgage, ownership share, growth override, **gross annual rent**, joint-ownership
  and higher-rate toggles, the existing **CGT-on-sale wizard** (defaulting to *all let* for a pure
  BTL), and an optional **"plan to sell in year ___"**.
- Reuse the existing CGT wizard partial per property.
- **Acquisition affordance:** `purchased | inherited | gifted` **relabels** the cost field —
  "Probate / market value when inherited" + "Year inherited", or "Market value at gift" — so the user
  enters the correct CGT base cost without knowing the rule. Pure labelling over the same
  `CgtHistory.purchasePrice` / `acquisitionYear`.

## Results / presentation

- Wealth breakdown lists each property; income-by-source shows rental income.
- A **per-property sale waterfall** when disposed (reuse `saleExplainer`).
- IHT panel reflects the larger estate and the absent RNRB on lets.

## Tax nuances, and where v1 stops

- **CGT base cost on inherited / gifted property** = market value at death / gift. Supported; surface
  it in the UI labels.
- **No PRR / no final-nine-month exemption** on a never-occupied let. Already correct when
  `mainResidenceMonths = 0`.
- **Section 24** — already modelled. Not a gap.
- **£1,000 property allowance**, rental losses carried forward — out of Phase 1, flagged in
  DATA-MODEL "Known divergences".
- **SDLT additional-property surcharge** — only bites if we model *acquiring* a property mid-forecast.
  Phase 3.

## Tests (the project's reconciliation / completeness bar)

- **Per-source completeness** (the DLA-drop lesson): a held property's value reaches `totalWealth`
  *and* the IHT estate; its **net rent** reaches the income-tax pass; its **disposal proceeds** reach
  liquid wealth; **CGT on disposal** is charged. Each demonstrably contributes.
- **Reconciliation:** Σ per-property values == reported `propertyWealth`; disposal proceeds reconcile
  (value − mortgage − costs − CGT == cash added); **rent counted exactly once**.
- **Carry-through:** `HousingComparison::withHousing()` preserves `additionalProperties` across all
  three variants.
- **Golden fixture:** a realistic multi-property household (residence + one inherited-let + one BTL
  with a mortgage), not a synthetic happy path.
- **Disclosure:** any engine-side default reaching the projection appears in `assumedFigures()`
  (`AssumedFiguresDisclosureTest`), and `scenarios:audit` gains the rent-overlap check of Q4.
- **Engine isolation** stays intact (`EngineIsolationTest`).

## Phasing

- **Phase 1 (the whole of the settled scope):** hold N additional properties as growing capital
  assets with net rental income, letting costs read from card 0030, IHT estate inclusion, and CGT on
  an optional planned disposal. Buy-vs-rent unchanged beyond carrying the list through.
- **Phase 2 (not scoped, no card):** liquidity/drawdown integration (sell a BTL to fund retirement),
  means-tested capital and care, per-property mortgage maturity events.
- **Phase 3 (not scoped, no card):** acquiring property mid-forecast (SDLT surcharge), property
  allowance, loss carry-forward, refinancing.
