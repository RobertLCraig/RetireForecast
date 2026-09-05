# Data model: RetireForecast

_Last updated: 2026-07-30_

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
| salary_growth | Percent | bps/yr | yes | **consumed 2026-07-02** — a per-person **real** (above-inflation) salary-growth override; the projector escalates each person's salary at their own rate, falling back to the assumption set's `salaryGrowth` when null (`PerPersonSalaryGrowthTest`) |
| ni_category | string | | yes | **consumed 2026-07-03** — selects the employee NI band rate: A/F/H/M/N/V standard 8%, B/E/I reduced 1.85%, D/J/L/Z deferred 2%, C/K/S/X nil (gov.uk category letters); empty/unknown = standard |
| planned_retirement_age | int | years | yes | |
| state_pension_deferral_weeks | int | weeks | no | default 0; **lives on the State pension subtype in code** (`StatePensionEntitlement::deferralWeeks`, consumed) |
| sex_for_mortality | enum | | no | drives cohort life table |

### Pension 🔒 (single table, subtype-discriminated by `subtype`)
Common: id, person_id, subtype (`dc` \| `db` \| `state`).

**DC:** current_value (Money), ongoing_contributions (Money/yr), employer_contributions
(Money/yr), growth_assumption_override (Percent?, consumed since 2026-07-02 — per-pot growth
beats the assumption set), pcls_taken_to_date (Money, LSA tracking), earliest_access_age
(int; 55, rising to 57 from Apr 2028 — gates drawdown since 2026-07-02),
intended_withdrawals (WithdrawalPlan[]: kind PCLS/UFPLS/drawdown, amount, age),
annuity_purchase (AnnuityPurchase?, 2026-07-01). (A planned `crystallised_value` field was
never materialised — see Known divergences.)

