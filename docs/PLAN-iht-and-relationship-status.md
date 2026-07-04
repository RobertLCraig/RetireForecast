# PLAN — wire Inheritance Tax into the forecast, made relationship-status aware

> **Status: DRAFT spec, ready for a fresh agent. Nothing here is built yet.** Dated 2026-07-04,
> written from a code-grounded survey of the actual engine. The sibling NI-category tidy-up from the
> same session **is** built and committed (see DECISIONS 2026-07-04) — do not redo it. Build this in
> green, committed slices per the build order below; honour the reconciliation/completeness bar
> (CLAUDE.md) — this feature exists precisely to close a collected-but-unconsumed input.

## Why / motivating findings

Two findings from the 2026-07-04 browser review drive this:

1. **The `ihtModelled` toggle is collected but never consumed.** It is stored (`Scenario::iht_modelled`),
   set in the builder, and surfaced in what-if diffs (`WhatIfChanges`) and the GDPR export — but **no
   forecast or engine code reads it**. `InheritanceTaxCalculator` exists and is unit-tested, yet it is
   referenced only by its own test and the Filament tax-audit admin page; **nothing in the results
   pipeline calls it**. So turning the IHT toggle on changes nothing a user sees. This is exactly the
   *collected-but-unconsumed input* class the data-integrity rule (CLAUDE.md, DECISIONS 2026-06-25)
   exists to catch — a silent drop.

2. **Relationship status (married / civil-partnered vs cohabiting) can't be expressed, yet it materially
   changes the result.** A two-person household is implicitly treated as married everywhere: `PathProjector::settleEstates`
   assumes the survivor inherits the deceased's assets IHT-free (spousal exemption), and the IHT calculator's
   transferable nil-rate band (the `nilRateBandMultiplier = 2` path) is designed for a married couple's second
   death. A **cohabiting** couple gets **none** of that — no spousal exemption on first death, no transferable
   NRB, no State-Pension inheritance — so today they would be materially **over-relieved**. There is no field
   to say "we're not married".

Relationship status only earns its keep once IHT is actually computed, so the two are one unit of work:
**wire IHT into the forecast (consume the toggle), and make it relationship-status aware.** Relationship
status then falls out naturally and finally gives the toggle meaning.

## What already exists (the head start — do not rebuild)

- **`RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator::compute(...)`** — complete and tested:
  ```php
  compute(
      Money $estateExcludingPensions,
      Money $unusedPensionValue,
      bool  $includePensionsInEstate,   // the April-2027 rule (enacted, Finance Act 2026)
      Money $homePassingToDescendants,  // caps the RNRB
      int   $nilRateBandMultiplier = 1, // 2 for a married couple's second death (transferable NRB)
  ): IhtResult
  ```
  It applies NRB×mult, RNRB×mult with the £2m taper (capped at the home value passing to descendants),
  the 40% rate on the excess, and emits the pensions-in-estate warning. `IhtResult` carries
  `totalEstate, nilRateBandUsed, residenceNilRateBandUsed, taxableEstate, rate, tax, pensionsIncluded, warnings`.
- **`IhtParameters`** per tax year (`TaxYearConfig::$iht`): NRB £325k (frozen to 5 Apr 2031), RNRB £175k
  (frozen to 5 Apr 2030), £2m taper threshold, 40% rate — all gov.uk-verified `2026-06-27`.
- **`Scenario::iht_modelled`** / builder `ScenarioBuilder::$ihtModelled` — the stored toggle, ready to consume.
- **`PathProjector::settleEstates`** — on first death the survivor inherits the deceased's assets (cash/ISA/GIA
  with CGT base-cost uplift + the remaining DC pot); the household runs to the last survivor. Per-year wealth
  legs are already computed: `YearResult::liquidWealth / pensionWealth / propertyWealth`, and the mortgage
  balance lives in projector `state`.
- **`RepresentativeDeathAge`** (extracted 2026-07-01) — the median-lifespan rule the deterministic forecast
  and the historical backtest share, so "when does each person die" already has one definition.

**So the missing pieces are entirely: (a) a relationship-status input, (b) valuing the estate at each death
from the forecast, (c) choosing the multiplier / exemption by relationship status + death order, (d) calling
the calculator inside the forecast, and (e) surfacing the result.**

## Target shape

