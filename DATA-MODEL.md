# Data model: RetireForecast

_Last updated: 2026-06-25_

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
owner_person_id, type (rental \| annuity \| other), gross_amount (Money 🔒/yr), taxable (bool),
inflation_linked (bool), start_age (int), end_age (int?).

### ExpenseProfile
target_annual_spend (Money 🔒/yr), essential_portion (Money 🔒 — the floor for "success"),
discretionary_portion (Money 🔒), inflation_basis (enum), one_off_costs (OneOff[]:
care/SDLT/etc.), survivor_spend_factor (Percent, spend change on first death, default ~70%).

### Scenario
household_id, name, variant (`buy_outright` \| `rent` \| `stay_put`),
housing_action { sale_price (Money), buy_price (Money?), rent_pa (Money?),
rent_inflation (Percent?) }, withdrawal_decisions[], assumption_set_id, region,
base_tax_year, iht_modelled (bool toggle), encrypted_payload 🔒.

### AssumptionSet
name, source_note, asset_classes [{ name, expected_real_return (Percent),
volatility (Percent) }], correlation_matrix, inflation_mean (Percent), inflation_vol (Percent),
salary_growth (Percent), house_price_growth (Percent), rent_inflation (Percent),
is_default (bool). Shipped presets: FCA-derived (default), DMS/EGS-derived,
OBR/BoE-inflation-blended.

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

## Storage shape (how the app persists the DTOs)
Per the persistence decision, every persisted row is **clear structural columns + one encrypted
payload**. The DTO is the shape; the mapper (`app/Finance/Mapping/`) turns it into the payload
array and back. Money is stored as integer pence, Percent as integer basis points, dates as
ISO `Y-m-d`, backed enums by value, the unbacked `WithdrawalKind` by case name.

- **households** — clear: `user_id?`, `name`, `region`. Encrypted `payload`: persons, pensions
  (dc/db/state, each carrying its own withdrawal plan), accounts, income streams, expense
  profile, primary residence. Maps to/from the `Household` DTO.
- **scenarios** — clear: `household_id`, `user_id?`, `assumption_set_id?`, `name`, `variant`
  (`buy_outright|rent|stay_put`), `base_tax_year`, `iht_modelled`, `status` (`draft|ready`).
  Encrypted `payload`: the `HousingAction` (sale/buy/rent figures).
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
on the DC pension inside the household payload, not separately on the scenario.

## Materialised today (concrete shape in code)
- `Money/{Money, Percent, IntMath, RoundingMode}` — integer-pence money + basis-point rates.
- The full `TaxYear/` config spine (2025-26, 2026-27; England/Wales/NI; Scotland throws) and
  all per-calculator result objects.
- The domain DTOs under `src/Dto/` (Household, Person, DcPension, DbPension,
  StatePensionEntitlement, Property, Account, IncomeStream, ExpenseProfile, HousingAction,
  AssumptionSet + enums).
- **App persistence:** Eloquent `Household`, `Scenario`, `AssumptionSet`, `SimulationRun`,
  `Result` with the to/from-DTO bridges and `encrypted:array` payload casts; the
  `app/Finance/Mapping/` mappers + `Codec`.
- **App forecast services:** `app/Forecast/` — `ScenarioForecaster` (assembles engine inputs from
  a persisted scenario; deterministic / single-variant / buy-vs-rent), `SimulationRunner`
  (create → run → persist, with progress + cancel), `RunScenarioSimulation` job.
- **Builder + drafts (2026-06-26):** `Person` gained an optional display-only `$name` (persisted via
  the mapper/assembler; never used in any calculation). A new **`scenario_drafts`** table (one per
  user, encrypted form-state) auto-saves the in-progress builder so work survives leaving the page.

## Planned shape changes (2026-06-26) — authorised, not yet built
For the research-backed plan (docs/PLAN.md "Sector-informed build plan"; DECISIONS 2026-06-26).
Recorded here so the rebuild does not fork the model:
- **`scenarios.builder_state`** (encrypted): the raw builder form-state — the **editable record**. The
  engine `Household` DTO becomes a **derived** artifact regenerated from it on save (one source of input).
- **Base plan + delta child what-ifs:** a child scenario references a base and stores only a **delta** of
  overridden parameters; effective inputs = base ⊕ overrides via one merge function. **Not a full copy**
  (full-copy forks). Compare runs base + child side by side.
- **Expenditure → 3-tier line items:** `{label, amount(annual), category}`, category ∈ essential /
  discretionary / **self-investment** (savings + contributions). Line items are the **source**; the
  essential/discretionary totals are the **sum of the lines** (derived). The budget view emphasises the
  prioritisation goal (keep / can-drop / invest), not a fixed percentage. Deferred: phased ("smile") spend.

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