**DB:** accrued_annual_pension (Money/yr), normal_retirement_age (int),
revaluation_basis (enum, pre-retirement), escalation_in_payment (enum, post-retirement,
distinct from revaluation — ⚠️ both **collected but the engine applies one smooth inflation
proxy** regardless; per-scheme bases are a flagged v1 limit), commutation_lump_sum (Money?),
commutation_factor (**float ratio**, £ lump sum per £1/yr given up, e.g. 12; null/≤0 defaults
to 12 — **consumed 2026-07-02**: the pension is permanently reduced by `lump_sum ÷ factor` and
the lump sum is paid tax-free in the year the member reaches NRA; LSA cap not enforced, a v1
limit), spouse_pension_fraction (Percent?, survivor benefit —
**consumed 2026-07-02**: on the member's death the survivor keeps `accrued_annual_pension ×
fraction` for life, escalated by the same in-payment factor; mirrors the annuity's
`survivorFraction`. A scheme with no fraction still stops on death, as before).

**State:** weekly_entitlement (Money/wk?) or qualifying_years (int?), deferral_weeks (int,
consumed). (Planned `spa_override` and `triple_lock_assumption` fields were never
materialised — SPA is computed from DOB and the triple-lock factor lives in the projector;
see Known divergences.)

### Property
| Field | Type | Units | Nullable | Notes |
|-------|------|-------|----------|-------|
| current_value | Money 🔒 | pence | no | |
| ownership | enum | | no | outright \| mortgaged |
| outstanding_mortgage | Money 🔒 | pence | yes | |
| is_primary_residence | bool | | no | PRR / capital-exemption flag |
| ever_let | bool | | no | default false; triggers PRR restriction |
| ownership_share | Percent | bps | no | default 100%; **consumed 2026-07-03** — the household's beneficial share of a home held with others (tenants in common). Whole-property figures are entered; the share scales the household's wealth, running costs, means-test capital, IHT, and sale proceeds/CGT (HMRC apportions gain + proceeds by beneficial share). Null = wholly owned |
| running_costs | Money 🔒 | pence/yr | yes | maintenance + insurance + council tax |
| growth_assumption | Percent | bps/yr | yes | |
| mortgage_roll_up_rate | Percent | bps/yr | yes | **2026-07-06** — a lifetime-mortgage (equity-release) roll-up rate: fixed nominal, fixed for life. Null = static balance (repayment/serviced, the prior behaviour). Set = the `outstanding_mortgage` COMPOUNDS unpaid in `PathProjector::growState`, NNEG-capped at the home value, repaid from the estate (feeds IHT). See DECISIONS 2026-07-06 |
| repayment_terms | RepaymentMortgageTerms | | yes | **2026-07-29** — the terms of an ordinary capital-and-interest ("repayment") mortgage: `term_months`, `first_payment_year`/`_month`, and an ordered list of `MortgageRatePeriod` rate tiers (annual nominal rate + months; the last open-ended). Null = the two pre-existing shapes (static balance, or roll-up) — every stored scenario is byte-identical. Set = `AmortisationSchedule` owns BOTH legs: the `outstanding_mortgage` amortises to zero over the term, and the fixed-nominal instalment is charged as essential spend, REPLACING the "Mortgage" expense line (dropped, so the two cannot double-count). **Mutually exclusive with `mortgage_roll_up_rate`** (the DTO throws). The instalment is added after the CPI and survivor multiplies — it does not inflate, and does not shrink on a death. The amount borrowed is NOT restated here: it is `outstanding_mortgage`, one home for the debt. See DECISIONS 2026-07-29 |
| mortgage_overpayment | Money 🔒 | pence/yr | yes | **2026-07-19** — a voluntary FIXED-nominal annual overpayment on a rolled-up lifetime mortgage (only meaningful when `mortgage_roll_up_rate` is set): subtracted from the balance each year AFTER the roll-up compounds, floored at 0, so it slows the roll-up and preserves the estate. Null/0 = pure roll-up. The cash to fund it is a separate outflow (a "Mortgage" expense line of the same amount), so the two together show the honest trade-off: a lower balance bought with cashflow the household must find. See DECISIONS 2026-07-19 |

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

### CapitalReceipt (added 2026-07-16 — the no-magic-money input)
owner_person_id, label (string — what the money is and where it comes from), amount (Money 🔒, today's money),
calendar_year (int). A **documented one-off capital inflow** (family gift / inheritance / outside-asset sale):
money from outside the modelled assets is *entered* here, never assumed. Credited to cash in its calendar year
(inflated to that year's prices), reported as the `capital_receipt` income source on the ladder; tax-free, not
means-test income (the banked cash raises tariff income from the following year), **never** part of the
secure-income floor. Builder-state `capitalReceipts` rows; `Household::$capitalReceipts` on the DTO.

### ExpenseProfile
target_annual_spend (Money 🔒/yr), essential_portion (Money 🔒 — the floor for "success"),
discretionary_portion (Money 🔒), inflation_basis (enum), one_off_costs (OneOff[]:
care/SDLT/etc.), survivor_spend_factor (Percent, spend change on first death, default ~70%).
**Age-varying spend / the "smile" (2026-07-06):** essential and discretionary spend each also
carry a `SpendPath` (`src/Dto/SpendPath.php`) — a piecewise-constant **real** path held as
ordered `{fromAge, amount}` bands, keyed by the **reference (first-declared) person's age** (the
same convention/limit as `one_off_costs`). The `essential_portion`/`discretionary_portion`
scalars are the **headline** (first-band) figures — identical to before for a flat plan, and the
figure the non-age-aware consumers read — while the projector reads `…AnnualSpendAt(refAge)`. The
scalar is always the path's first band (enforced in the constructor: **one home**, no drift). A
flat plan has one-band paths, so every scalar and age lookup returns the one value — byte-identical
to the pre-smile engine. `SpendPath::plus` sums two paths band-for-band, so an aggregate path is
the exact per-age sum of its line paths (reconciliation). Represents every industry form (a flat
spend, a go-go/slow-go/no-go step, a Basu per-category schedule, a Blanchett %/yr decline); see
DECISIONS 2026-07-06.
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

**Utilities inside a home-ownership cost (card 0033, 2026-09-05):** `propertyCostsUtilities`
(Money?) is a marked subset of `propertyCosts` — the water, gas and electricity a block service
charge BUYS. It is the one part that does **not** die with the home: `withoutPropertyCosts()` and
the projector's mid-projection forced sale both remove `propertyCosts − propertyCostsUtilities` and
leave the remainder in the essential floor as ordinary always-charged spend (no marker, so no
service-charge escalator applies to it and nothing can strip it twice). Read it through
`propertyCostsUtilities()`, which clamps it to the bucket it comes out of. Builder key
`expenseLines.*.utilities`, offered only on a `while_owning_home` line and stored **sparsely**;
the reader always enters it, the engine never supplies one.

**Insurance is essential where cover is required (card 0033, 2026-09-05):** a spend line's tier is
read through `HouseholdAssembler::tierOf()`, not off its stored `category`. A **discretionary** line
whose label names insurance *and* the home (buildings / contents / home / house / property) counts
in the essential floor instead; nothing else moves, so pet, travel and car cover stay where the
reader put them. The assembler, the builder's live totals and `ResultPresenter::expenseBreakdown()`
all read that one rule, so the screen and the projection cannot disagree about the same pounds.

**Above-CPI property-cost growth (2026-07-08; default added 2026-09-05):**
`propertyCostsRealGrowth` (Percent?) is the **real** annual growth rate on the `propertyCosts`
bucket only. The projector compounds it per projection year on top of the CPI all spend rides; it
follows the bucket (a sold home escalates nothing; the mortgage payment is contractual and is NOT
escalated). Builder key `expense.propertyCostsGrowthPct`, stored **sparsely** (absent when blank, so
pre-field scenarios and unchanged what-ifs record no delta).
**Null is no longer "no growth" (card 0028).** `propertyCostsRealGrowth()` returns
`ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` (**CPI + 3%**) when the rate is null **and**
the bucket is positive; an explicit rate, including an explicit **zero**, is the reader's own figure
and wins. The default is disclosed as an `assumed_figure` input note reading that constant, and only
on a plan that keeps the current home (`ResultPresenter::keepsCurrentHome`): a buy or rent variant
stripped the bucket, so a note about it would assert a cost its projection never charges. Source and
its open primary-citation gap: ASSUMPTIONS.md §12.

**Property-linked one-off costs (2026-09-05):** an `ExpenseProfile::$oneOffCosts` row carries an
optional `condition` of `while_owning_home`, making the lump a liability of owning the **current**
home (a Section 20 major-works demand on a block). It is then dropped by `withoutPropertyCosts()`
(the buy/rent variants) and skipped by the projector once the home is sold mid-projection, exactly as
the service charge is. Absent = charged always, the pre-existing behaviour. Builder key
`oneOffCosts.*.condition`, stored **sparsely**.

**Bought-home maintenance default (2026-07-08):** the buy variant's new home takes its
`runningCosts` from `HousingComparison::newHomeRunningCosts` — the current home's `runningCosts`
scaled pro-rata when it has them, else a standard **1%-of-value** home-maintenance default (the UK
rule of thumb, sourced in-code), so a freehold bought after selling a leasehold flat (whose upkeep
was inside its stripped service charge) is not modelled upkeep-free. A real `Property::runningCosts`
overrides it. See DECISIONS 2026-07-08. **Both derived branches are now on screen (card 0033):** the
1%-of-value fallback as an `assumed_figure` note, and the pro-rata scaling as a `computed_figure`
note stating the rule (the current figure, the two prices) and reading the engine's own answer, so
a number the reader never typed no longer looks like one they did.

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
house_growth_volatility (?Percent, null = deterministic) + house_equity_correlation (float, 0.2),
salary_growth_volatility (?Percent, null = deterministic) + salary_equity_correlation (float, 0.1)
(the two stochastic-growth pairs, 2026-07-18; null keeps that factor deterministic so old snapshots reproduce),
care_cost_real_growth (?Percent, null = flat-real care fees; shipped presets CPI+2% real, 2026-07-18 —
the projector compounds the sampled care fee above CPI to the year the spell falls; null keeps care flat so
old care snapshots reproduce),
is_default (bool). Shipped presets: FCA-derived (default), DMS/EGS-derived,
OBR/BoE-inflation-blended. A5: the projector splits a GIA's total return into this taxable
income (dividends, taxed yearly) + capital growth (CGT on disposal vs the account's
`unrealised_gain` cost basis); cash interest = the cash return, taxed as savings; ISA stays
tax-free. Mapper defaults a pre-A5 snapshot's yield to 2.0%.

**User-editable custom set (2026-06-29).** A scenario may tune the chosen preset's economic
figures into a derived custom set. The edits live in `builder_state` under
`assumptionOverrides`: a **sparse map** of `{ investmentGrowth, inflation, houseGrowth,
rentGrowth, salaryGrowth, incomeYield, careCostGrowth }` => percentage string, holding **only the figures the
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
assumption_set_snapshot 🔒 (frozen copy — results survive later default changes),
inputs_hash (the cache key), integrity_hash (the tamper-evident stamp).

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
  reproducible after the live set is edited. Two hashes, the same pair of jobs `threshold_results`
  does: `inputs_hash?` (sha256 of the effective builder-state + the frozen assumptions + the engine
  and tax-year stamps + mode/paths/seed: **the cache key**, so an unchanged scenario is handed its
  stored run rather than recomputing a 10,000-path Monte Carlo; `SimulationRunner::inputsHash()`),
  and `integrity_hash?` (**the tamper-evident stamp**, an app-key HMAC over that provenance *plus*
  every variant's stored result payload, written when the run completes; `SimulationRun::isIntact()`
  re-derives it and `scenarios:audit` reports any run that no longer matches). The mutable lifecycle
  columns (status, progress, timestamps, error) are deliberately outside the stamp, so cancelling
  a run is not mistaken for tampering. Both are nullable: a run predating them is never served as a
  cache hit and is reported as unverifiable rather than as altered.
- **results** — clear: `simulation_run_id`, `variant` (unique per run). Encrypted `payload`: the
  engine's `SimulationResult` (success probabilities, terminal-wealth percentiles, fan-chart
  bands). A buy-vs-rent run produces three (stay_put, buy_outright, rent) on identical seeds.
- **threshold_results** (decision-support Phase 1, 2026-07-05) — a computed lever threshold; both
  the queued run and its result in one row (a threshold is one computation). Clear: `scenario_id`,
  `user_id?`, `lever_key` (`buy_price|retirement_age|essential_spend`), `metric`
  (`essentials|full_spend`), `target_probability`, `n_paths`, `seed` (fixed, always recorded),
  `engine_version`, `taxyear_config_version`, `status` (reuses `SimulationStatus`), `progress_pct`,
  `inputs_hash` (sha256 of the effective builder-state + engine version + all compute params — the
  cache key), `started_at?`/`finished_at?`/`error?`. Encrypted: `grid` (the swept lever values),
  `assumption_snapshot` (frozen `AssumptionSet`), and `payload` (the mapped `ThresholdOutcome` =
  swept curve + crossing, null until done; `App\Finance\Mapping\ThresholdOutcomeMapper`). Invalidated
  on scenario edit exactly as `simulation_runs` are (deleted, cascade to children), with the
  `inputs_hash` as the belt-and-braces so a stale threshold is never surfaced. **A 2-D frontier
  (Phase 5, 2026-07-07) is the same row kind:** nullable `condition_lever_key` + encrypted
  `condition_grid` (the held values) discriminate it (null = 1-D threshold — the columns decide,
  never payload sniffing); both join the `inputs_hash`, and the payload is then the mapped
  `FrontierOutcome` (per held value: the crossing + its full measured curve), read via
  `frontierOutcome()` while `thresholdOutcome()` returns null (and vice versa for 1-D rows).

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
  The optional **per-asset overrides** — `DcPension::growthAssumptionOverride`, `Property::growthAssumptionOverride`
  and `Account::yield` — are now **consumed by `PathProjector`** (previously collected but ignored — a silent drop):
  a pot/home grows at its own real rate, a GIA distributes income at its own yield (per-person balance-weighted blend
  where multiple accounts differ), each falling back to the assumption-set default when unset (DECISIONS 2026-07-02).
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
  (bool), condition?, bands?}`, is the **single source** of spend. **(2026-07-06, the smile)** an optional
  `bands: list<{fromAge, amount(£ string)}>` gives a line an age-varying path — the base `amount` holds from the
  start, each band steps from its age; the assembler builds a `SpendPath` per line (`[{0, amount}, …bands]`) and
  sums them per-age into the `ExpenseProfile` essential/discretionary paths (reconciliation: aggregate == Σ line
  paths at every age). **Only an `always`-condition line may smile** (a contingent cost — mortgage/service
  charge/commute — is flat; a band on it is ignored, a flagged v1 limit). **(2026-06-29, #1)** an optional `condition ∈
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
  phased ("smile") spend (an engine change). **(2026-07-30):** every displayed budget figure gained a
  **monthly twin** (`amountMonthly` per line, plus `subtotalMonthly` / `spendingTotalMonthly` /
  `savingTotalMonthly`) — rounded **per line and then summed**, never re-divided at the total, so the
  monthly column reconciles to its own rows exactly as the annual one does.
- ✅ **BUILT (2026-07-30): the income side is echoed back like the spend side.** New
  `ResultPresenter::incomePlan(Household, ForecastResult)` — a **view-model only, no shape change** —
  returning `income` (entered sources: salary / DB / State Pension / annuity-rental-other / one-off
  receipts, each with owner, annual + monthly, taxable flag and its own start and stop), `capital` (cash /
  ISA / GIA / Premium Bonds `Account`s, DC pots and home equity, with what is paid in, when it can be
  reached and how it is taxed on the way out), `timeline` (per source: first year paid, last year, the
  amount at each end and its largest year — **derived from `YearResult::incomeBySource`**, so it reconciles
  with the cashflow ladder rather than restating inputs; a source that never pays is omitted), plus
  `hasSavings` (no cash/ISA/GIA entered at all is a materially different position from unlisted accounts).
  Rendered on both the results page and the PDF. See DECISIONS 2026-07-30.
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
  spendable (excl-home) money, the honest "will it last" series (gotcha P), with an include-home toggle.
  **Extended 2026-07-04:** `SimulationResult` also carries `netPositionFanChart` — the same excl-home series
  continued **below £0** by the cumulative unmet spend (`net = usable − Σ unmet`; assets can't go negative, so
  usable floors at £0 and a household that runs out reads as flat zero, while net position shows how deep the
  funding gap gets). Equals `usableFanChart` while solvent; nullable/empty for a run persisted before it existed
  (the presenter falls back to the usable fan). See DECISIONS 2026-07-04. Also added **`YearResult::incomeBySource`** (the
  canonical sources — now 10, incl. `means_tested_benefit`) powering the deterministic cashflow ladder + the
  per-source completeness guard. Phase C1 added **`YearResult::essentialSpend`** (real terms — the essential
  floor incl. rent/running costs and the survivor factor) so the income-floor readout reads one definition.
  **2026-07-01** added **`YearResult::investmentGrowth`** (nullable Money, real terms) — the year's CAPITAL
  appreciation left in the pots (share/fund growth; separate from the taxed `investment_income` paid out), so
  the ladder can show where wealth grows beyond income; `growState` returns it, deflated by next year's price
  level for the real purchasing-power gain (DECISIONS 2026-07-01). **2026-07-06** added **`YearResult::mortgageBalance`**
  (nullable Money, real terms) + derived **`homeEquity()`** (home equity NNEG-floored, mirroring
  `EstateValuer`) so an equity-release roll-up's compounding debt is visible (DECISIONS 2026-07-06).
  **2026-07-08 — `totalWealth` is NET (supersedes the gross definition):** `YearResult::totalWealth` is no longer
  a constructor input but **derived in the constructor** as liquid + pension + `homeEquity()` (property net of the
  mortgage, NNEG-floored); the then-identical `netWealth()` was removed. `terminalTotalWealth`, the Monte Carlo
  percentiles/fans and every display surface inherit it (labelled "incl. home equity"); gross property remains as
  the `propertyWealth` leg only. Guarded by `WealthReconciliationTest` (parts-sum invariant with + without a
  mortgage, and the debt provably reaching the terminal headline; DECISIONS 2026-07-08).
  **2026-08-22** added **`YearResult::$nominal`** (nullable `YearResult`): the same year in the projector's
  own PRE-deflation pounds, built in `projectYear` from the identical nominal integers and carried through
  `withInvestmentGrowth` (which attaches the growth/charges flows undivided). It is what the results page's
  nominal-pounds toggle reads, so a cash-pounds figure is the engine's arithmetic rather than a presenter
  re-inflating a rounded real figure. Null on a hand-built year, so a caller must check rather than label
  real money as nominal. Guarded by `NominalTwinTest` (identical with zero inflation; deflates back to the
  reported real figure with inflation; DECISIONS 2026-08-22). **Still app-side (not yet built):** the
  **PLSA benchmark** (→ C4).
- ✅ **BUILT (2026-06-29, adviser-legibility presentation layer).** Two small **additive engine** outputs feed the
  legibility layer, each a single source the app only reads: (1) **`ForecastResult::deathCalendarYears`**
  (`array<personId, int>` = birthYear + modelled death age, computed once in `PathProjector` from the draws; default
  `[]`) — the canonical "when does each person die", powering the milestones + input-sanity notes without
  re-deriving the death age; (2) **`Housing\HousingPurchase`** (a reconciled value object beside `HousingProceeds`) —
  the single source of the buy-side funding decomposition, read by `HousingComparison::buyVariant` and the
  sale-explainer. **2026-07-16 — the full funding identity, asserted in its constructor** (a non-reconciling
  decomposition cannot be constructed): `netProceeds + fundedFromReceipts + fundedFromSavings + mortgage +
  unfundedGap == buyPrice + stampDuty + movingCosts + surplus` (see the no-magic-money workstream below). The results-page **view-models** (`ResultPresenter::saleExplainer`
  / `assumptionsPanel` / `milestones` / `inputNotes`, plus the ladder's essential/discretionary split) are app-side
  presentation derived from these + the household — they add **no** persisted entity and **no** canonical-shape change.

## Forced-housing-event workstream (2026-06-30/07-01) — BUILT
For the rationale + the real-couple case that surfaced these: DECISIONS 2026-06-30 (forced-mortgage pressure-test;
input-expectation clarity) + 2026-07-01 (deferred-refinement resolutions) and docs/PLAN.md "Forced-housing-event
workstream". The canonical-shape additions below are now materialised (see `git log`); two details resolved differently
from the original plan, flagged inline:
- ✅ **(A) Means-tested benefits in the forecast.** Engine `Benefits\PensionCreditCalculator` (Guarantee Credit
  to the Standard Minimum Guarantee + Severe Disability / Carer additions; capital tariff via the existing
  `CapitalAssessment`). **`YearResult` gains an income source `means_tested_benefit`** (one of the canonical
  `YearResult::INCOME_SOURCES`, 11 since `capital_receipt` was added 2026-07-16 — completeness guard covers each). A **disability flag** is added to `Person` (or `Household`):
  e.g. `receivesDisabilityBenefit: bool` (+ a derived "severe disability" qualifier), driving the SDP and the
  DLA/AA passport. The benefit is a **household-level** credit computed each projected year from that year's
  assessable income + assessable capital (liquid wealth, home excluded), so it erodes/restores dynamically and
  fires the £16k Housing/Council-Tax-Support cliff in-projection. CTR itself stays out (locally set) — modelled as
  the cliff/passport, flagged. **Refinement (2026-07-01):** a `Property::isLet` flag means a **let** home's equity
  joins assessable capital (it is no longer the exempt main residence) — so "let out & rent" erodes benefit like a sale.
  **Correction (2026-07-29, DECISIONS):** the severe-disability addition now follows the household rule — a single
  disabled pensioner (single rate) or a couple where **both** partners receive a qualifying disability benefit
  (couple rate = 2× single); one disabled partner in a couple no longer wrongly triggers it. New engine field
  `Person::caresForPartner: bool` wires the **carer addition** (a partner caring for a disabled partner); it
  defaults false and is **not yet a builder input** (app-UI exposure deferred), so no stored scenario changes.
- ✅ **(B) Mortgage redemption.** `Property` gained `mortgageRedemptionYear: int?` and `mortgageMaturityAction:
  enum {refinance | repay_from_capital | forced_sale}`. The projector tracks the mortgage **balance** (new state) and
  applies the action at maturity. Stopping the bundled mortgage *payment* after a repay is **built** (the
  `while_mortgaged` expense condition + `ExpenseProfile::mortgageCosts`, DECISIONS 2026-07-01). **`forced_sale` is now
  modelled in place (2026-07-03):** the projector sells at the redemption year (new state `homeSold` + `propertyWhole`),
  frees the net proceeds into GIA via the shared `HousingProceeds::compute`, stops the housing costs and charges the
  entered rent from then on. The post-sale rent + selling-cost basis ride on the new `ForecastSettings::$sellingCosts`
  (+ `annualRent`/`rentInflationReal`), populated by `ScenarioForecaster::settings()` for a ForcedSale scenario only,
  since the projector has no `HousingAction` (DECISIONS 2026-07-03). *Open:* the one-off **path scope** field.
- ✅ **(C) Feasibility** is a **derived** result note (no stored field): `HousingComparison` exposes the purchase's
  funding decomposition, surfaced by `ResultPresenter`. **Buy-with-a-mortgage (2026-07-03):** `HousingAction` gained
  `buyMortgageRate: Percent?` (builder `housing.buyMortgageRate`). **Superseded by the funding waterfall
  (2026-07-16, no-magic-money):** a buy above the proceeds is funded receipt-first (**card 0034, 2026-09-05**: a
  `CapitalReceipt` dated the base year is spent on the purchase before anything else, reported as
  `HousingPurchase::$fundedFromReceipts`, and the buy variant carries the receipts REDUCED by what it spent so the
  same pound is never both spent and banked), then savings (cash+Premium Bonds → GIA → ISA,
  never pensions — `Housing\SavingsFunding`, the drawn accounts actually reduced in the variant household), then the
  RIO mortgage takes the *post-savings remainder* (interest via `ExpenseProfile::withMortgageCosts`); anything left is
  `HousingPurchase::$unfundedGap`, charged as a year-0 one-off cost (`ExpenseProfile::withOneOffCost`) so the plan
  visibly fails (`unmetSpend` > 0) instead of being handed the home for free. A GIA draw realises its pro-rata gain
  (`Household::$realisedGainsAtStart`) and the projector charges the CGT in year 0, sharing one AEA with any in-year
  disposal (DECISIONS 2026-07-16).
- ✅ **(D) Input clarity** is mostly **builder-state / UI**, not canonical-shape: a per-input **pay frequency** is a
  form concern (stored annual, so the DTO is unchanged). The tax-free-benefit type was **upgraded** from the planned
  `IncomeStream{type: other, taxable: false}` to a first-class `IncomeStreamType::DisabilityBenefit` (structurally
  tax-free — see IncomeStream above + DECISIONS 2026-07-01). The planned `endsOnSale` property-link is **declined**
  (single-property model — DECISIONS 2026-07-01).

## Known divergences (to close)
- **CLOSED 2026-08-22 — a resident's Pension Credit is counted into the care contribution**
  (card 0015, DECISIONS 2026-08-22). Guarantee Credit is assessable income for the care financial
  assessment, but it is tax-free, so it never reached the taxable figure the means test read: the
  household banked the award as income and was never charged it back. The care leg of `PathProjector`
  now adds the household award split per living member to each resident's assessable income, so a
  funded resident's credit is a wash rather than a windfall. No data-shape change. **Still open:** a
  couple's award is not re-computed as two single awards on a permanent placement (two singles get
  more than a couple), and the severe-disability addition is not withdrawn on one.
- **CLOSED 2026-08-22 — CGT deemed-occupation absences are entered as such and relieved**
  (card 0015, DECISIONS 2026-08-22). A qualifying absence used to be relieved only by the reader
  marking it "main home" and applying the statutory cap by hand. `CgtHistory` gained
  `absenceAnyReasonMonths` / `absenceWorkElsewhereUkMonths` / `absenceWorkAbroadMonths` (all
  defaulting to 0, byte-identical to before), carrying raw months from three new period kinds on the
  wizard's occupation timeline; `CgtParameters` gained the statutory caps (36 and 48 months, work
  abroad uncapped) and `CgtPrivateResidenceCalculator` applies them, reporting what survived as
  `CgtResult::deemedOccupationMonths` so the wizard can show it. `HouseholdAssembler` owns the one
  test only a timeline can answer: the home must have been the main residence before the absence, and
  been returned to after it, the return excused for the two work absences. **Still open:** the rule
  that no other residence may be eligible for relief during the absence (the engine models one home),
  and job-related accommodation.
- **CLOSED 2026-07-31 — an employer's death-in-service cover is now modelled, and so is the moment it
  ceases** (adviser-parity B2, DECISIONS 2026-07-31). `Person::$deathInServiceCover`
  (`?DeathInServiceCover`; null = no cover, the adverse default and byte-identical to before) pays a
  lump sum to the surviving partner when the member dies **while still in employment**, sized either as
  a multiple of the salary in the year of death or as a fixed (nominal) sum assured. Tax follows the
  registered-scheme rules verified against HMRC PTM073010: **tax-free under 75 up to the member's
  remaining lump sum and death benefit allowance**, taxable as the recipient's income above it and for a
  death at **75 or over**; the taxable slice runs through the engine's single income-tax pass. It is
  **outside the estate for IHT** (registered-scheme death-in-service benefits are excluded, including
  under the April-2027 pensions-in-estate rule), and it is **capital, not income, for Pension Credit**
  (so the payout can extinguish a survivor's Guarantee Credit through the capital tariff — a real effect
  the forecast now shows). New `YearResult::INCOME_SOURCES` key `death_in_service`, shown gross.
  **Still open:** the 45% special lump sum death benefits charge for a non-qualifying recipient (a
  trust) is not modelled — only a surviving partner is; excepted group life policies (outside the
  registered-scheme regime, so no LSDBA test) are not distinguished; and cover is not carried into a
  new employment after a job change, because the engine has no concept of one.
- **CLOSED 2026-07-31 — investment returns are no longer gross of charges** (was the largest open correctness
  gap, found 2026-07-29; A1 of [docs/build/PLAN-adviser-parity.md](build/PLAN-adviser-parity.md)).
  `AssumptionSet::$investmentCharge` (`?Percent`; null = no charge, byte-identical, so a stored run
  reproduces) carries the annual platform + fund ongoing charge, reaches the projector through
  `PathDraws::investmentChargeRate()` (all three drivers, so deterministic and Monte Carlo agree), and
  `PathProjector::growState` deducts it from each invested balance **after** growth. Charged: DC pots, ISAs,
  GIAs. **Not charged: cash deposits** (no platform or fund fee) or the home. Shipped at **0.50%** across all
  presets, sourced in [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) §10, and exposed as the 8th editable
  economic assumption. `YearResult::$investmentCharges` reports what the charge cost in pounds each year, and
  `$investmentGrowth` stays **gross** of it, so opening balance + growth − charges reconciles to the closing
  balance and the charge is visible rather than a quietly smaller growth line. **Still open:** no per-account
  or per-pot charge override (one household-wide rate), and the *advised* cost stack (ongoing advice fee) is
  the separate B1 comparison, not modelled.
- **CLOSED 2026-07-31 for net pay — pension contributions now get tax relief** (adviser-parity A2,
  DECISIONS 2026-07-31). `DcPension::$reliefMethod` (`?PensionReliefMethod`; null = relief not modelled,
  the back-compat default, and surfaced as a `no_relief_method` input note). **Net pay** is modelled by
  subtracting the contribution from gross earnings before both the income-tax pass and the spendable
  total — so relief is given at the member's marginal rate through the engine's single tax pass, with no
  parallel calculation to drift, and NI is correctly unaffected. Capped at pay, so it stops when the
  salary does. **`ReliefAtSource` THROWS** rather than silently giving no relief; it is unbuilt.
  Two structural defects fixed with it: the **employer's** contribution is no longer funded from
  household surplus (it is credited while the member works, prorated in a part-year, and could
  previously be silently dropped in a year with no surplus), and contributions no longer run for ever
  after retirement. **Still open:** salary sacrifice is unmodelled (adviser-parity A4).
- **CLOSED 2026-08-22: contributions are capped, and a non-earner has a relief route** (the half of
  adviser-parity A2 left open on 2026-07-31; DECISIONS 2026-08-22). `PathProjector::payIntoPot` is
  the one place a DC pot is credited, and its cap is now `contributionHeadroom()`: the **annual
  allowance** (£60,000) less what has gone in this year, replaced by the **MPAA** (£10,000) once the
  member has flexibly accessed a pension. Both count the employer's contribution, because the
  statutory limit is measured on total pension input. What the cap blocks stays in pay and is taxed
  there (net pay) or stays in savings (the surplus-funded route); an **employer** contribution it
  blocks is lost, because that money never passes through the household's cashflow — see "Still open"
  below (this line used to say nothing was dropped, corrected 2026-08-23 by card 0007).
  New `PensionReliefMethod::NonEarner` models the statutory
  **basic amount**: the member pays £2,880 out of household surplus, the provider adds basic-rate
  relief, and £3,600 reaches the pot; new `PensionParameters::$nonEarnerReliefLimit` (£3,600) and
  `$reliefMaximumAge` (75) own both figures. `ContributionAllowanceTest` guards it. **Still open:**
  the cap is a hard limit on what may be paid in rather than an annual-allowance **charge** on the
  excess (`AnnualAllowanceCalculator` prices that separately and is still not wired in — card 0073);
  in the MPAA **trigger year** whether the cap bites depends on which of the three contribution routes
  the money took rather than on the date, an artefact of `projectYear`'s order (employer and net-pay
  are paid before the withdrawals that set the trigger and escape it; the surplus-funded route runs
  after and is capped — card 0073); an EMPLOYER contribution the cap blocks is **not paid anywhere
  else**, because it never passes through the household's cashflow, so the plan simply loses it (the
  adverse side, card 0073 again); carry-forward of unused allowance is not tracked (the cautious side
  of the rule); the high-income **taper** is not applied in the projector, because it needs adjusted
  and threshold income which the year's own contributions move; and `ReliefAtSource` still throws, so
  a higher-rate taxpayer wanting relief above the basic amount must use net pay.