### 1. `Household::relationshipStatus` (new)
- Enum `RelationshipStatus { MarriedOrCivilPartnership, Cohabiting }` under `src/Dto/`.
- New nullable/defaulted field on `Household` — **default `MarriedOrCivilPartnership`** so every existing
  scenario keeps today's behaviour (spousal treatment) with no change.
- Only meaningful for a two-person household (ignored for one person).
- ⚠️ **Follow [[new-builder-field-delta-gotcha]]**: a new builder field with a **non-empty default** means
  moving four things together — `blankPerson`/blank-state default, validation, `loadState` backfill, and
  `BuilderStateFixture::full()` — or child what-if deltas break. Add `relationshipStatus` to the builder,
  `HouseholdAssembler`, both fixtures, and `WhatIfChanges` label in the same slice.

### 2. Estate valuation at death (`EstateValuer`, engine)
Value the household's estate at a given death, from the forecast state, as **one definition built from its
parts** (reconciliation rule):
```
estateExcludingPensions = liquid (cash + ISA + GIA) + property_value − outstanding_mortgage
unusedPensionValue      = remaining DC pot value (DB has no residual fund)
homePassingToDescendants = property equity IF the home passes to direct descendants (see open question)
```
- **First death:** the deceased's *share* of the estate (respect `Property::ownershipShare` and per-person
  asset ownership — the couple own assets individually; IHT is per person).
- **Second death (last survivor):** the whole remaining estate.
- All figures in the engine's usual **real** terms; see the real-vs-nominal open question for the bands.

### 3. Wire IHT into the deterministic forecast (consume `ihtModelled` + relationship status)
Add to `DeterministicForecaster` (and expose on `ForecastResult`, e.g. an `IhtOutcome { firstDeath: ?IhtResult, secondDeath: ?IhtResult, total: Money }`):

- **Married / civil-partnered:**
  - *First death:* everything passing to the surviving spouse is **spousally exempt → £0 IHT**, and the
    deceased's unused NRB + RNRB transfer to the survivor.
  - *Second death:* the remaining estate to descendants; call `compute(..., nilRateBandMultiplier: 2)`
    (both bands available), `homePassingToDescendants` = the home equity if left to descendants.
- **Cohabiting:**
  - *First death:* the deceased's estate passing to the survivor is a **chargeable transfer** — call
    `compute(deceased-share, deceased-pension, includePensions, homeToDescendants=0-if-to-survivor,
    nilRateBandMultiplier: 1)`. No spousal exemption, no band transfer.
  - *Second death:* the survivor's own estate; `nilRateBandMultiplier: 1`.
