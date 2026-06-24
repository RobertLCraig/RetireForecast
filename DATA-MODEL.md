# Data model: RetireForecast

_Last updated: 2026-06-24_

The single source of truth for this project's data shape. Every layer (engine, storage, UI)
conforms to this. The canonical representation **will be** the engine's readonly DTOs under
`packages/finance-engine/src/Dto/`; Eloquent models and Livewire form objects map to/from
those DTOs (one shape, three consumers). Full prose field lists also live in docs/PLAN.md
("Data model (canonical shape)").

**Status: the DTOs are NOT built yet.** They are the next data-shape deliverable, required
before persistence or UI. What is materialised in code today is only the tax-year config and
money layer (see "Materialised today" below).

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

## Materialised today (concrete shape in code)
- `Money/{Money, Percent, IntMath, RoundingMode}` — integer-pence money + basis-point rates
  with explicit per-call rounding.
- `TaxYear/{TaxYearConfig, TaxYearRegistry, RegionProfile, IncomeTaxParameters,
  DividendParameters, SavingsParameters, NationalInsuranceParameters}` — 2025-26 and 2026-27,
  England/Wales/NI; Scotland throws rather than faking rUK bands.
- `Tax/{IncomeTaxCalculator, IncomeTaxResult}` — non-savings income only so far.

## Canonical representation
The readonly DTOs (to be created under `packages/finance-engine/src/Dto/`) are the one shape
every layer maps to. Until they exist, the tax-year config objects above are the only shared
shape. Enums and their allowed values are listed per-entity above.

## Known divergences (to close)
- None yet — no persistence or UI layer exists to diverge. The first divergence risk appears
  when Eloquent models are added; they must map to the DTOs, not redefine the shape.