- **CLOSED 2026-08-23: a fill-the-bands pension draw is a UFPLS, and tax-free cash crystallises what
  it leaves behind** (card 0007, DECISIONS 2026-08-19). An ad-hoc draw to meet a shortfall under
  `DrawdownStrategy::FillBands` used to be taxed on 100% of the gross; it is now split 25/75 while the
  Lump Sum Allowance lasts (`PathProjector::ufplsSplit`, capped by `maxUfplsGross` and `lsaHeadroom`).
  Pots carry a **`crystallised`** balance, so money that has already had its tax-free quarter cannot
  get a second one: a planned `WithdrawalKind::Pcls` crystallises cash / 25% of the pot, the residue is
  drawn first and taxed in full, and it grows with the pot so the share holds. An **inherited** pot
  has neither a quarter nor any of the heir's allowance to spend, and drawing it is not a flexible-access
  trigger for the heir. **Still open:** a **starting** pot is assumed wholly uncrystallised, because
  `DcPension::$pclsTakenToDate` is an allowance ledger across all of the member's pensions rather than a
  per-pot crystallisation record, so a reader who has already taken tax-free cash is given a second
  quarter of it (card **0080**); the tax-free part of an **ad-hoc** UFPLS is reported on the cashflow
  ladder under `pension_drawdown` rather than `pension_lump_sum`, because `fundShortfall` returns one
  `fromPension` total, so the money is visible and the year reconciles but a reader adding up taxable
  income off the ladder gets too big a figure (card **0074**); a draw from an inherited pot is taxed in
  full even where the member died **under 75**, when in life it is tax-free income, because
  `PathProjector::settleEstates` stores no age at death (card **0079**); and no ad-hoc draw, taxed or
  tax-free, reaches the Pension Credit means test, which is assessed before the shortfall is funded and
  never written back (card **0077**); and the panel that names the **cheapest draw order** ranks them on
  the tax the plan pays (now including the tax paid at death, DECISIONS item 14), which is only the right
  measure while every order funds the same spending — an order that runs out stops drawing, so it stops
  paying, and could be named cheapest while funding the least (card **0081**).
