# Data model: RetireForecast

_Last updated: 2026-06-29_

The single source of truth for this project's data shape. Every layer (engine, storage, UI)
conforms to this. The canonical representation **is** the engine's readonly DTOs under
`packages/finance-engine/src/Dto/`; Eloquent models and Livewire form objects map to/from
those DTOs (one shape, three consumers). Full prose field lists also live in docs/PLAN.md
("Data model (canonical shape)").

**Status: the DTOs are built, and all three consumers (engine, storage, UI) now map to/from
them.** Household, Person, the three Pension subtypes, Property, Account, IncomeStream,
ExpenseProfile, HousingAction, AssumptionSet and their enums all exist under `src/Dto/`. The app
persists them as **clear structural columns + one encrypted payload per row** (see "Storage shape"
and "Materialised today"). **SimulationRun and Result are built** (the forecast-services step): a
run records mode/n_paths/seed/status/progress + a frozen encrypted assumption snapshot, with one
encrypted `Result` per housing variant. The **UI consumer** is now real too: the Livewire scenario
builder collects strings and `app/Forecast/HouseholdAssembler` rebuilds the DTOs losslessly (money
parsed to exact pence), so a value entered, stored and re-read is identical.

## Conventions (honoured by all existing code)
- **Money = integer pence** (`Money` value object, GBP only). Never a PHP float in tax or
  cashflow arithmetic.
- **Rates = `Percent`** (integer basis points, no float drift).
- **Dates = ISO `Y-m-d`.** **Ages are derived from DOB + a reference date, never stored.**
- 🔒 = sensitive, encrypt at rest when persistence is added. `?` = nullable.

## Entities (planned canonical shape)

### Household
| Field | Type | Units | Nullable | Notes |
|-------|------|-------|----------|-------|
| id | id | | no | |
| name | string | | no | |
| region | enum | | no | `england_wales` (default) \| `scotland` \| `ni` |
| persons | Person[] | | no | 1–2 |
| primary_residence_id | id | | yes | → Property |
| created_by_user_id | id | | yes | null = anonymous |

### Person 🔒
| Field | Type | Units | Nullable | Notes |
|-------|------|-------|----------|-------|
| dob | date | Y-m-d | no | ages derived, never stored |
| employment_status | enum | | no | employed \| self_employed \| retired \| not_working |
| gross_salary | Money | pence/yr | yes | working partner |
| salary_growth | Percent | bps/yr | yes | |
| ni_category | string | | yes | |
| planned_retirement_age | int | years | yes | |
| state_pension_deferral_weeks | int | weeks | no | default 0 |
| sex_for_mortality | enum | | no | drives cohort life table |

### Pension 🔒 (single table, subtype-discriminated by `subtype`)
Common: id, person_id, subtype (`dc` \| `db` \| `state`).

**DC:** current_value (Money), ongoing_contributions (Money/yr), employer_contributions
(Money/yr), growth_assumption (Percent?), pcls_taken_to_date (Money, LSA tracking),
crystallised_value (Money), access_age (int; 55, rising to 57 from Apr 2028),
intended_withdrawals (WithdrawalPlan[]: kind PCLS/UFPLS/drawdown, amount, age).

**DB:** accrued_annual_pension (Money/yr), normal_retirement_age (int),
revaluation_basis (enum, pre-retirement), escalation_in_payment (enum, post-retirement,
distinct from revaluation), commutation_lump_sum (Money?), commutation_factor (ratio?),
spouse_pension_fraction (Percent?, survivor benefit — matters for joint-life).

**State:** weekly_entitlement (Money/wk?) or qualifying_years (int?),
spa_override (int?; normally computed from DOB), triple_lock_assumption (enum).

### Property
| Field | Type | Units | Nullable | Notes |
|-------|------|-------|----------|-------|
| current_value | Money 🔒 | pence | no | |
| ownership | enum | | no | outright \| mortgaged |
| outstanding_mortgage | Money 🔒 | pence | yes | |
| is_primary_residence | bool | | no | PRR / capital-exemption flag |
| ever_let | bool | | no | default false; triggers PRR restriction |
| ownership_share | Percent | bps | no | default 100% |
| running_costs | Money 🔒 | pence/yr | yes | maintenance + insurance + council tax |
| growth_assumption | Percent | bps/yr | yes | |