- **Single person / already widowed:** one death, multiplier 1 (v1: no re-inheritance of a predeceased
  partner's transferable band — flag as a simplification).
- Gate the whole computation on `ihtModelled`; when off, `IhtOutcome` is null/zero (unchanged behaviour).

### 4. RNRB "home to descendants" input
RNRB applies **only** when the home passes to direct descendants (children/grandchildren). Add a builder
toggle (household-level) "Do you plan to leave your home to your children / direct descendants?" driving
`homePassingToDescendants`. See the open question for the default.

### 5. Surface it (app layer, education/guidance only)
- A results **IHT panel** behind the toggle: estate value, NRB/RNRB applied, taxable estate, **IHT due at
  first + second death**, the pensions-in-estate note (Apr-2027), and the relationship-status assumption
  stated plainly. Pass the banned-phrasing lint; include the `<x-signpost>` (a solicitor / STEP / gov.uk
  inheritance-tax guidance). Add to **Compare** and the **PDF**.
- **Cohabiting caveats** surfaced where relevant: scheme **DB survivor pensions** are usually spouse/civil-
  partner only (offer to zero/​warn `spousePensionFraction` for a cohabiting couple), and **State-Pension
  inheritance** is a spouse/civil-partner right that this tool does not model for anyone — say so.

## Open questions / decisions needed (Rob or the executing agent)

1. **RNRB home-to-descendants default.** Propose a builder toggle defaulting **on** when the household owns a
   home (RNRB commonly applies), flagged in copy. Alternative: default off (conservative — more IHT). *Decide.*
2. **Real vs nominal bands.** The estate is modelled in real terms; the NRB/RNRB are **frozen nominal**, so in
   real terms they shrink over the horizon (real fiscal drag — a modelled feature elsewhere). Options: (a)
   compute IHT in **nominal at the death year** (inflate the real estate + use the frozen nominal band), then
   present as real; or (b) deflate the band to real at the death year. (a) is more correct and matches how the
   Monte Carlo already treats frozen thresholds. *Decide + document.*
3. **Beneficiary assumptions (v1 simplification).** v1 assumes: first death → to the survivor; second death →
   to direct descendants (for RNRB). Non-descendant beneficiaries (no RNRB), split legacies, and leaving the
   first estate to children rather than the survivor are **out of v1 scope** — flag in copy + DECISIONS.
4. **Which run computes IHT.** v1: the **deterministic** forecast (representative death years) drives the
   results panel — one clear number. A **Monte-Carlo IHT distribution** (IHT across paths, correlated with
   longevity + terminal wealth) is a later enhancement (build order 7).
5. **Out of v1 scope (flag each):** lifetime gifts / PETs + the 7-year taper, trusts, business/agricultural
   relief, the charity-reduced 36% rate, gifts-with-reservation, quick-succession relief. State that IHT here
   is an *illustration of the headline bands*, not a full estate computation.

## Tests (the reconciliation / completeness bar)

- **Estate = Σ parts** — `EstateValuer` reconciles to liquid + property − mortgage (+ pensions when included),
  penny-exact; per `Property::ownershipShare` and per-person ownership.
- **Completeness — the toggle now bites.** With `ihtModelled` **on**, a large estate produces a non-zero IHT
  in the `ForecastResult`; with it **off**, none. (Closes the collected-but-unconsumed drop — assert it
  explicitly, the sibling of the existing per-source completeness tests.)
- **Relationship status changes the IHT.** The *same* estate yields **£0 on first death (married)** vs a
  **chargeable transfer (cohabiting)**, and **NRB×2 vs ×1 on second death**. Pin both.
- **Worked-example fidelity.** Extend `InheritanceTaxCalculatorTest`'s worked examples through the *wired*
  path (a £2m estate, home to children, married second death) to the penny — reuse the existing golden values.
- **Back-compat.** An existing scenario (no `relationshipStatus`) rehydrates as married and its forecast is
  byte-identical to before this change.
- **Guidance-only.** The IHT panel passes the banned-phrasing partition lint; the signpost renders.

## Build order (each slice green + committed)

1. **`RelationshipStatus` enum + `Household` field + builder/assembler/fixtures/`WhatIfChanges`** — default
   married, no behaviour change yet. (Watch the delta gotcha.)
2. **`EstateValuer`** — value the estate at a given year from the forecast state; unit-tested (= Σ parts).
3. **Wire IHT into `DeterministicForecaster`** consuming `ihtModelled` + relationship status; add `IhtOutcome`
   to `ForecastResult` (first- + second-death IHT, multiplier by status). Reconciliation + completeness tests.
4. **RNRB home-to-descendants** builder toggle + wiring.
5. **Results IHT panel** (education-only + signpost) + Compare + PDF.
6. **Cohabiting caveats** — DB survivor-pension zero/warn + the SP-inheritance note.
7. **(Later) Monte-Carlo IHT distribution** — IHT across paths, longevity-correlated.

## Files to touch (map)

- **Engine:** `src/Dto/RelationshipStatus.php` (new), `src/Dto/Household.php`, `src/Iht/EstateValuer.php` (new),
  `src/Forecast/DeterministicForecaster.php` (+ `PathProjector` if the estate is read from projector state),
  `src/Forecast/ForecastResult.php` (+ `IhtOutcome`). The calculator + params are done.
- **App:** `app/Forecast/HouseholdAssembler.php`, `app/Livewire/ScenarioBuilder.php` +
  `resources/views/livewire/scenario-builder.blade.php`, `app/Forecast/WhatIfChanges.php`,
  `app/Forecast/ResultPresenter.php` (IHT panel) + the results / compare / PDF blades,
  `app/Forecast/ScenarioForecaster.php` if it needs to thread the toggle.
- **Fixtures/tests:** `tests/Support/BuilderStateFixture.php`, `tests/Support/HouseholdFixture.php`, new
  engine + feature tests per the bar above.
- **Docs:** `DATA-MODEL.md` (record `relationshipStatus` + the now-consumed `ihtModelled`), `DECISIONS.md`
  (the modelling decisions from the open questions), `docs/METHODOLOGY.md` (add the IHT-in-forecast section +
  its v1 limits), HANDOVER.