- **SUPERSEDED — pension contributions get no tax relief (flagged in code since v1).** `PathProjector::applyContributions`
  takes contributions from *net* surplus and adds no relief (see its own docblock), so the pot grows as if
  relief did not exist — understating a still-working household's accumulation and rigging any
  pension-vs-ISA comparison against the pension. Relief method matters (`net_pay` / `relief_at_source` /
  `salary_sacrifice`) and none is modelled; salary sacrifice's NI saving is absent entirely. Must bind to
  the existing annual-allowance + MPAA machinery when built. Specced as A2/A4 of the same plan.
- **CLOSED 2026-07-31 — the ISA overall subscription allowance is enforced** (adviser-parity A3,
  DECISIONS 2026-07-31). New `IsaParameters` in the tax-year registry (£20,000 overall per person per
  year, sourced + `verified_on`, plus the dated April-2027 cash-ISA cut it also carries).
  `applyContributions` now caps ISA subscriptions **per person, per year** and **spills the excess into
  that person's GIA** — the household still saves the money, it just saves it somewhere taxable, which
  is what happens in reality. Dropping the excess would have been a completeness failure (a real input
  that stops counting) and would have made the household look poorer rather than more taxed. The cap
  applies to money paid **in**, never to what the wrapper already holds. Guarded by
  `IsaSubscriptionCapTest`, **verified to fail** with the cap removed.
  **Correction to the earlier note here (which overstated this, and pointed the wrong way):** it claimed
  the bias was "largest for the sell-and-invest housing variants". It is not. A sale's proceeds are
  invested into a **GIA**, not an ISA (`HousingComparison::withHousing`), and ordinary surplus banks to
  **cash** — so no housing variant ever sheltered a penny through the missing cap. The gap only ever
  bit on an explicitly-entered ISA `ongoingContributions` above £20,000/yr, which no stored scenario
  has. Measured on the real household before building: peak liquid wealth £136k–£150k and lifetime
  investment income £32k–£38k, most of it inside the personal savings and dividend allowances.
  The April-2027 22% charge on cash held inside a stocks-and-shares ISA has
  nothing to bite on (an ISA is modelled as one invested balance, with no cash sleeve); the April-2027
  cash-ISA cut does not apply to a 65+ saver.