### Account
| Field | Type | Units | Nullable | Notes |
|-------|------|-------|----------|-------|
| owner_person_id | id | | no | |
| type | enum | | no | isa \| gia \| cash \| premium_bonds |
| balance | Money 🔒 | pence | no | |
| unrealised_gain | Money 🔒 | pence | yes | GIA, for CGT |
| yield | Percent | bps | yes | |
| is_assessable_capital | bool (derived) | | no | home excluded |

### IncomeStream
owner_person_id, type (rental \| annuity \| disability_benefit \| other), gross_amount (Money 🔒/yr), taxable (bool),
inflation_linked (bool), start_age (int), end_age (int?). `disability_benefit` (DLA/AA/PIP) is **structurally tax-free**
— the assembler forces `taxable = false` for it, so it is disregarded from income tax and the Pension Credit means test
(DECISIONS 2026-07-01).

### ExpenseProfile
target_annual_spend (Money 🔒/yr), essential_portion (Money 🔒 — the floor for "success"),
discretionary_portion (Money 🔒), inflation_basis (enum), one_off_costs (OneOff[]:
care/SDLT/etc.), survivor_spend_factor (Percent, spend change on first death, default ~70%).
**Contingent costs (2026-06-29, #1; extended 2026-07-01):** `propertyCosts`, `mortgageCosts` and
`employmentCosts` (all Money?) are the conditional portions of the spend, carried as **marked
subsets** of essential (not second totals), so the engine can stop charging each when its
condition no longer holds. Aggregated by `HouseholdAssembler` from each line's **condition**
(`always` \| `while_owning_home` \| `while_mortgaged` \| `while_working`; auto-classified by label,
explicit override wins):
- `propertyCosts` (`while_owning_home`) — service charge / ground rent / factor fee; stop when the
  home is **sold** (`withoutPropertyCosts()`), continue while it is owned.
- `mortgageCosts` (`while_mortgaged`) — the ongoing mortgage **payment**; stops when the mortgage
  ends by **sale** (also dropped by `withoutPropertyCosts()`) **or by redemption** while the home is
  kept (the projector drops it once `RepayFromCapital` clears the balance — so a repay-and-stay path
  is not charged both the repayment and the payment). Split out from `propertyCosts` because a
  mortgage's stop condition is stricter than "while owning".
- `employmentCosts` (`while_working`) — commuting; the projector drops it in years no one earns.

### Scenario
household_id, name, variant (`buy_outright` \| `rent` \| `stay_put`),
housing_action { sale_price (Money), buy_price (Money?), rent_pa (Money?),
rent_inflation (Percent?) }, withdrawal_decisions[], assumption_set_id, region,
base_tax_year, iht_modelled (bool toggle), encrypted_payload 🔒.

### AssumptionSet
name, source_note, asset_classes [{ name, expected_real_return (Percent),
volatility (Percent) }], correlation_matrix, inflation_mean (Percent), inflation_vol (Percent),
salary_growth (Percent), house_price_growth (Percent), rent_inflation (Percent),
investment_income_yield (Percent — nominal GIA income yield, A5; ~2%, ⚠️ verify),
is_default (bool). Shipped presets: FCA-derived (default), DMS/EGS-derived,
OBR/BoE-inflation-blended. A5: the projector splits a GIA's total return into this taxable
income (dividends, taxed yearly) + capital growth (CGT on disposal vs the account's
`unrealised_gain` cost basis); cash interest = the cash return, taxed as savings; ISA stays
tax-free. Mapper defaults a pre-A5 snapshot's yield to 2.0%.

**User-editable custom set (2026-06-29).** A scenario may tune the chosen preset's economic
figures into a derived custom set. The edits live in `builder_state` under
`assumptionOverrides`: a **sparse map** of `{ investmentGrowth, inflation, houseGrowth,
rentGrowth, salaryGrowth, incomeYield }` => percentage string, holding **only the figures the
user changed** (an absent key keeps following the preset, so a re-source flows through — the
same base ⊕ overrides discipline as a delta-child, merged by `BuilderStateDelta`). The engine
`AssumptionSet` gains pure `with*` derivations (`withRealReturnShift` for the blended-real
investment growth, single-field setters for the rest); `App\Forecast\AssumptionOverrides::apply()`
overlays the delta onto the preset DTO; and `ScenarioForecaster::assumptions()` is the **single
place** it is applied, so every consumer (deterministic, per-variant ladder, Monte Carlo, the
frozen run snapshot) sees one customised set. The `AssumptionSet` DTO stays the canonical shape;
the overrides are an app-layer edit on top, never a parallel store.

### SimulationRun
scenario_id, mode (`preview` \| `full`), n_paths (int), seed (int?; null = random, always
recorded), horizon (joint-life), status (`queued` \| `running` \| `done` \| `failed`),
progress_pct (int), engine_version, taxyear_config_version,
assumption_set_snapshot 🔒 (frozen copy — results survive later default changes).

### Result
simulation_run_id, success_probability { essentials, full_spend },
terminal_wealth_percentiles { p10..p90 }, depletion_age_distribution,
yearly_percentile_bands[] (fan chart), first_year_tax_breakdown 🔒 (the lump-sum shock),
estate_value / iht_due (Money?, if toggle on), warnings[] (cliff-edge hits, MPAA triggered,
emergency tax, capital crossed £16k).

### Retirement Living Standards (PLSA benchmark — sourced reference, not persisted)
Engine reference data under `src/Benchmark/` (`RetirementLivingStandards` + `RetirementLivingStandardsResult`),
alongside the other sourced figures (tax config, assumption sets, mortality). Three annual-budget tiers
(`minimum` \| `moderate` \| `comfortable`) × {single, couple} × {outside London, London}, each a `Money`,
plus provenance constants `SOURCE` / `EDITION` / `VERIFIED_ON`. **Basis (PLSA's own):** excludes rent +
mortgage (assumes outright ownership), **includes** home running costs. Not stored and not personal — it is a
yardstick. The results page compares the household's **lifestyle spend** (`ExpenseProfile::targetAnnualSpend()`,
i.e. essential + discretionary, excluding *saved* self-investment) **+ owned-home running costs** (rent excluded
by construction) against the tier for the household's composition, via `App\Forecast\ResultPresenter::plsaBenchmark()`.
Reconciles to the same `ExpenseProfile` the forecast runs on (no second definition of "spend"). Figures
read 2026-06-26 and **re-confirmed against the published table in the gov.uk figure pass 2026-06-27** (all 12
match exactly; see DECISIONS 2026-06-27).

## Storage shape (how the app persists the DTOs)
**Post-Phase-B (2026-06-25 rebuild).** A scenario stores the **raw builder form-state** as one encrypted
payload (`builder_state`), which is the **single source of truth**; the engine `Household` + `HousingAction`
DTOs are **derived** from it on demand by the `HouseholdAssembler` (`Scenario::toHousehold()` /
`toHousingAction()`) — there is **no reverse-mapper**. The clear structural columns are a **projection** of
that form-state, refreshed on every save (`fillFromBuilderState()`), kept clear for listing/filtering. Money
is stored as integer pence, Percent as integer basis points, dates as ISO `Y-m-d`, backed enums by value, the
unbacked `WithdrawalKind` by case name. (The pre-rebuild `households` + `scenario_drafts` tables and the
`Household`/`HousingAction` mappers were **dropped** — see "Materialised today" and "Planned shape changes".)

- **scenarios** — clear: `user_id?` (owner), `assumption_set_id?`, `name`, `variant`
  (`buy_outright|rent|stay_put`), `base_tax_year`, `iht_modelled`, `status` (`draft|ready`),
  `parent_scenario_id?` (a what-if **child**'s base; self-FK, cascade). A **base** scenario holds the
  encrypted `builder_state` (the form-state above; an in-progress build is a `draft`-status base, one per
  user, promoted to `ready` on save). A **child** holds **no `builder_state`** — instead an encrypted
  `overrides` delta (a sparse map of changed form-state leaves); effective inputs = base ⊕ overrides via
  `App\Forecast\BuilderStateDelta`, resolved by `Scenario::effectiveBuilderState()`, off which the same
  `toHousehold()`/`toHousingAction()` + clear-column projection run. There is **no** `household_id` and no
  separate `HousingAction` payload (the housing figures live inside `builder_state`).
- **assumption_sets** — clear: `name`, `source_note`, `is_default`. Plain JSON `payload`
  (asset classes, correlation matrix, inflation/growth rates) — not personal data, so not
  encrypted. Seeded from the engine's `AssumptionSetLibrary`; at most one default. Maps
  to/from the `AssumptionSet` DTO.
- **simulation_runs** — clear: `scenario_id`, `user_id?`, `mode` (`preview|full`), `n_paths`,
  `seed` (always recorded), `status` (`queued|running|done|failed|cancelled`), `progress_pct`,
  `engine_version`, `taxyear_config_version`, `started_at?`, `finished_at?`, `error?`. Encrypted
  `assumption_snapshot`: a frozen copy of the `AssumptionSet` DTO used, so a stored result stays
  reproducible after the live set is edited.
- **results** — clear: `simulation_run_id`, `variant` (unique per run). Encrypted `payload`: the
  engine's `SimulationResult` (success probabilities, terminal-wealth percentiles, fan-chart
  bands). A buy-vs-rent run produces three (stay_put, buy_outright, rent) on identical seeds.

`ScenarioVariant`, `ScenarioStatus`, `SimulationMode` and `SimulationStatus` are app-level enums
(the engine takes a Household + HousingAction and does not name the variants). Withdrawals live
on the DC pension inside the `builder_state` form-state, not separately on the scenario.

## Materialised today (concrete shape in code)
- `Money/{Money, Percent, IntMath, RoundingMode}` — integer-pence money + basis-point rates.
- The full `TaxYear/` config spine (2025-26, 2026-27; England/Wales/NI; Scotland throws) and
  all per-calculator result objects.
- The domain DTOs under `src/Dto/` (Household, Person, DcPension, DbPension,
  StatePensionEntitlement, Property, Account, IncomeStream, ExpenseProfile, HousingAction,
  AssumptionSet + enums). `DcPension` carries an optional **`AnnuityPurchase`** (`atAge`, `amount`,
  `rate` as a user-input Percent, `escalation` PensionEscalationBasis where `None` = level, optional
  `survivorFraction` for a joint-life annuity; null = stay in drawdown) — the pot converts to a
  guaranteed lifetime income at that age, mapped to the `other_taxable` income source (DECISIONS 2026-07-01).
- **App persistence:** Eloquent `Scenario` (a base holds the encrypted `builder_state`, the source of
  truth; a Phase-C2 child instead holds `parent_scenario_id` + an encrypted `overrides` delta and resolves
  `effectiveBuilderState()` = base ⊕ overrides; both derive the engine DTOs). The child's `overrides` is also
  surfaced **read-only as "what changed"** by `App\Forecast\WhatIfChanges` (a pure projection of the delta — one
  home per fact, never a separate store): it reads the base value each override replaced via
  `BuilderStateDelta::valueAt()` and humanises each dot-path into `{label, from, to}` for the results panel,
  the dashboard tags and the Compare chips. `AssumptionSet`,
  `SimulationRun`, `Result` with `encrypted:array` payload casts; the `app/Finance/Mapping/`
  `AssumptionSetMapper` + `SimulationResultMapper` + `Codec`. (The `Household`/`HousingAction` mappers and
  the `households`/`scenario_drafts` tables were dropped in Phase B — see "Planned shape changes".)
- **App forecast services:** `app/Forecast/` — `ScenarioForecaster` (assembles engine inputs from
  a persisted scenario; deterministic / single-variant / buy-vs-rent), `SimulationRunner`
  (create → run → persist, with progress + cancel), `RunScenarioSimulation` job.
- **Builder + drafts (2026-06-25, Phase B):** `Person` gained an optional display-only `$name` (carried in
  `builder_state`, derived by the assembler; never used in any calculation). The in-progress builder
  auto-saves as a **`draft`-status `Scenario`** (one per user, encrypted `builder_state`) so work survives
  leaving the page; it is promoted to `ready` on save. (Replaces the dropped `scenario_drafts` table.)

## Planned shape changes (2026-06-25) — authorised, not yet built
For the research-backed plan (docs/PLAN.md "Sector-informed build plan"; DECISIONS 2026-06-25).
Recorded here so the rebuild does not fork the model:
- ✅ **BUILT (2026-06-25 rebuild, Phase B).** **`scenarios.builder_state`** (encrypted) is the raw builder
  form-state — the **single source of truth / editable record**. The engine `Household` + `HousingAction`
  DTOs are **derived** from it on demand (`Scenario::toHousehold()` / `toHousingAction()` via the
  `HouseholdAssembler`); there is **no reverse-mapper**. The clear structural columns (name, variant,
  base_tax_year, iht_modelled, assumption_set_id) are a **projection** refreshed on every save by
  `Scenario::fillFromBuilderState()`, never an independent source. The old `households` table + `Household`
  model + `HouseholdMapper`/`HousingActionMapper`, and the separate `scenario_drafts` table + `ScenarioDraft`
  model, are **dropped**: an in-progress build is now a `draft`-status scenario (one per user), promoted to
  `ready` on save. Editing reloads the form-state (`/scenarios/{scenario}/edit`, owner-scoped); save is
  update-or-create and **invalidates stale runs/results** (gotcha B). (No data migration — rebuild authorised.)
- ✅ **BUILT (2026-06-26 rebuild, Phase C2).** **Base plan + delta child what-ifs.** A child scenario
  references a base (`parent_scenario_id`) and stores only a **delta** of overridden form-state leaves in an
  encrypted **`overrides`** column (no `builder_state` of its own); effective inputs = base ⊕ overrides via
  one merge function (`App\Forecast\BuilderStateDelta`), resolved by `Scenario::effectiveBuilderState()`.
  **Not a full copy** (full-copy forks). **List rows (pensions, accounts, income streams, one-off costs,
  withdrawals) now carry stable `id`s** so an override targets the right row across base edits (people keep
  p1/p2). A base edit refreshes its children's projected columns and drops their stale runs; a base delete
  cascades to its children. **Compare** runs base + children side by side on their deterministic projection.
  **v1 boundary:** a child overrides *values* only — adding/removing a list row is refused
  (`structurallyDiffers`) and directed to the base or a new forecast.
- ✅ **CORE BUILT (2026-06-26, Phase C1). Expenditure → 3-tier line items:** `builder_state.expenseLines`,
  each `{id, label, amount(annual £ string), category ∈ essential|discretionary|self_investment, savedAsAsset
  (bool), condition?}`, is the **single source** of spend. **(2026-06-29, #1)** an optional `condition ∈
  always|while_owning_home|while_working` is the contingent-cost override; when absent the `HouseholdAssembler`
  **auto-classifies by label** (mortgage / service charge → while-owning; commute → while-working; else always)
  and aggregates the contingent lines into `ExpenseProfile::propertyCosts`/`employmentCosts` (above). The `HouseholdAssembler` derives the engine `ExpenseProfile`
  totals from them: essential = Σ essential lines; discretionary = Σ discretionary + *spent* self-investment.
  A *saved* self-investment line (`savedAsAsset: true`) is **not spend** — it becomes a balance-zero ISA
  `Account` with `ongoingContributions` = the saved amount (the engine applies it from surplus), so it is
  counted **once** (one home per pound). The flat `expense.essential/discretionary` are cleared when lines
  exist (no drifting total); a legacy/imported scenario seeds lines from its flat totals on load. List rows
  carry stable ids (so a C2 override can target a line). The builder shows the 3-tier split as a goal, not a
  fixed %. **Fast-follow BUILT (2026-06-26):** the results **3-tier display**
  (`ResultPresenter::expenseBreakdown`, reconciling to the assembled spend) + the **income-floor readout**
  (`incomeFloor()`, off the new `YearResult::essentialSpend`); **importers now emit real lines** —
  `ImportResult` gained `expenseLines` (`list<{label, amount, category, savedAsAsset?}>`, no id — the builder
  assigns ids on apply); the three calibrated profiles populate it (RetireForecast per-row, PayAndExpenditures
  per-outgoing, CSP per-bucket), with the flat `expense` kept as the reconciliation anchor and the gotcha-A
  guard extended to the line sums. **(2026-06-28, Phase D Tier-1):** `ImportResult` also gained
  `reconciliation: list<ReconciliationLine>` — a **transient (not stored)** import-review artifact pairing each
  imported/aggregated total with the sheet's own independent figure for the same quantity
  (`{label, imported, stated?, detail?}`, compared in **exact pence**, `stated = null` when the layout has no
  second figure) so the import panel can flag a divergence loudly. **Deferred (→ C4):** the PLSA benchmark;
  phased ("smile") spend (an engine change).
- ✅ **BUILT (2026-06-25 rebuild, Phase A).** **`Account` gained `ongoingContributions`** and the projector
  now applies it (and DC `ongoingContribution`/`employerContribution`, previously ignored), funded from
  surplus so *saved* self-investment accumulates.
- ✅ **BUILT (2026-06-25 rebuild, Phase A; builder lever wired 2026-06-26).** **`Person` gained
  `LongevityAdjustment`** (`LongevityMode`: peer / fixed age / ±years / mortality multiplier) feeding both the
  deterministic representative death age and the Monte-Carlo `JointLifeSampler` (via an optional q(x)
  multiplier on `CohortLifeTable`). The builder now carries the lever as two per-person form fields —
  `longevityMode` (`peer`/`fixed_age`/`offset_years`) + `longevityValue` — which the `HouseholdAssembler` maps
  to the adjustment (peer/blank → null); a child what-if can override either via the C2 delta. The
  `mortality_multiplier` mode stays engine-only (no builder control in v1).
- ✅ **BUILT (2026-06-25 rebuild, Phases A + C3).** **Results split usable vs total wealth.** The engine now
  reports terminal **usable** wealth (excl. home) on `ForecastResult`/`SimulationResult`
  (`usableWealthPercentiles`) alongside total; the results page shows both, so the asset-rich / cash-poor case
  (100% run out yet high "wealth left") reads correctly. **Extended 2026-06-29:** `SimulationResult` also
  carries a **per-year** usable fan (`usableFanChart`) beside the total `fanChart` — same `liquid + pension`
  definition as the ladder, with a `usable ≤ total` per-year invariant — so the over-time charts can default to
  spendable (excl-home) money, the honest "will it last" series (gotcha P), with an include-home toggle. Also added **`YearResult::incomeBySource`** (the
  canonical sources — now 10, incl. `means_tested_benefit`) powering the deterministic cashflow ladder + the
  per-source completeness guard. Phase C1 added **`YearResult::essentialSpend`** (real terms — the essential
  floor incl. rent/running costs and the survivor factor) so the income-floor readout reads one definition.
  **2026-07-01** added **`YearResult::investmentGrowth`** (nullable Money, real terms) — the year's CAPITAL
  appreciation left in the pots (share/fund growth; separate from the taxed `investment_income` paid out), so
  the ladder can show where wealth grows beyond income; `growState` returns it, deflated by next year's price
  level for the real purchasing-power gain (DECISIONS 2026-07-01). **Still app-side (not yet built):** the
  **PLSA benchmark** (→ C4).
- ✅ **BUILT (2026-06-29, adviser-legibility presentation layer).** Two small **additive engine** outputs feed the
  legibility layer, each a single source the app only reads: (1) **`ForecastResult::deathCalendarYears`**
  (`array<personId, int>` = birthYear + modelled death age, computed once in `PathProjector` from the draws; default
  `[]`) — the canonical "when does each person die", powering the milestones + input-sanity notes without
  re-deriving the death age; (2) **`Housing\HousingPurchase`** (a reconciled value object beside `HousingProceeds`:
  `netProceeds − buyPrice − stampDuty − movingCosts = surplus`) — the single source of the buy-side surplus, read by
  `HousingComparison::buyVariant` and the sale-explainer. The results-page **view-models** (`ResultPresenter::saleExplainer`
  / `assumptionsPanel` / `milestones` / `inputNotes`, plus the ladder's essential/discretionary split) are app-side
  presentation derived from these + the household — they add **no** persisted entity and **no** canonical-shape change.

## Forced-housing-event workstream (2026-06-30/07-01) — BUILT
For the rationale + the real-couple case that surfaced these: DECISIONS 2026-06-30 (forced-mortgage pressure-test;
input-expectation clarity) + 2026-07-01 (deferred-refinement resolutions) and docs/PLAN.md "Forced-housing-event
workstream". The canonical-shape additions below are now materialised (see `git log`); two details resolved differently
from the original plan, flagged inline:
- ✅ **(A) Means-tested benefits in the forecast.** Engine `Benefits\PensionCreditCalculator` (Guarantee Credit
  to the Standard Minimum Guarantee + Severe Disability / Carer additions; capital tariff via the existing
  `CapitalAssessment`). **`YearResult` gains an income source `means_tested_benefit`** (the canonical source list
  grows from 8 to 9 — update the completeness guard). A **disability flag** is added to `Person` (or `Household`):
  e.g. `receivesDisabilityBenefit: bool` (+ a derived "severe disability" qualifier), driving the SDP and the
  DLA/AA passport. The benefit is a **household-level** credit computed each projected year from that year's
  assessable income + assessable capital (liquid wealth, home excluded), so it erodes/restores dynamically and
  fires the £16k Housing/Council-Tax-Support cliff in-projection. CTR itself stays out (locally set) — modelled as
  the cliff/passport, flagged. **Refinement (2026-07-01):** a `Property::isLet` flag means a **let** home's equity
  joins assessable capital (it is no longer the exempt main residence) — so "let out & rent" erodes benefit like a sale.
- ✅ **(B) Mortgage redemption.** `Property` gained `mortgageRedemptionYear: int?` and `mortgageMaturityAction:
  enum {refinance | repay_from_capital | forced_sale}`. The projector tracks the mortgage **balance** (new state) and
  applies the action at maturity. *Open:* the one-off **path scope** field, and stopping the bundled mortgage *payment*
  after a repay (needs a `while_mortgaged` expense condition — DECISIONS 2026-07-01, still deferred).
- ✅ **(C) Feasibility** is a **derived** result note (no stored field): `HousingComparison` exposes whether a buy
  price exceeds net proceeds (and the gap), surfaced by `ResultPresenter` as an input-sanity note.
- ✅ **(D) Input clarity** is mostly **builder-state / UI**, not canonical-shape: a per-input **pay frequency** is a
  form concern (stored annual, so the DTO is unchanged). The tax-free-benefit type was **upgraded** from the planned
  `IncomeStream{type: other, taxable: false}` to a first-class `IncomeStreamType::DisabilityBenefit` (structurally
  tax-free — see IncomeStream above + DECISIONS 2026-07-01). The planned `endsOnSale` property-link is **declined**
  (single-property model — DECISIONS 2026-07-01).

## Known divergences (to close)
- The DTO carries withdrawals on the DC pension; the original Scenario sketch listed
  `withdrawal_decisions` separately. Resolved in favour of the DTO (one source of truth); the
  scenario does not duplicate them.
- The `Result` shape stores the Monte Carlo `SimulationResult` per variant. The data-model
  sketch also listed a deterministic `first_year_tax_breakdown` (the lump-sum shock) on Result;
  that deterministic detail is computed on demand (now surfaced live on the results page by
  `App\Forecast\LumpSumTaxShock`, via the engine's `FlexibleWithdrawalAssessor`) and is **not
  persisted** — fold it into `Result` only if a stored copy is ever needed.
- **Spreadsheet import does not add to the canonical shape:** `app/Import/` profiles produce
  partial *builder form-state* (the same strings the wizard collects), which `HouseholdAssembler`
  maps into the existing DTOs. No new persisted entity; money is parsed to exact pence (`MoneyText`).