- **CLOSED 2026-08-22: the ISA allowance is now USED as well as enforced** (the larger half of
  adviser-parity A3, and the last of it; DECISIONS 2026-08-22). `PathProjector::bedAndIsa()` runs
  after each year's contributions and disposals and moves money the household already holds in a
  taxable GIA into their ISA, up to whatever is left of each person's £20,000 allowance. **The
  allowance is one allowance**: `$state['isaSubscribed']` is shared with money paid in, so it cannot
  be spent twice. The move is a **disposal**, so it realises the pro-rata gain and consumes the
  matching cost basis, and it is sized to keep that gain inside what is left of the person's CGT
  annual exempt amount, which is the discipline a real bed-and-ISA follows and means the step never
  adds a tax bill the projection would then have to fund. It runs in shortfall years too, because a
  sell-and-invest plan has a shortfall in almost all of them and that is exactly the plan this
  shelters. `YearResult::$isaSheltered` reports what moved, and the results page discloses it as an
  `assumed_figure` note read out of the forecast, because it is an **action** nobody entered rather
  than a blank input filled in. **It is ON by default** (`ForecastSettings::$useIsaAllowance`, from
  the builder-state key `useIsaAllowance`) because leaving it out understated exactly the plans this
  tool exists to compare. Guarded by `BedAndIsaTest`. **Still open:** no builder control turns it off
  yet (the engine and the scenario key take it, the UI does not offer it); and when a drawdown year's
  disposals have already spent the exempt amount, nothing moves that year, so spending has first
  claim on the allowance rather than sheltering.
- **A bought home can now carry its own cost and its own (possibly NEGATIVE) growth — CLOSED
  2026-07-30 (DECISIONS 2026-07-30).** `HousingAction::$buyRunningCosts` + `$buyGrowthOverride`. The
  bought home previously always appreciated at the assumption-set house rate with running costs derived
  as 1% of value, so a **park home** (flat pitch fee, depreciating) could not be modelled at all and
  looked strictly better than it is. Both null = byte-identical to before
  (`DepreciatingHomePurchaseTest`). **Still open:** the up-to-10% resale commission a site owner takes
  is not modelled (it bites only on an actual resale, e.g. a forced sale for care), and above-CPI pitch
  drift via "agreed park improvements" is not modelled.
- **OPEN — `usableWealth` counts pre-tax pension money as cash (found 2026-07-30).** The ladder's
  `usableWealth`, the burndown chart and the safety-buffer check all use `liquidWealth + pensionWealth`,
  so £100,000 of pension is treated as £100,000 available when drawing it is taxable (worth perhaps
  £75–85k in the hand). It **overstates available capital and understates depletion risk** for
  pension-heavy plans, and because it drives the "below buffer floor" warning, that warning **fires
  later than it should**. Not fixed by the 2026-07-30 spendable-view work, which deliberately does NOT
  reuse it (`availableCapital` is `liquidWealth` only, with pension carried separately and labelled
  taxable). Fixing it moves existing reported figures and needs its own decision on how to net the tax.
- **A repayment mortgage now amortises — CLOSED 2026-07-29 (DECISIONS 2026-07-29).** The balance of a
  capital-and-interest mortgage was modelled **static** (the workaround was `mortgageRedemptionYear` +
  repay-from-capital, which yanks the whole balance out of capital in one year), and its payment was an
  ordinary expense line — so the model inflated a contractually fixed instalment with CPI, shrank it by the
  survivor factor on a death, never stopped it at the end of the term, and understated net wealth and the IHT
  estate by every pound of capital repaid. `Property::$repaymentTerms` + `AmortisationSchedule` now model it
  properly (monthly amortisation, nominal-rate/12, payment recomputed at each rate tier, final instalment
  trued up to land on zero). Pinned to a real lender illustration: the LiveMore ESIS of 29 July 2026 (£160,000
  / 192 months / 6.23% for 60 then 7.24%) reproduces **to within 21p at any row over 16 years**, with both
  monthly instalments (£1,318.54, £1,384.65) exact — `AmortisationScheduleTest`, `RepaymentMortgageForecastTest`.
  Null terms = byte-identical to before. **Still open:** lender fees, early-repayment charges and the
  10%/yr penalty-free overpayment allowance are not modelled (an overpayment on an amortising loan has no
  input — `mortgage_overpayment` applies only to a roll-up).
- **House-price AND salary growth in the Monte Carlo — both stochastic since 2026-07-18 (DECISIONS 2026-07-18).**
  Each was deterministic (a straight line at the mean): house growth understated the risk of home-heavy plans,
  salary growth understated the spread of a still-working couple's accumulation. `AssumptionSet` now carries
  `houseGrowthVolatility` + `salaryGrowthVolatility` (each `?Percent`; null = deterministic, the back-compat
  default) with their equity correlations (`houseEquityCorrelation` 0.2 / `salaryEquityCorrelation` 0.1, floats);
  `ReturnModel` draws a per-year shock for each correlated to the equity shock, `SampledPathDraws` reads them
  (the salary shock drawn last, so a null-salary set consumes no extra draw and every stored run reproduces
  byte-identically). Completeness-tested (`StochasticHouseGrowthTest`, `StochasticSalaryGrowthTest`: each spread
  widens, collapses to the mean at zero vol, reproduces under a seed). No MC-growth-determinism divergence remains.
- **Care fees now escalate above CPI (A1, DECISIONS 2026-07-18).** The engine draws one CPI series and models
  most costs as a real spread; care was left riding flat CPI, understating the tool's headline late-life risk.
  `AssumptionSet::careCostRealGrowth` (`?Percent`; null = flat-real, back-compat; shipped presets CPI+2% real)
  now compounds the sampled self-funder fee above CPI to the year the spell falls, mirroring
  `ExpenseProfile::propertyCostsRealGrowth` (`CareCostInflationTest`).
- **Care now appears in the deterministic path as an adverse stress (A2, DECISIONS 2026-07-18).** Care remains
  absent from the care-free central estimate (still a probabilistic risk in the Monte Carlo), but a labelled
  "if significant care is needed" stress runs beside it: `DeterministicPathDraws` accepts injected
  `CareEpisode`s (empty = byte-identical care-free path), and `DeterministicForecaster::forecastWithCareStress`
  places one adverse ~4-year nursing spell (`CareStressScenario`) on the last-surviving partner. The
  Affordability screen shows both, so "lasts for life? Yes" is never rendered against a silently care-free
  path (`DeterministicCareStressTest`, `AffordabilityTest`). **Still open:** a care-stress params UI, a
  probability-weighted "typical" option, and the stress line on the main results ladder.
- **Collected-but-not-consumed fields (2026-07-02 doc audit) — ALL CLOSED 2026-07-02/07-03.** Inputs that
  were validated, assembled into DTOs and documented above but read by no engine code — a silent-drop class
  (see the completeness rule in CLAUDE.md), all now wired with a per-source completeness test:
  `DbPension::spousePensionFraction` (survivor DB income now paid; `SurvivorDbPensionTest`),
  `Person::salaryGrowth` (per-person salary-growth override; `PerPersonSalaryGrowthTest`),
  `DbPension::commutationLumpSum`/`commutationFactor` (the commutation trade-off; `DbCommutationTest`),
  `Person::niCategory` (employee NI now category-aware, sourced B/E/I reduced + D/J/L/Z deferred + C/K/S/X
  nil rates; `NationalInsuranceCalculatorTest` / `NiCategoryForecastTest`), and `Property::ownershipShare`
  (beneficial share scales wealth, means-test, IHT + sale proceeds/CGT per HMRC tenants-in-common
  apportionment; `OwnershipShareTest`).
- **`Scenario::iht_modelled` / the IHT toggle — CLOSED 2026-07-04.** Was collected-but-unconsumed (stored,
  validated, shown in diffs + GDPR export, but no forecast read it). Now wired: `ForecastSettings::modelIht`
  drives `PathProjector` to value the estate at each death (via the new `Iht\EstateValuer` = liquid + home
  equity, pensions separate) and compute the IHT due (`InheritanceTaxCalculator`), surfaced on
  `ForecastResult::iht` (an `Iht\IhtOutcome`). Two new inputs land with it: **`Household::relationshipStatus`**
  (married/civil-partner vs cohabiting, default married — drives the first-death spousal exemption + the
  final-death transferable nil-rate band ×2) and **`ForecastSettings::homeToDescendants`** (builder toggle,
  default on; unlocks the residence nil-rate band). Computed in nominal pounds at the death year (frozen bands
  bite = real fiscal drag), deflated to real; pensions enter the estate only from April 2027. Completeness-
  tested (`InheritanceTaxForecastTest`: the toggle bites, relationship status changes it). See DECISIONS 2026-07-04.
- **Planned fields never materialised:** `DcPension::crystallisedValue`,
  `StatePensionEntitlement` `spa_override` + `triple_lock_assumption` (SPA computes from DOB;
  the triple-lock factor lives in the projector). Kept here rather than in the entity tables
  so the tables describe only what exists.
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
