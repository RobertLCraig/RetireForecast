# UK Retirement / Downsizing Forecast Tool — Implementation Plan

## Context

Rob wants a UK financial-forecasting **decision-support tool**. The flagship worked example: an older couple (one still working, one retired) deciding whether to sell their home and either **buy somewhere cheaper outright** (no mortgage, invest the surplus) or **sell and rent** (hold all proceeds invested). The tool must make the consequences of **pension lump-sum withdrawals** visible (the tax shock most people walk into) and forecast whether the money **lasts for life** via Monte Carlo simulation.

The problem it solves: people in this situation make irreversible six-figure decisions (drawing a pension pot, selling a home, signing a tenancy) on gut feel, and the tax and benefit traps are invisible until it is too late. The intended outcome is a tool that lets them drive their own numbers and *see* the consequences, framed as **education/guidance, never regulated advice**.

### Locked decisions
- **Stack:** Laravel (latest stable) + Livewire 3, Filament for admin, Fortify for auth, Redis + Horizon for queues. _(Superseded 2026-06-25: the build runs **Livewire 4 + Filament 5 + SQLite + db/sync queue** — Filament 5 pulled Livewire 4, and a local-first single-user app needs no Redis/Horizon. See DECISIONS 2026-06-25 "Rebuild … ratify LW4+SQLite".)_
- **Accounts:** optional. Anonymous use writes nothing server-side; logged-in users can save scenarios. Stored financial PII is **encrypted at rest**, with **GDPR export + delete** from day one.
- **Modelling:** HMRC-accurate deterministic engine **plus** Monte Carlo (return uncertainty, inflation, sequence-of-returns risk).
- **Pensions:** model DC (flexible access), DB (final salary), and State Pension.
- **Housing:** model **buy-cheaper-outright vs rent** side by side, on identical simulated paths.
- **Longevity:** **stochastic joint-life mortality** (ONS cohort life tables, last-survivor; income and costs shift on first death).
- **Legacy/IHT:** **in, as a toggle** (terminal estate + IHT, including unused pension pots entering the estate from April 2027). Not the main story.
- **Assumptions:** several **sourced** assumption sets (FCA-derived default, DMS/EGS-derived, OBR/BoE inflation), user-selectable at runtime with a **compare-assumptions overlay**. This is a display choice, not baked into the engine.
- **Headline pitfalls:** (1) the lump-sum tax shock, (2) running out of money. Secondary but modelled: MPAA, benefit cliff edges, IHT, care costs.
- **Build scope:** the full phased plan is one deliverable (internally phased for a sane build order).
- **Regulatory posture:** generic guidance only. No output ever phrases a personal recommendation.

### Design ethos (Rob's standing rules, applied here)
Data-shape-first. No silent failure (every op reports in-progress / succeeded / failed-with-reason; long runs show live progress). No magic numbers (every assumption and tax figure carries a source + verified-on date). Engine is framework-free and unit-tested in isolation. Accessibility (WCAG 2.1 AA) is a hard constraint, not a polish item.

### Two corrections the research turned up (the brief was stale)
- Income-tax threshold freeze now runs to **April 2031**, not 2028 (Autumn 2025 Budget).
- **Dividend tax rates rise in 2026/27** (ordinary 8.75 to 10.75%, upper 33.75 to 35.75%; additional 39.35% unchanged). So 25/26 and 26/27 are distinct config years.

---

## Project location and docs
- New project at `C:\Dev\RetireForecast` (working name, brandable later).
- First action after approval: run **scaffold-docs** to lay down PRD.md, DATA-MODEL.md, DECISIONS.md and the root CLAUDE.md orient tripwire, then port this plan's Context, Data Model and Decisions into them. HANDOVER.md via the handover skill at the first checkpoint.

---

## Architecture

### Engine as a framework-free package
```
/packages/finance-engine          # the product. ZERO Laravel dependencies.
  /src
    /Money            # Money value object over brick/money (integer pence, GBP)
    /Tax              # IncomeTax, Ni, DividendTax, SavingsTax, Cgt, Sdlt
    /Pension          # Pcls, Ufpls, Drawdown, EmergencyTax, AnnualAllowance, Mpaa
    /Benefits         # PensionCredit, CapitalAssessment
    /Iht /Care
    /Mortality        # CohortLifeTable, JointLifeSampler (last-survivor)
    /Forecast         # YearStepper, CashflowProjector, Household state
    /MonteCarlo       # ReturnModel, PathGenerator, Simulator, ResultAggregator
    /TaxYear          # TaxYearConfig (immutable), TaxYearRegistry, RegionProfile
    /Assumptions      # AssumptionSet, sourced presets (FCA / DMS / OBR)
    /Dto              # readonly value objects shared by engine + storage + UI
  /tests              # pure PHPUnit/Pest, no Laravel bootstrap
  composer.json       # standalone, path-repo'd into the Laravel app
```
The engine never touches the container, DB, or the clock. Inject `TaxYearConfig` and a `Clock` interface; never call `now()` inside it. This is what makes the HMRC worked-example tests and the Monte Carlo golden-master trustworthy.

### Money
- `Money` value object wrapping **brick/money**, integer **pence**, GBP only. No PHP `float` anywhere in tax/cashflow arithmetic. Floats are allowed only inside the return-draw layer of the Monte Carlo, converted to `Money` at each year boundary with an explicit rounding rule.
- Rounding conventions differ per tax (income tax, SDLT, CGT each round differently). Encode rounding **per calculator**, each documented with its gov.uk source and tested.

### Persistence and encryption
- Canonical shape lives in the engine's `/Dto`. Eloquent models map to/from DTOs; Livewire binds to form objects that hydrate DTOs. **One shape, three consumers.**
- Store **one encrypted JSON payload per scenario** (Laravel `encrypted:array` cast) rather than ~30 encrypted columns (encrypted columns are unindexable anyway). Keep only non-sensitive structural columns in the clear for listing/filtering: scenario name, region, base tax year, status, timestamps, owner id.
- GDPR: export returns all of a user's data; delete is a hard row delete. Document `APP_KEY` rotation.

### Charts (accessibility is mandatory, not optional)
- **ApexCharts** for the Monte Carlo fan chart (native banded range series for the 10/25/50/75/90 percentiles) and the buy-vs-rent comparison.
- Every chart ships with a visually-hidden, fully-populated `<table>` data equivalent (the accessible source of truth), a "download CSV" action, no meaning-by-colour-alone (label the median, dash/texture the outer bands, 3:1 / 4.5:1 contrast), and respects `prefers-reduced-motion`. Headline numbers (success probability, terminal-wealth percentiles, the tax shock) render as text/HTML first, never only inside the canvas.

---

## Data model (canonical shape)

> **⚠️ Original planning shape (2026-06) — [DATA-MODEL.md](../DATA-MODEL.md) is the current canonical
> description.** Since this was written: the `builder_state` storage inversion + delta-child what-ifs
> replaced `encrypted_payload` (households table dropped); ExpenseProfile gained 3-tier line items with
> conditions (`always`/`while_owning_home`/`while_mortgaged`/`while_working`); Property gained `is_let`,
> the mortgage-maturity event and `CgtHistory`; DcPension gained `AnnuityPurchase` and the consumed
> `growthAssumptionOverride`/`earliestAccessAge`. Build against DATA-MODEL.md, not this sketch.

Units everywhere: money = integer **pence**; rates = a `Percent` value object (basis points, no float drift); dates = ISO `Y-m-d`; **ages derived from DOB + a reference date, never stored**. Legend: 🔒 = encrypt at rest, `?` = nullable.

- **Household** — id, name, region enum(`england_wales` default / `scotland` / `ni`), persons (1–2), primary_residence_id?, created_by_user_id? (null = anonymous).
- **Person** 🔒 — dob, employment_status enum, gross_salary?/yr, salary_growth?, ni_category?, planned_retirement_age?, state_pension_deferral_weeks (default 0), sex_for_mortality (for life table).
- **Pension** 🔒 (single table, subtype-discriminated):
  - common: id, person_id, subtype(`dc`|`db`|`state`).
  - **DC:** current_value, ongoing_contributions/yr, employer_contributions, growth_assumption?, pcls_taken_to_date (LSA tracking), crystallised_value, access_age (55, rising to 57 from Apr 2028), intended_withdrawals (WithdrawalPlan[]: PCLS vs UFPLS vs drawdown, amounts, ages).
  - **DB:** accrued_annual_pension/yr, normal_retirement_age, revaluation_basis enum (pre-retirement), escalation_in_payment enum (post-retirement, distinct from revaluation), commutation_lump_sum?, commutation_factor?, spouse_pension_fraction? (survivor benefit, matters for joint-life).
  - **State:** weekly_entitlement?/wk (or qualifying_years?), spa_override? (normally computed from DOB), triple_lock_assumption enum.
- **Property** — current_value 🔒, ownership enum(outright|mortgaged), outstanding_mortgage 🔒?, is_primary_residence (PRR / capital-exemption flag), ever_let (default false, triggers PRR restriction), ownership_share (default 100%), running_costs 🔒? (maintenance + insurance + council tax), growth_assumption?.
- **Account** — owner_person_id, type enum(isa|gia|cash|premium_bonds), balance 🔒, unrealised_gain 🔒? (GIA, for CGT), yield?, derived is_assessable_capital (home excluded).
- **IncomeStream** — owner_person_id, type(rental|annuity|other), gross_amount 🔒/yr, taxable, inflation_linked, start_age, end_age?.
- **ExpenseProfile** — target_annual_spend 🔒/yr, essential_portion 🔒 (the floor for "success"), discretionary_portion 🔒, inflation_basis enum, one_off_costs (OneOff[]: care, SDLT, etc.), survivor_spend_factor (spend change on first death, default ~0.7).
- **Scenario** — household_id, name, variant(buy_outright|rent|stay_put), housing_action{sell_price, buy_price?, rent_pa?, rent_inflation?}, withdrawal_decisions[], assumption_set_id, region, base_tax_year, iht_modelled (toggle), encrypted_payload 🔒.
- **AssumptionSet** — name, source_note, asset_classes[{name, expected_real_return, volatility}], correlation_matrix, inflation_mean, inflation_vol, salary_growth, house_price_growth, rent_inflation, is_default. Shipped presets: FCA-derived (default), DMS/EGS-derived, OBR/BoE-inflation-blended.
- **SimulationRun** — scenario_id, mode(preview|full), n_paths, seed (null = random, set = reproducible, always recorded), horizon (joint-life), status(queued|running|done|failed), progress_pct, engine_version, taxyear_config_version, **assumption_set_snapshot 🔒** (frozen copy, so results survive later default changes).
- **Result** — simulation_run_id, success_probability (essentials-met and full-spend, both reported), terminal_wealth_percentiles{p10..p90}, depletion_age_distribution, yearly_percentile_bands[] (fan chart), first_year_tax_breakdown 🔒 (the lump-sum shock), estate_value/iht_due? (if toggle on), warnings[] (cliff-edge hits, MPAA triggered, emergency tax, capital crossed £16k).

---

## Tax / rules engine — UK rule set (2025/26 and 2026/27)

One immutable `TaxYearConfig` per tax year via `TaxYearRegistry::for('2025-26')`; region income-tax bands from a `RegionProfile`. **Every figure carries a `source` URL and `verified_on` date.** A build-time job confirms each ⚠️ item against gov.uk before go-live. **Figure-verification pass completed 2026-06-27** (Phase D Tier-1 trust gate): every statutory figure below was re-confirmed against gov.uk and is now stamped `verified_on: 2026-06-27`; no figure changed (all were already correct). See DECISIONS 2026-06-27. ✅ = web-verified.

**Income tax (England/Wales/NI default):** PA £12,570 ✅ (taper £1/£2 over £100k, gone by £125,140 ✅); basic 20% to £50,270 ✅; higher 40% to £125,140 ✅; additional 45% above ✅; **freeze to April 2031** (corrected). Ordering: non-savings then savings then dividends.

**Scotland (flag, region-pluggable):** own bands/rates on non-savings, non-dividend income; savings/dividend/NI/pensions stay UK-wide. Ship rUK first. **If region=scotland and no Scottish config loaded, throw, never silently apply rUK bands.** ⚠️ verify Scottish bands at build.

**Savings and dividends:** PSA £1,000 basic / £500 higher / £0 additional ✅; starting-rate-for-savings £5,000 at 0% tapered ✅; dividend allowance £500 ✅; **dividend rates 25/26 = 8.75/33.75/39.35%, 26/27 = 10.75/35.75/39.35%** (keyed by tax year) ✅.

**National Insurance (working partner):** Class 1 employee 8% between £12,570 and £50,270, 2% above ✅ (26/27 confirmed 2026-06-27: rates + frozen thresholds unchanged). **NI stops at SPA; pension income bears no NI** (guard against charging it).

**Pensions (the headline pitfalls):** 25% tax-free PCLS subject to **LSA £268,275** ✅ (track pcls_taken_to_date); UFPLS 25% tax-free / 75% taxable per chunk ✅; drawdown taxable as earnings, no NI ✅; **Month-1 emergency-tax on the first flexible withdrawal** (non-cumulative code treats a one-off as 1/12 of annual income, large over-deduction at source) ✅, model the over-deduction *and* the reclaim, and report which form applies: **P55** (part-withdrawal, pot not emptied, other income), **P50Z** (whole pot emptied, no other PAYE income), **P53Z** (whole pot emptied, still has other taxable income) ✅; **MPAA £10,000** ✅ (triggered by flexible access of DC, not PCLS-only; caps money-purchase AA and kills DC carry-forward, warn when a planned withdrawal trips it while the working partner still contributes); Annual Allowance £60,000 ✅, tapered AA −£1/£2 over £260k adjusted income, floor £10k ✅ (verified 2026-06-27); access age 55 now, **57 from 6 Apr 2028** ✅ (verified 2026-06-27; model the step).

**State Pension:** new SP full rate £230.25/wk (25/26) to £241.30/wk (26/27, +4.8% triple lock) ✅; basic SP £176.45 to £184.90/wk ✅; **SPA 66 to 67 rising 6 May 2026 to 6 Apr 2028 by DOB, computed from DOB not hard-coded** ✅; deferral uplift ~5.8%/yr ✅; **State Pension is taxable, fed through the income-tax calc** (the full new SP now sits just under the frozen PA, so many start paying tax on it around 27/28) ✅.

**CGT — Private Residence Relief:** main-home sale fully relieved, no CGT ✅. Edges: `ever_let` restricts relief; a second/non-main property is chargeable; final 9 months always relieved. CGT on GIA holdings separate — **NOW MODELLED (A5, 2026-06-27):** GIA disposals realise the pro-rata gain vs cost basis, shared £3k AEA, 18/24% by band, reusing the residential rates (equal to share-gain rates since Oct-2024). Rates + AEA £3,000 ✅ (verified 2026-06-27); final 9 months always relieved + lettings relief shared-occupancy-only ✅ (HS283, verified 2026-06-27).

**SDLT (buying the cheaper home, England/NI):** 0% to £125k, 2% to £250k, 5% to £925k, 10% to £1.5m, 12% above (from 1 Apr 2025) ✅; **+5% additional-property surcharge** if they own two homes momentarily (buy before sell), reclaimable within 36 months, model the timing ✅. SDLT bands + 5% surcharge ✅ (verified 2026-06-27). Scotland/Wales use LBTT/LTT (different taxes; out of v1 scope, region resolver throws), swap by region, ship SDLT first.

**Means-tested benefits:** Pension Credit capital, first **£10,000 disregarded**, then **deemed income £1/wk per £500** (pensioner tariff, not the working-age £6,000/£250 rule), **no upper limit for PC itself** ✅; **HB / Council Tax Support: same tariff but £16,000 upper cut-off** ✅; **downsizing converts an exempt home into assessable capital** (the killer interaction, warn on crossing £16,000) ✅; deprivation-of-capital surfaced as information only, never a recommendation. Capital tariff (£10k disregard, £1/wk per £500, £16k HB/CTS cut-off) ✅ verified 2026-06-27. **The Guarantee Credit award is now modelled (2026-07-01):** `Benefits\PensionCreditCalculator` computes it per year inside `PathProjector` (SMG single/couple + Severe Disability addition, sourced weekly rates per tax year in `TaxYearRegistry`) — those figures join the freshness/verification pass.

**IHT (toggle):** NRB £325,000 ✅ (frozen to 5 Apr 2031), RNRB £175,000 ✅ (frozen to 5 Apr 2030) with £2m taper ✅, spousal transfer (up to £1m for a couple) ✅ structure (all verified 2026-06-27); **unused pension pots enter the IHT estate from 6 April 2027** ✅ — **now enacted** (Finance Act 2026, Royal Assent 18 Mar 2026), upgraded from "proposed"; model behind the toggle.

**Care (secondary):** England means-test upper £23,250 / lower £14,250 ✅ (verified 2026-06-27, frozen 15th year; £1/wk per £250 between limits); the £86k cap reform was **cancelled July 2024**, not in force (confirmed 2026-06-27), so not modelled; deprivation risk mirrors benefits.

**Build-time verification checklist — COMPLETED 2026-06-27** (was ⚠️): NI 26/27 ✅; tapered-AA thresholds ✅; LSA + LSDBA ✅; access-age-57 date (6 Apr 2028) ✅; CGT residential rates + £3,000 AEA ✅; PC/HB capital tariff (£10k/£500/£16k) ✅; IHT NRB/RNRB + £2m taper ✅ (NRB frozen to 5 Apr 2031, RNRB to 5 Apr 2030) + the **April-2027 pensions-in-estate change now enacted** (Finance Act 2026) ✅; care thresholds ✅ (£86k cap cancelled). Still out of v1 scope (not verified, region resolver throws): **Scottish income-tax bands** and **LBTT/LTT** (Wales/Scotland property taxes). Each ✅ figure carries its gov.uk citation + `verified_on: 2026-06-27` in config. See DECISIONS 2026-06-27.

---

## Monte Carlo + mortality

- **Real terms throughout** (user thinks in today's money), but model **inflation as its own stochastic series** to convert to nominal for the tax interaction: frozen nominal thresholds mean **real fiscal drag** is a modelled feature, not a footnote.
- **Correlated annual real returns, lognormal compounding** (Cholesky on the correlation matrix). Annual step.
- **Joint-life mortality:** sample each partner's age of death per path from **ONS cohort life tables** (by sex/age), independent by default (a documented "broken-heart" correlation option later). Household runs until the **last survivor** dies (hard cap age 105). On first death: one State Pension stops, spend drops by `survivor_spend_factor`, and **the survivor inherits the deceased's assets** (cash/ISA/GIA with CGT base-cost uplift + remaining pension pot — `PathProjector::settleEstates`, the 2026-07-01 stranded-wealth fix that changed every couple forecast). *Not yet modelled despite the original sketch:* the DB survivor fraction (`spousePensionFraction` is collected but unconsumed — see DATA-MODEL Known divergences) and any council-tax single-person discount (arguably folded into `survivor_spend_factor`). So each path carries both a return sequence and a mortality outcome.
- **Defaults are sourced, never invented.** Asset classes (global equities, gilts/bonds, cash) and inflation each carry expected real return, volatility and a `source` string, editable in admin/UI. Presets: FCA-derived (default), DMS/EGS-derived, OBR/BoE-inflation-blended. A **compare-assumptions** view overlays them, each labelled with its source and method.
- **Outputs:** 10/25/50/75/90 percentile fan; **success probability** reported two ways (essentials always met; full target met); **depletion-age distribution** (at what age money runs out across failing paths); a dedicated **sequence-risk** surface (the p10 path's first-five-years drawdown), not buried.
- **Reproducibility:** seeded PRNG (`\Random\Randomizer` with `Mt19937(seed)`). Seed set gives byte-identical results for golden-master tests and shareable runs; seed always recorded.
- **Performance, no silent long-runs:** preview ~1,000 paths synchronous (~1–2s, still shows progress); full 10,000 paths queued (database queue + worker; Horizon dropped — see Locked decisions), chunked 10×1,000 so progress is granular and cancellable. UI shows live progress via Livewire `wire:poll` (~1s) for v1, with a cancel; Reverb/Echo push is a later upgrade. Pre-draw the random matrix; convert to `Money` only at year boundaries.

---

## Buy-vs-rent comparison

Both variants run the **same engine on the same seeds**; only the housing leg and investable pot differ. Common start: net sale proceeds = sale price − outstanding mortgage − selling costs − any CGT (usually £0 via PRR).

- **Buy outright:** t0 outflow = purchase price + SDLT (+ surcharge if timing overlaps) + moving/legal costs; invested surplus = net proceeds − that; house gets optional stochastic growth and recurring maintenance/insurance/council tax (inflating). Smaller liquid pot, larger illiquid asset.
- **Rent:** t0 = full net proceeds invested (bigger liquid pot, more growth *and* more sequence risk); recurring rent inflating at `rent_inflation` (modelled separately from CPI, rents historically outpace it, user-set with a source); no house asset or maintenance.
- **Coupling (the point):** the pension withdrawal plan feeds the *same* income/tax engine in both, but the housing choice changes *required* withdrawals (renters need more income, may withdraw more, hit higher marginal rate / MPAA / PA taper). Each year: housing leg, then required net spend, then gross withdrawal needed, then tax, then next-year pot.
- **Compared on:** terminal wealth distribution (liquid + property) per variant, fan charts side by side; success probability per variant; depletion-age distribution per variant; a plain-language **consequence** panel (neutral framing, never a ranking).

---

## Regulatory / compliance layer

**Built 2026-06-25 (Phase 2 step 4) — see DECISIONS and the code-review status above.** The lint +
partition test, the first-run acknowledgement gate, the per-result + per-export disclaimers, the
reusable signpost component and the admin-granted interpretation toggle are all implemented and tested.

- Generic guidance only, user-driven inputs, illustrative consequences. Never "you should".
- **Disclaimers:** persistent global footer; a first-run modal the user acknowledges (timestamp stored for logged-in users); a per-result disclaimer block on every Result render and every export; a reusable **signposting component** (Pension Wise, MoneyHelper, "find an FCA-regulated adviser") beside every pension-withdrawal or benefits output.
- **Wording guard:** an `OutputPhrasing` lint with banned recommendation patterns ("you should", "we recommend", "the best option", "is better for you"); all user-facing result strings pass through a neutral formatter ("Under these assumptions...", "This illustrates...", "One consequence is..."). **An automated test fails the build if any Result/warning template contains a banned phrase.** Comparisons present both options neutrally.
- **Interpretation toggle (added 2026-06-25, see DECISIONS):** an optional, admin-granted, per-user capability renders directive "what this suggests" readouts from the computed numbers — off by default, the public default staying neutral. The directive text lives in a single walled-off `Interpretation` service, never in the result templates, so the build test above becomes a **partition check** (banned phrasing allowed *only* inside that layer, clean everywhere else). A "not financial advice" banner is **necessary but not sufficient**: it does not move the regulatory line (classification is by substance, not by label/disclaimer), so public users always get the neutral view and the toggle is self/family only on a live deployment.
- **Audit trail:** every saved run stores the assumption snapshot + tax-year config version + engine version, so any output is demonstrably input-driven, not advice. The deprivation/care/IHT outputs (highest-risk for sounding like advice) stay strictly "here is the rule and how your numbers interact with it" plus a hard signpost.

---

## Build phasing (one deliverable, sane internal order)

1. **Engine, test-driven, no UI.** Money VO; TaxYearConfig 25/26 + 26/27 with sourced figures; IncomeTax/NI/Savings/Dividend; Pension (PCLS/UFPLS/drawdown/emergency-tax/MPAA/AA); SP-from-DOB; CGT-PRR; SDLT; Pension Credit capital; IHT/care. Worked examples A–C (below) are the acceptance gate.
2. **DTOs ↔ persistence + auth.** Eloquent models, encrypted payload, Fortify, anonymous-vs-saved, GDPR export+delete, Filament admin for AssumptionSet + tax-year config audit.
3. **Forecast stepper + mortality + Monte Carlo.** Deterministic annual cashflow first; then joint-life sampler, correlated returns, seeded reproducibility, percentile aggregation, success prob, depletion distribution, golden-master, queue + progress.
4. **UI / charts.** Livewire scenario builder; preview (sync) vs full (queued + `wire:poll` progress + cancel); ApexCharts fan + buy-vs-rent + compare-assumptions, each with the mandatory accessible table; headline numbers as text.
5. **Demo preset** (the anonymised couple, data supplied by Rob, fields listed below).
6. **Polish:** compliance hardening, PDF export with disclaimers, a11y audit (axe/Pa11y in CI), 10k-path perf tuning, Scotland config pack if in scope.

### Refinements found in code review (2026-06-25) — fold into the phases above
A review of the in-progress app layer (engine + Phase-2 steps 1–3 done) surfaced concrete
items to pick up as the phases proceed. None break the current green suite; each is tagged to
the phase it belongs in so the normal build absorbs it rather than treating it as a separate track.
**Step-4 status (2026-06-25): the compliance items + the no-silent-failure hardening are now BUILT
(✅ below); the headline-output panels and the a11y/form-UX pass remain OPEN.**

- ✅ **Compliance — BUILT (step 4 / §Regulatory).** `App\Compliance\OutputPhrasing` (directive-only
  banned patterns) + a partition build test over every Blade view and app PHP file (exempting only the
  `App\Compliance` namespace and `interpretation`-named views); first-run acknowledgement gate
  (`EnsureDisclaimerAcknowledged` middleware + `users.disclaimer_acknowledged_at` + a dedicated screen);
  reusable `<x-disclaimer.result>` + `<x-signpost>` components on every result; a disclaimer prefix on
  the CSV export. The interpretation toggle is built too (see §Regulatory). Stock `welcome.blade.php`
  deleted (unused; tripped the lint).
- ✅ **Surface the headline outputs still missing from the results page (step 4) — BUILT.** (a) The
  **lump-sum tax-shock panel** (`App\Forecast\LumpSumTaxShock`, live on the results page) and (b) the
  **compare-assumptions overlay** (`AssumptionComparison`) both shipped — see HANDOVER Current state
  "Done — app layer".
- ✅ **"No silent failure" hardening — BUILT (steps 3–4).**
  - *GDPR export* now includes the user's `simulation_runs` + `results` (decrypted, portable); erase
    cascades (user_id FK on households/scenarios/simulation_runs, results via simulation_run_id); tests
    cover runs+results in **both** export and erase.
  - *Dead worker:* `RunScenarioSimulation::failed()` marks the run `Failed` with the reason so a
    timeout / OOM / killed worker reaches a terminal status instead of stranding the page on `Running`.
  - *Owner-scoping:* `ScenarioResults::currentRun()` now scopes by `user_id`, so a forged `$runId`
    cannot load another user's run.
- ✅ **Accessibility + form UX (the itemised defects) — BUILT.** The scenario builder now programmatically
  associates field errors (`aria-invalid` + `aria-describedby` on invalid inputs), focuses the error summary on
  a validation failure, guards Save against double-submit (`wire:loading.attr="disabled"` + "Saving…"), enforces
  the `endAge ≥ startAge` cross-field rule (`endAfterStart`) and auto-saves the long-form draft. The Pa11y CI
  scaffold is wired. **What genuinely remains** is the **browser a11y audit to a public bar over the post-06-29
  panels** (What's-next #1/#2) — not these form-UX defects.

### External review triage (2026-06-25) — post-v1 enhancement backlog
A second-opinion review (MS Copilot, from the doc set) was triaged. Much of it re-surfaced our own
deferred "v1 modelling refinements" (GIA/cash income tax + CGT-on-disposal — **now built (A5, 2026-06-27)**;
stochastic house/salary growth, post-2031 reindexing, per-scheme DB escalation — still logged) or things already built (login
rate-limiting is in `FortifyServiceProvider`; the first-run acknowledgement + banned-phrase lint are
the planned compliance step). The genuinely new, aligned items, kept as a post-v1 backlog:

- **Outputs that exploit engine results we already compute (cheap, high adviser-value):** a
  **cashflow timeline table** ✅ **DONE** (the cashflow ladder, Phase C3 + drill-down #3);
  a **longevity distribution** visual (median / p10 / p90 last-survivor age, P(live past 95) from the
  joint-life sampler) ✅ **DONE**; a **stress-test panel** feeding historical sequences (1929, 1973–74,
  dot-com, GFC) through the engine ✅ **DONE (2026-07-01** — historical sequence backtest on the JST
  Macrohistory total-return data; DECISIONS 2026-07-01); **what-if sliders** ✅ **DONE, then superseded
  2026-07-01** by the save-as-a-what-if "Build a what-if" control (DECISIONS 2026-07-01 "What-ifs are
  the only way").
- **Modelling depth (v2 scope):** ✅ **an annuitisation option — DONE (2026-07-01).** Partial / level /
  escalating (RPI/CPI), single- or joint-life, priced off a **user-input rate** (default a sourced ~7.2%),
  not the mortality tables — a real quote is age/health-specific and belongs to the user, so no fabricated
  rate table lives in the engine (DECISIONS 2026-07-01). ✅ **care-cost stochasticity — DONE
  (2026-07-01):** a sampled late-life care spell in the Monte Carlo (sourced probability / duration /
  self-funder fee — Dilnot/PSSRU/LaingBuisson), opt-in, surfaced as a care-risk panel (DECISIONS 2026-07-01).
- ❌ **Neutral diagnostics — SUPERSEDED/declined (2026-06-30).** The academic diagnostics list (implied
  withdrawal rate, critical yield, replacement rate, narrative generator, capacity-for-loss panel) was
  replaced by the **per-year surplus/shortfall classification + configurable safety floor** (Rob found
  the diagnostics framing unhelpful — DECISIONS 2026-06-30 "per-year surplus/shortfall + safety floor").
  Do not build these.
- **Hardening + process (cheap):** ✅ a **Content-Security-Policy** header — **DONE** (Phase D Tier-2,
  `config/security.php`); ✅ the build-time **source-freshness** check — **DONE (2026-06-30,**
  `figures:freshness`); ✅ the **annual ONS mortality refresh** ingest + diff — **DONE (2026-07-01,**
  `mortality:refresh`). Still open (HANDOVER What's-next #4 flags both low-value/confirm-worth-it):
  the optional tamper-evident SHA-256 over each run's snapshot + seed + input DTO, and caching
  deterministic forecasts by input hash.

**Declined (over-engineering or misaligned for a local-first single-user tool) — see DECISIONS 2026-06-25:**
per-row/envelope encryption, a native (Rust/WASM/SIMD) Monte Carlo accelerator, and automated gov.uk
scraping.

### Competitive gap analysis (2026-06-30) — post-v1 backlog additions
A full-market scan (UK providers, robo/aggregator apps, independent consumer tools, prosumer/FIRE tools,
UK adviser cashflow software, and the FCA framing) is captured in
**[docs/RESEARCH-competitive-gap-analysis.md](RESEARCH-competitive-gap-analysis.md)**. Conclusion: the engine
already matches/beats the field (only Timeline also has stochastic mortality; no UK consumer tool combines a
stochastic engine + HMRC tax + housing). The gaps are a **decumulation-policy + framing layer**. The genuinely
**new, aligned** items (the existing triage above already covers the historical stress-test panel, what-if
sliders, annuitisation, care-cost stochasticity and the neutral diagnostics — this study **validates and
sharpens** them; the items below are the net-new ones), ranked by impact × on-brand fit:

- ✅ **Tax-efficient withdrawal sequencing across wrappers — CORE SHIPPED (2026-07-01, Lane C).** The
  `FillBands` strategy (pension to the PA → GIA gains to the CGT AEA → cash/ISA → basic-rate pension →
  the rest, Pension-Credit-aware) + the results-page **lifetime-tax £-delta** panel are built —
  see **[docs/PLAN-withdrawal-sequencing.md](PLAN-withdrawal-sequencing.md)** + DECISIONS 2026-07-01
  (Lane C). Remaining from that spec: **#5 planner-timed PCLS** and **#6 the optimiser** (ready-to-execute
  plan in the spec, gated on two small modelling calls from Rob).
- **Dynamic / guardrail withdrawal strategies.** Add Guyton-Klinger (±20% bands → ±10% adjustments) and a
  Vanguard-style +5%/−2.5% collar; ideally **Income Lab's risk-based guardrail** (target spend = a percentile
  of *our own* Monte-Carlo sustainable-spend distribution; recompute yearly; asymmetric raise-fast/cut-slow) —
  a natural extension of the income-floor readout + longevity distribution. The single biggest sustainability
  lever (Okusanya/Morningstar: lifts the UK safe rate from ~3.9% fixed-real toward ~5%+). Pairs with a
  **VPW/amortisation** mode using our ONS cohort survival curve as the divisor.
- **Equity release as a 4th housing strategy.** Put stay-put / downsize / rent / **lifetime mortgage** on
  identical Monte-Carlo paths — compounding fixed-for-life roll-up interest, a No-Negative-Equity-Guarantee
  cap, LTV-by-age gates, drawdown reserve vs lump sum, **with the IHT-estate interaction**. No holistic tool
  integrates equity release; clearest unoccupied position in the market. Builds on the existing
  buy/rent/stay machinery + IHT engine.
- **Sharpen the stress-test panel** (the panel itself + the "retire into a bad year" historical-sequence
  mode are ✅ **BUILT 2026-07-01** — JST backtest over ~140 start years; and care-cost stochasticity is
  already longevity-correlated, the spell lands at end of life off the sampled death age). Genuinely
  open: the FCA TR24/1 **four named tests** (start-of-retirement crash, reduced real returns,
  lower-percentile path, higher withdrawals), **block-bootstrap MC** as a refinement, and the care
  means-test refinements (an NHS Continuing Healthcare branch, the spousal home-disregard).
- **Framing/legibility on output we already compute:** a single **success-probability gauge** (+ first
  shortfall year + a Timeline-style **longevity-adjusted success rate** blending survival × sustainability),
  a **Sankey** income→tax→wrappers→spend, **reverse goal-solving** ("what pot/age/contributions hit £X for
  life?"), and a free-form spending-curve editor (hand-draw the smile). Confirm an explicit per-pot
  ongoing-charges drag is modelled.
- **Deliberate non-goal (recorded):** live account/property **aggregation** (Open Banking / Zoopla) — both
  consumer aggregators (Moneyhub, Multiply) exited that market and ProjectionLab omits it on privacy grounds;
  it conflicts with the local-first posture. A **deep-link to the gov.uk State Pension forecast** and a future
  **Pensions Dashboard** import (consumer launch ~2027) are the pragmatic substitutes.

### Delta-research backlog (2026-07-02) — communication, household, adviser outputs, a11y, methodology
A second research wave over the five topics the competitive scan left as residuals/gaps. Full findings +
sources + the adversarial source-check: **[docs/RESEARCH-delta-2026-07-02.md](RESEARCH-delta-2026-07-02.md)**.
Conclusion: the engine is (again) not the gap — these are **presentation, disclosure and a small scope**
items. Ranked by value × on-brand fit. **All stay education/guidance-side (banned-phrasing lint); directive
forms behind `compliance.personal_use`.**

- **Data-integrity fixes first (silent drops found by the 2026-07-02 doc audit — the same class the
  reconciliation/completeness rule exists to kill; see DATA-MODEL "Known divergences").** Each is a live
  builder input read by **zero** engine code, so a user's entry silently vanishes:
  - **DB survivor pension (highest). — DONE 2026-07-02.** `spousePensionFraction` is now paid: on the
    member's death the survivor keeps `accruedAnnualPension × dbFactor × fraction` for life (a new
    `PathProjector::survivorDbIncomeNominal`, mirroring the annuity survivor income), sourced as
    `defined_benefit`. Pinned by `SurvivorDbPensionTest` (completeness: the fraction demonstrably reaches
    the forecast; a null fraction still stops on death). Builder "Survivor fraction (%)" field gained a
    hint; the `DbPension` docblock was already accurate.
  - **Per-person salary growth. — DONE 2026-07-02.** `Person::salaryGrowth` is now a per-person **real**
    override: the projector holds a per-person `salaryFactor` (replacing the single household factor) and
    escalates each person's salary at their own rate, falling back to the assumption set when null (the
    override sets the trend, not risk — no volatility, mirroring the per-asset overrides). `PerPersonSalaryGrowthTest`
    pins the rate, per-person independence, and the null fall-back; builder field labelled "real" with a hint.
  - **DB commutation. — DONE 2026-07-02.** `commutationLumpSum`/`commutationFactor` now model the
    lump-sum-for-income trade-off: the pension is permanently reduced by `lumpSum ÷ factor` (factor is now
    a plain float ratio, was mis-typed `Percent`; null/≤0 defaults to 12) and the tax-free lump sum is paid
    in the year the member reaches NRA (routed as PCLS-style tax-free cash; LSA cap a v1 limit). Applies to
    the survivor fraction too. `DbCommutationTest`; builder gained a factor input + hints.
  - **NI category. — DONE 2026-07-03.** `Person::niCategory` now selects the employee NI band rate:
    standard 8% (A/F/H/M/N/V), reduced 1.85% (B/E/I), deferred 2% (D/J/L/Z), nil (C/K/S/X) — sourced from
    the gov.uk category-letter tables (2025-26 & 2026-27 identical; `NationalInsuranceParameters` gained
    `reducedMainRate`/`deferredMainRate`). Empty/unknown = standard. Builder gained a category select.
    `NationalInsuranceCalculatorTest` + `NiCategoryForecastTest`.
  - **Property::ownershipShare. — DONE 2026-07-03.** Researched the tenants-in-common convention (HMRC
    apportions value, sale proceeds and the taxable gain by beneficial share); wired it: whole-property
    figures are entered, and the share scales the household's property wealth, mortgage, means-test capital,
    IHT, running costs, and sale proceeds — with CGT computed on the household's share of the gain and split
    across its owners (composing with the existing joint-owner split). Null = wholly owned (a no-op, so the
    reconciliation-tested sale math is unchanged). `OwnershipShareTest`; builder gained an ownership-share input.
  - **All five silent-drops are now closed.** The collected-but-unconsumed class the 2026-07-02 audit found is done.
- **User-facing copy fixes (cheap; the copy-audit cluster — trust depends on copy matching the engine).**
  The **"Survivor fraction" field** is resolved (2026-07-02 — it now works and has a hint). Still open: the
  **care-off silence** — care risk is opt-in/default-off, so by default nothing tells the user a ~1-in-4,
  six-figure tail is excluded from "will the money last?" (add one line on results + PDF when off; the PDF
  never mentions care even when on).
  Lower: the **stress-test copy** ("actual returns that followed") should say years past 2020 revert to
  expected returns (the early sequence-critical years are always historical); the **fan chart** carries no
  house-price/salary uncertainty (deterministic in the MC) — say so; footnote the **longevity panel** (ONS
  50–100, >100 extrapolated, cap 110) and the **emergency-tax panel** (models the magnitude, PAYE can differ
  by a few pounds); the **"Tax-free (25%)"** card should read "up to 25%" (LSA cap / drawdown = £0); the
  **disclaimer** should acknowledge the labelled advice panel in personal-use mode; the **"all three run and
  compared"** builder copy should match the single-strategy report (compare on Compare); and pull
  **hardcoded figures** (10,000 paths, care fees, 1871–2020, CGT rates) from their single sources.
- **Uncertainty-communication upgrades** (reuse figures already computed): a **natural-frequency headline +
  10×10 icon array** ("12 of 100 futures run short", never "1 in 8"); **consequence metrics** (median
  depletion age + the £/month cut that fixes it — a solver over the engine, directive form gated);
  **mortality-weighted ruin risk** ("alive **and** out of money", Timeline/Rich-Broke-Dead); **harden the fan
  chart** vs deterministic construal (in-plot band labels + "1-in-10 fall below the fan" + optional sample
  paths). **Record the deterministic-leads decision** (COBS 13.5 + PRIIPs removal) so a redesign can't invert
  it into a public-release problem.
- **Third adult contributing to upkeep (Rob's back-burner scope ask — model without full third-person
  planning).** A **household-owned `BoardContribution`** income stream with a three-way tax enum
  (`family_cost_sharing` non-taxable · `rent_a_room` £7,500/£3,750 exemption, excess taxable · `taxable_rent`)
  + a lightweight **`HouseholdMember`** presence record (flags only, no mortality/pension). Wire the two UK
  interactions that bite for a pensioner household: suppress the **25% council-tax discount** and the **PC
  Severe Disability addition** while a non-disregarded member is present; family board money is not PC-means-
  test income. Reconciliation **in reverse**: a third adult must be able to **reduce** entitlements. Same
  shape later serves **dependent children with time-limited costs**. **No third full planning subject** — the
  couple ceiling stands (market-unanimous; DECISIONS 2026-07-02).
- **User-facing methodology layer (market-unique trust signal).** A single **`/methodology`** page (intent →
  TOC by component → plain-English + expandable technical + concrete values → a "what we don't model" list →
  an **auto-rendered sources-and-dates table** from the registries → FAQ → regulatory posture); the results
  panels deep-link into it. Plus a **"how we test this"** section (reproduces HMRC examples; completeness;
  reconciliation — no competitor makes this claim) and a **public assumptions changelog** (dated lines from
  registry diffs). Adopt the UK statutory disclosure vocabulary (SI 2013/2734 Sch 6) as lint-safe boilerplate.
- **Adviser / Pension-Wise output pack** (turns the PDF from *results* into a conversation-starter): add the
  **five COBS 9.4.10G drawdown risk warnings**; a **Plan Version** id on the cover; **real-terms net-of-tax**
  page labels + a visible fees line; **per-objective funding lines** ("essentials met in N of M years");
  an **assumptions annex** (value + source + `verified_on` + altered-from-default markers); a
  **scenario-comparison** section with a fixed interpretation block + sub-100% success figure; a **Pension
  Wise fact-find appendix** (RIAAT 8 areas + prep checklist + plan-specific "questions to ask"); a
  **version-to-version change log**. Later: a **modular pack** (self / adviser / full) and — personal-use mode
  only, behind the flag — a suitability-style narrative.
- **Accessibility + mobile to a public bar.** Raise the target to **WCAG 2.2 AA** (the operative UK baseline;
  encode the six new criteria as a manual checklist — axe/Pa11y don't cover them); publish a GOV.UK-style
  **accessibility statement**; add a **body-text message description** per chart (keep the table+CSV); **stay
  responsive-web, desktop-recommended setup** but make the results/monitoring surface work on mobile and
  **replace the results nav hidden on mobile** (no market precedent for hiding it); restructure the wizard
  toward **GOV.UK question-pages** (one topic per page + check-answers — mobile-capable for free, satisfies
  2.2 Redundant Entry); run **one manual assistive-tech pass** before release.
- **Stress-test data completeness (from the technical-doc audit).** `HistoricalReturns` embeds JST **only,
  ending 2020** — 2021–2024 (incl. the 2022–23 inflation shock) is absent; past-2020 horizons revert to
  expected returns. Either extend with **ONS CPI** (own `source` + `verified_on`) or keep JST-only and say so
  on the panel + in DECISIONS.

### Forced-housing-event workstream (2026-06-30) — from the V2 real-couple pressure-test
Driving the engine against a real forced-mortgage couple (a BTL they live in, due for redemption and can't refinance;
income ≈ two State Pensions + DLA + one small DC pot) found the *core* already answered (buy-vs-rent on identical seeds,
partial-PRR CGT, income-floor + safety floor, longevity) but four decision-critical gaps. Rationale +
the couple's figures: DECISIONS 2026-06-30 (forced-mortgage pressure-test; input-expectation clarity).

> **✅ ALL FOUR TRACKS BUILT (2026-06-30 → 07-01, Lane B)** — see DECISIONS 2026-06-30/07-01 +
> DATA-MODEL "Forced-housing" notes. (A) Pension Credit (SMG + Severe Disability addition, sourced weekly
> rates) is wired into `PathProjector` as the `means_tested_benefit` income source; (B) the
> mortgage-maturity event + the payment-stop are built ([PLAN-mortgage-payment-stop.md](PLAN-mortgage-payment-stop.md),
> BUILT); (C) feasibility flags are surfaced by `ResultPresenter`; (D) the pay-frequency selector + the
> first-class tax-free `DisabilityBenefit` income type are built (the income-ends-on-sale sub-item was
> **declined** — single-property model, DECISIONS 2026-07-01). **The one remaining piece is the specced
> in-place forced-sale what-if** ([PLAN-in-place-forced-sale.md](PLAN-in-place-forced-sale.md), decisions
> resolved, ready to build). The original build-order spec follows as the build record:

- **(A) Means-tested benefits in the live forecast.** ~~Today~~ (at time of writing) `Benefits\CapitalAssessment` (capital tariff + £16k
  cliff, verified 2026-06-27) was a standalone snapshot, **never wired into `PathProjector`**. Build a sourced
  `Benefits\PensionCreditCalculator` (Guarantee Credit = top assessable income up to the Standard Minimum Guarantee;
  + **Severe Disability / Carer additions**; tariff income from capital reuses `CapitalAssessment`); add it to the
  per-year net-cash assembly as a new `YearResult` income source **`means_tested_benefit`**, recomputed each year as
  capital/income change, firing the £16k cliff in-projection. Add a household/person **disability flag** (drives the
  SDP + the DLA/AA passport). **Council Tax Reduction is locally set** → v1 models the £16k cliff / PC-passport, not
  a precise CTR award (flagged). Guards: per-source **completeness** (benefit demonstrably reaches the result) +
  **reconciliation** (award + tariff). *Why first:* the V2 survivor-cliff (widow drops to ~£11k vs ~£26k need from
  the first death) is where this changes the recommendation. Figures to verify against gov.uk on build:
  /pension-credit (SMG single/couple; SDP; Carer), /attendance-allowance, /disability-living-allowance-adults.
- **(B) Mortgage-redemption event.** `Property` gains a **redemption/maturity year** + a **maturity action**
  {refinance at a rate · repay-from-capital · forced sale}; `PathProjector` tracks the mortgage balance and applies
  the action at maturity (inject capital / switch to a repayment cost / transition to the sell transform). Stops
  "stay put" silently paying a perpetual BTL that must legally be redeemed. Generalises to interest-only maturities
  and fixed-term ends. (Pairs with: a one-off cost that declares **which path(s)** it applies to — the convert-to-
  residential deposit.)
- **(C) Feasibility flags.** `HousingComparison` currently floors a buy price above net proceeds ("downsizing
  assumed") — surface it as an **input-sanity note** ("buying at £X needs £Y more than the sale frees"); likewise
  flag a "stay/convert" needing capital the household lacks. No silent infeasible model.
- **(D) Input-expectation clarity.** A **pay-frequency selector** (weekly / 4-weekly / monthly / annual → stored
  annual; 4-weekly because DWP pays that way) on every per-period money input; a **"tax-free benefit (DLA/AA/PIP)"**
  income type (sets `taxable=false`, means-test-exempt); and **input-sanity prompts** for a blank retirement age
  (modelled as working for life) and a one-off charged to every variant. Catches the exact V2 mis-entries at the
  point of entry. **Also surfaced:** income tied to a property should **end when that property is sold** (the
  rental on a sold flat) — fold into (B)/(C).
- **Validation:** a **synthetic** forced-mortgage engine fixture exercises (A)–(C); the real couple's figures stay
  local-only (the V2 scenario + throwaway runs), never committed.

### Sector-informed build plan (2026-06-25) — edit/clone/compare, line-item expenditure, drill-down
Research into how the cashflow-modelling sector solves these (Voyant/Timeline/CashCalc + PLSA/SMPI)
is captured in **[docs/RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md)**. These are
solved problems — we follow the proven shape and customise. Agreed build order:

**0. Checkpoint** ✅ done (commit `2b5abc8`). **Rebuild authorised (2026-06-25):** the scenario +
expenditure data-shape rebuild is sanctioned even though it reworks yesterday's prototype builder (drafts,
names, State Pension shortcut) — that work got Rob a usable app for feedback; the UI wins carry over, the
draft mechanism folds into `builder_state`.

**1. Edit → designed for clone + compare (the base-plan / what-if pattern — CONFIRMED in scope).**
- Persist the builder **form-state** on the scenario (encrypted `builder_state`); the engine DTO
  stays a *derived* artifact regenerated on save (avoids a fragile reverse-mapper — single source).
- Edit route `/scenarios/{scenario}/edit`, **owner-scoped**; `save()` becomes update-or-create.
- **Child what-if** = a named scenario from a **"Create child" button** that **overrides anything** the
  user changes (often 1–2 fields; curated levers are presets, not a limit) — stored as a **delta** of the
  changed fields, not a full copy (single-source, no fork; research §1 + DECISIONS 2026-06-25). The child
  editor is the **full builder pre-filled from the base**; effective = base `builder_state` ⊕ overrides
  via **one merge function** (+ round-trip test). **List items (expense lines, pensions, accounts) gain
  stable IDs** so overrides target the right row. **Compare** reuses the variant side-by-side rendering.
  On edit-save, **invalidate stale runs/results**.
  - ✅ **BUILT — edit (Phase B, 2026-06-25); clone/compare (Phase C2, 2026-06-26).** Children store
    `parent_scenario_id` + an encrypted `overrides` delta; the merge fn is `App\Forecast\BuilderStateDelta`
    (id-aware, round-trip + structural-guard tested); list rows carry stable ids; the builder's child mode
    diffs the edited form to the delta and refuses a structural add/remove; a base edit propagates to its
    children; `ScenarioCompare` shows base + children on their deterministic projection. **v1 boundary:** a
    child overrides values only (structural row changes go to the base). Longevity-as-a-builder-field is a
    C1 fast-follow (engine support exists from A2).

**2. Budget line items + the two cheap sector payoffs.**
- ✅ **CORE BUILT (Phase C1, 2026-06-26):** line items are the source of truth (`builder_state.expenseLines`);
  the `HouseholdAssembler` derives essential/discretionary (spent self-investment → discretionary) and a saved
  self-investment line → a balance-zero contributing ISA (one home per pound) — **no engine change needed**
  (the contribution machinery already existed from Phase A1). Flat totals dropped when lines exist; legacy/
  imported scenarios seed lines from flat totals. Reconciliation + completeness tested. **Fast-follow:** the
  results 3-tier display, the income-floor readout, PLSA benchmark, and importers emitting real lines (they
  still emit flat totals → seeded into 2 generic lines; extend the import golden fixtures then).
- Line items `{id, label, amount(annual), category, savedAsAsset}` become the **source of truth**,
  category ∈ **essential / discretionary / self-investment**; essential/discretionary **totals = the sum
  of the lines** (reconciliation discipline). **Self-investment** carries `savedAsAsset`: *spent*
  (courses/books) → expenditure, *saved* (savings/investments) → a **contribution to net worth** (needs
  **ongoing contributions on accounts**); one home per pound. Importers populate the lines (today
  discarded into a total). Builder shows an editable list; results show the **3-tier** breakdown framed as
  the **goal, not a %**. New reconciliation invariants + extend the import golden fixtures.
- ✅ **DONE.** **Income-floor readout** (essential vs guaranteed income = State Pension + DB + annuity +
  tax-free; C1 fast-follow) and **PLSA benchmark** ("your spending reaches the Moderate standard"; **C4,
  2026-06-26**). The PLSA figures are sourced engine reference data (`src/Benchmark/RetirementLivingStandards`,
  figures ✅ verified 2026-06-27 against the published table); the comparison is put on the PLSA basis (excludes rent/mortgage,
  includes home running costs — gotcha J) and reconciles to the same `ExpenseProfile` the forecast runs on.
  Defer the spending "smile"/phased spend (an engine expense-model change).
- **Engine additions (small, each golden-master tested):** a per-person **longevity/health adjustment**
  feeding `JointLifeSampler` (fixed assumed age / ±years / mortality multiplier — for the lifespan
  what-if); **ongoing contributions on accounts** (so *saved* self-investment grows).

**3. Projection drill-down.**
- **Deterministic cashflow ladder** from `YearResult[]` (per year: **income by source** — salary, DB,
  State Pension, annuity, **tax-free streams like DLA**, drawdown — → tax → net → spend → surplus/deficit
  → asset balances), accessible table + CSV; MC success-rate as the risk lens, framed as a probability
  (not pass/fail). Show **how the drawdown was allocated** each year (which pot, the strategy: ~4% from
  savings/ISA/GIA vs DC lump-sum vs drawdown vs annuity) so the user can verify the mechanics — this is
  how income-counting gaps get caught (live use found tax-free DLA income was being dropped from the
  "will the money last" calc; **fixed 2026-06-25**, regression-tested). **Per-pension** current →
  projected pot → indicative income. Defer the annuity-equivalent (new mortality-pricing math).
- **Next-steps signposting (later, compliance-gated):** beside the results, point to *regulated channels*
  to act on or refine the plan — Pension Wise, MoneyHelper, the FCA "find an adviser" register, annuity
  comparison services — as **categories/channels, never specific product recommendations** (stays
  guidance-only; passes the `OutputPhrasing` lint).

**Gotchas — what could bite (the important part, refined with the research):**
| # | Bite | Mitigation |
|---|------|------------|
| A | Line-items double-count / unit slip (monthly vs annual) | totals = `sum(lines)`, never stored apart; reconciliation invariant + golden fixtures |
| B | Edit leaves **stale results** (old forecast from old inputs) | invalidate runs/results on edit-save, prompt re-run |
| C | Edit/clone **owner-scoping** (forged scenario id) | explicit `user_id` check in `mount()` |
| D | Draft vs edit/clone **context clobber** | gate on `editingScenarioId`; editing skips the create-draft, doesn't delete it |
| E | No single Monte-Carlo year-by-year path | ladder is **deterministic**; MC stays for bands/probability |
| F | Per-pension series / annuity may need engine work | **verify `YearResult` shape first**; defer annuity-equivalent |
| G | Backward-compat: old households (no line items), old scenarios (no `builder_state`) | lines authoritative-when-present else fall back to the stored total; old scenarios: "re-create to edit" |
| J | **PLSA assumes outright ownership, excludes housing/care** | add the housing leg before benchmarking (our buy-vs-rent is exactly the excluded bit) |
| K | Flat spend vs the spending **smile** | defer phased spend; flag as next engine piece |
| L | Income-floor needs income classified **guaranteed vs flexible** | derive from types (SP/DB/annuity = guaranteed; drawdown/GIA = flexible) |
| M | Success-rate read as pass/fail | present as a **probability under assumptions** (also satisfies the lint) |
| N | Delta-child **override resolution** / orphaned overrides when the base changes | one merge fn `effective = base ⊕ overrides` + round-trip test; **stable IDs** on list items so overrides target the right row; validate keys vs the base (full-copy rejected — it forks) |
| O | **Saved self-investment double-counted** (a savings line that also grows as an account) | one home per pound — a *saved* line **is** the contribution, never also an account balance; reconciliation invariant |
| P | **Asset wealth vs usable cash conflated** (live-use bug 2026-06-25: a card shows 100% chance of running out yet the *highest* "median wealth left", because the illiquid home is counted as wealth) | results must separate **usable/liquid** from **total incl. property**; verify what "wealth left" includes; the per-scenario cashflow graph makes it legible |
| Q | **Income source silently dropped** (live-use bug 2026-06-25: tax-free DLA income was not counted — only taxable streams were summed) | **per-source completeness test** (every income source contributes to net cash); the cashflow ladder's income-by-source view is the visual guard (fixed + tested) |

**Decisions now settled (DECISIONS 2026-06-25):** anything-overridable delta children + "Create child" +
stable IDs; 3-tier line items (essential/discretionary/self-investment) with spent/saved, totals derived;
per-person longevity adjustment; income-floor readout in-phase, PLSA benchmark a fast-follow; phased spend
+ annuity-equivalent deferred. **Drill-down must also split usable cash vs total wealth and graph cashflow
per scenario** (live-use finding, gotcha P). Planning closed — ready to build, starting with the scenario
data-shape + edit/clone.

### Go-live UX backlog (2026-06-28, from the verification pass)
- **Queued-run "waiting for a worker" hint (no-silent-failure).** Live use surfaced that clicking *Run the full
  10,000-path forecast* with **no queue worker running** leaves the run at "Queued — 0%" **indefinitely with no
  indication why**. The full run is dispatched to the database queue (`QUEUE_CONNECTION=database`) and needs a
  worker (`php artisan queue:work`); the synchronous *preview* does not. A run that sits queued forever with no
  feedback violates the design ethos (*no silent failure; long runs show live progress / a reason*). **Fix:** in
  `App\Livewire\ScenarioResults`, when a run has been `queued` with zero progress for ~15s (compare the run's
  `created_at`/`updated_at` against now), render a neutral note on the results page — e.g. *"Still waiting for a
  background worker to start this run. If you're running locally, start one with `php artisan queue:work`."* — and
  clear it once it moves to running/done. Small, well-scoped: a timestamp check in the component + a Blade line +
  a test (queued-and-stale ⇒ hint shown; running/done ⇒ hidden). Optionally surface a one-line "start a worker"
  reminder in the docs/landing for local use. **Not blocking**; closes the exact gap hit during the 2026-06-28
  browser verification pass. **✅ Resolved (2026-06-29):** the predicate lives on the model
  (`SimulationRun::isAwaitingWorker()` = queued + 0% + `created_at` past a 15s grace window), with a neutral
  `role="status"` note rendered inside the existing `wire:poll` progress block in `ScenarioResults` (appears on the
  next poll, clears once the run moves to running/done). Covered by a model-predicate test (every status/age/progress
  case) and a Livewire test (fresh ⇒ hidden, stale ⇒ shown). The docs/landing reminder was not added (deferred as
  genuinely optional).

### Go-live UX backlog, cont. (2026-06-28, from live use of the full run)
- **"Chance of running out" label reads as contradictory beside "wealth left" (presentation, not a bug).** Verified
  in the engine: `depletionRate == 1 − successProbabilityEssentials`, i.e. "runs out" means *at least one year
  essentials weren't fully met* (flagged on the first such year; the path then continues and can recover as
  guaranteed income later outpaces spend). It sits next to the median *end-of-life* "wealth left", so e.g. Sell &
  rent can show **55% runs out** beside **£659k usable wealth left** — internally consistent (different lenses) but
  confusing. The existing footnote only explains the home-illiquidity paradox (total vs usable), not this
  transient-shortfall one. **Fix:** relabel to e.g. *"Chance of a shortfall year"* (or keep "running out" + a
  one-line clarifier) and extend the footnote to cover transient-recovery. Small Blade/presenter change + a test.
  **✅ Resolved (2026-06-28, `5277155`):** kept the "Chance of running out" label (Rob wanted the punch) and added a
  blunt plain-English verdict per option (`ResultPresenter::runOutVerdict` — "you'd very likely run out of money…",
  factual + lint-safe) plus a footnote that reconciles "running out" (a shortfall year, may recover) with "wealth
  left" (end state). So the phrasing keeps its bite and the figures no longer read as contradictory.

### Review findings (2026-06-28 re-review — data presentation/analysis/provision)
A focused re-review (3 parallel agents + direct verification) confirmed the project is on track and the
data-integrity discipline holds (one-formatter percentages, usable-vs-total one definition, no stored aggregates,
per-source completeness incl. tax-free income, reconciliation tests, no recommendation leak). Verified findings,
prioritised:

**✅ ALL FIVE RESOLVED (2026-06-28, commits `fff3f07` + `86d5d82`; suite 353 → 359 green).** Resolution per item is
noted inline below; the original findings are kept for the record.
- **[MED] PDF Monte-Carlo summary can diverge from the screen + carries no run provenance.** The PDF
  (`ScenarioPdfController::monteCarloSummary`) selects `latest Done` run, while the screen
  (`ScenarioResults::mount`/`currentRun`) shows the *latest* run only if it is `Done`. So after a `Done` run
  followed by a *cancelled/failed* new run, the screen hides the MC table but the PDF still prints the earlier
  `Done` one — the PDF can show figures the screen is suppressing, contradicting its "cannot drift from what the
  user saw" claim. The PDF MC table also lacks the run's mode/n_paths/seed/date that the screen stamps, so a
  1,000-path *preview* can be printed as the report headline indistinguishably from a 10k full run. **Fix:** make
  the PDF select the run the same way the screen does (latest; include MC only if `Done`), or decide both should
  fall back to the last successful run (a product choice); and stamp the PDF MC section with mode/n_paths/seed/date.
  Add a test. (Bugs introduced with the PDF feature this session.) **✅ Resolved:** both screen + PDF now read
  `Scenario::latestCompletedRun()` (fall back to the last successful run); the PDF stamps mode/paths/seed/date; and
  `DisplayedFigureProvenanceTest` now covers the PDF (the gap that let it slip through).
- **[MED] `freezeEndYear` documented but never implemented (engine).** `ForecastSettings::$freezeEndYear`
  (default 2031) is documented as "the year thresholds stop being frozen and rise with inflation again" and is
  plumbed through `HousingComparison`, but `PathProjector` uses one fixed tax-year config and never reads it —
  thresholds are frozen for the *whole* projection (the projector docblock admits this). So the two docblocks
  contradict and every long projection overstates fiscal drag post-2031. **Decision needed:** implement
  threshold un-freezing from `freezeEndYear`, or correct the `ForecastSettings` docblock (and drop the dead param)
  to match the conservative actual behaviour. **✅ Resolved:** implemented the un-freezing via the income-tax
  function's degree-1 homogeneity (`PathProjector::indexedTotalPence` — no hot-loop config rebuild); `ThresholdFreezeTest`
  pins it; both docblocks now accurate.
- **[LOW] GIA disposal gain/basis split rounds two float-derived parts independently** (`PathProjector.php:533-534`):
  `realisedGain += round((balance-basis)*take/balance)` and `giaBasis -= round(basis*take/balance)` use float
  division and round separately, so cost basis can drift a penny over many partial disposals — the sum-of-rounds
  vs round-of-sum pattern, in a *tax* calc where exact pence is the standard. **Fix:** derive one part from the
  other (`basisReduction = round(basis*take/balance)`, `realisedGain = take − basisReduction`, capped) and add a
  multi-disposal basis-reconciliation test. (Bounded; no current test pins the basis invariant.) **✅ Resolved:**
  extracted `PathProjector::disposeGiaSlice` (gain rounded, basis derived as the remainder so gain + basis == take
  exactly); pinned by a multi-disposal conservation test.
- **[LOW] `medianDepletionYear` reaches only the screen headline.** It is computed and shown in the per-variant
  headline cards but is absent from the comparison table, the fan CSV and the PDF — a computed figure reaching one
  of four surfaces. Add it to `ResultPresenter::comparison()` rows so all surfaces carry it. **✅ Resolved:** added to
  `comparison()` rows → now in the screen comparison table + the PDF.
- **[LOW] No cash-interest conservation test** mirroring the GIA one (income+capital == total return is correct in
  code but only GIA is pinned by a test). **✅ Resolved:** added (cash interest reaches the forecast; the taxed
  capital-only cash can't out-grow a tax-free ISA).

### Adviser-legibility workstream (2026-06-29, from Rob's browser walkthrough)
Rob walked the rendered results for a real couple and surfaced one **correctness** issue and a cluster of
**legibility** gaps. Unifying problem: the tool computes faithfully but does not make its model legible — it never
says what an input means, where a cost belongs, or *when* a life event happens. None of this is an engine bug
(determinism, mortality and tax were all re-verified this session); it is data-model placement + missing
explanation. Priority order below.

**1. [CORRECTNESS] Housing- and status-linked costs sit in shared *spending*, so they leak across options.**
✅ **Built (2026-06-29, option b; DECISIONS 2026-06-29 "Built #1").** An expense line carries a **condition**
(always / while-owning-home / while-working), auto-classified by label with a per-line override; `ExpenseProfile`
gains `propertyCosts` + `employmentCosts` markers; the **sell variants build with `withoutPropertyCosts()`** (so the
mortgage/service charge stop when the home is sold) and `PathProjector` **drops the commute when no one earns** (so
it stops at retirement); `HousingComparison::variantInputs()` is the new single source of the variant households
(also for the #6 ladder); PLSA excludes property costs too. Reconciliation-tested. ✅ **The builder UI for the
per-line cost-condition override was built 2026-06-30** (the "Applies" select — DECISIONS 2026-06-30; agrees with
the revised-sequencing item 3 list below). The original analysis follows.
`expenseProfile` is shared by all three housing variants (`HousingComparison::withHousing` passes it through
unchanged) and `PathProjector` charges `targetAnnualSpend()` in every variant. So costs that should depend on a
*choice* or a *life phase* are charged unconditionally:
  - **Mortgage payment + service charge** (~£22.9k/yr in the test couple) are essential-spend line items, so
    *sell & rent* pays a phantom mortgage + service charge on a flat it no longer owns (on top of rent), and
    *buy outright* pays a phantom mortgage on a home owned outright. This **biases the headline buy-vs-rent
    comparison against selling.**
  - **Commute fuel** is charged for life, but should stop when P1 retires (no job, no commute).

  **Agreed direction:** a cost has **one home, tied to what it depends on.** Housing-linked costs (ongoing
  mortgage payment, service charge / ground rent, owner maintenance) belong with the *property / decision* so
  selling removes them; status-linked costs (commuting) are tagged to the status that creates them (employment)
  and stop when it ends; general living costs (food, utilities, cars, leisure, insurance) stay in spending —
  they are the same whichever option is chosen. This is the data-integrity "one definition, one home" rule
  (DECISIONS 2026-06-25) + completeness, applied to **contingent expenses**. Already foreseen for the parked
  import work ("the mortgage ends, commuting stops, the spending smile"); **promote it from import-only to a core
  data-model concept** — an expense line can carry a *condition* (while-owning / while-working / age-bounded).
  **Guard:** reconciliation tests — a property's costs appear in **zero** post-sale years of the rent/buy
  variants; commute cost is **zero** from the retirement year; the spend charged in a year equals the sum of the
  lines *active* that year (no silent drop, no phantom charge).

**2. [LEGIBILITY] Life-event milestones are modelled but never shown "when".** ✅ **Built (2026-06-29, results page
"When the big events happen"; DECISIONS 2026-06-29 "life-event milestones"):** `ResultPresenter::milestones` shows a
dated/aged list of *when* each person **retires**, takes their first **pension** withdrawal, their **State Pension
starts** (SPA from `StatePensionAge`), and their **modelled death** — the death from a new single-source engine
field `ForecastResult::deathCalendarYears` (birthYear + death age). **The "still to do" half landed 2026-07-01:**
the house-sale marker ships with a sell strategy, and the charts moved to the top of the report **with milestone
annotations** (the Compare burndown gained the same annotations) — DECISIONS 2026-07-01 "What-ifs are the only
way". The original spec follows. Major events — each person
**retires**, **State Pension starts** (SPA), planned **pension access / lump sum**, **house sale**, each person's
**modelled death**, and the cost changes they trigger (commute stops, mortgage ends) — all happen inside the
projection but are invisible on screen. **Fix:** a milestones layer — a dated/aged list plus markers on the
cashflow ladder + charts ("2027 · P2 dies", "2027 · P1's State Pension starts", "year 0 · home sold"). Reuses
figures the engine already produces (`YearResult::ages`, SPA year, withdrawal ages, death ages); read-only
presentation.

**3. [LEGIBILITY] House-sale explainer — show the decomposition and the destination.** ✅ **Built (2026-06-29,
results page; DECISIONS 2026-06-29 "explainer / show-your-working layer"):** `ResultPresenter::saleExplainer` shows
the proceeds waterfall + per-option destination (the selling-cost **rate** beside the £, so the 20% case is
visible), reading the engine's single-source `HousingProceeds` + a new reconciled `HousingPurchase` for the
buy-side surplus; an `assumptionsPanel` states the blended **real** return (single-source) and the other
assumptions, each labelled real-vs-nominal; and the cashflow ladder itemises spend into essential/discretionary.
Reconciliation + labelling guards added. **Still to do:** surface it on the **PDF** too (currently results-page
only), and the per-year *drawdown narrative* ("what was spent when and why") lands with the milestones (#2) and the
per-strategy ladder (#6). The original spec follows. "Sell gives us ~£80k?"
must be auditable: sale price − outstanding mortgage − selling costs (2%) − CGT (£0, PRR v1) = **net proceeds**;
for buy-cheaper, − buy price − SDLT − moving = **surplus**; then **where the money goes** — it is invested into a
GIA following the chosen assumption set's **blended real return** (state the %, and that it is *real*, i.e. after
inflation; ~2% of it is paid out as taxable income, the rest is capital growth), not idle cash losing value.
`HousingComparison::saleProceeds()` already returns the full breakdown (`HousingProceeds`); surface it on the
results + PDF and reconcile (the parts sum to the proceeds; the invested amount equals what enters the GIA).
Deliver it as a **plain-text explainer block** that states the assumptions actually used (blended real return,
inflation, rent inflation, selling-cost rate) and then narrates the money: what was spent when and on what, and
how the proceeds are invested, so a user can see *why* a balance moves. Concrete need: tracing the real couple's
sell-&-rent showed £72k net proceeds draining to £0 in ~4 years with no on-screen reason (see #1 + #4 for the two
causes).

**4. [LEGIBILITY] Input-sanity explanations where an entry silently does something drastic.** 🟡 **Partly built
(2026-06-29, results-page notes; DECISIONS 2026-06-29 "input-sanity notes"):** `ResultPresenter::inputNotes` shows an
"A note on your inputs" heads-up for the two life-event foot-guns — a retirement age at/below the current age (no
salary modelled) and a death floored to the base year (a longevity/health age below the current age, via the new
`ForecastResult::deathCalendarYears`). **Still to do:** the **rate/£ validation** half — a live £-for-a-rate readout
and an out-of-range flag in the **builder** (the sale waterfall already shows the selling-cost rate beside its £, so
the 20% case is visible on the results page). The original spec follows. Retirement age
**≤** the person's current age → no earnings modelled at all (e.g. a person whose retirement age is at or below
their base-year age → their whole salary is dropped from year one). Longevity **offset** landing below current age → floored at current age =
"dies within the year" (P2, median 88, −15 → 73 → clamped to 80). Surface a neutral note at the input and/or on
the results so the consequence is visible, not discovered in a collapsed forecast. **Rate / percentage inputs must
show their resulting £ live and flag values far outside typical ranges** — a selling-cost rate was
entered as `20` and silently applied as **20% of the sale price** (~10x the typical 1–3%), and rent was
entered as `1650` (≈ £137/month, almost certainly a *monthly* figure in an *annual* field). These two compound
with #1: the phantom housing costs and the under-stated rent partly cancel, so totals read plausible while being
wrong for offsetting reasons — the exact failure mode that destroys trust in the output.

**5. [LEGIBILITY] Results narrative — per-option plain-English "why", anchored to the milestones.** e.g.
"essentials fall short from 2027 because P2 is modelled to die that year, leaving ~£9.8k State Pension against
~£37k of planned spend." Factual, milestone-anchored, lint-safe (guidance, never a recommendation).

**6. [LEGIBILITY] Per-strategy cashflow ladder — show the year-by-year differences by housing strategy.** ✅ **Built
(2026-06-29), then the report UI superseded 2026-07-01:** the results-page **strategy selector was removed** — the
individual report is now **single-strategy** (the ladder shows the scenario's own variant, labelled) and cross-strategy
comparison lives on **Compare** via variant what-ifs (DECISIONS 2026-07-01 "What-ifs are the only way"). The
per-variant engine projection (`deterministicVariants` off `HousingComparison::variantInputs()`) survives and drives
Compare. **Original build record (2026-06-29; DECISIONS 2026-06-29 "Built #6"):** `ScenarioForecaster::deterministicVariants()` runs each strategy
through `DeterministicForecaster` on the variant household + settings from **`HousingComparison::variantInputs()`**
(the *same single source* the Monte Carlo comparison runs, so they can't drift; `stay_put` == the old
`deterministic()`). The results page gained a **strategy selector** driving the ladder + its milestones (default =
the scenario's own variant; a wide table makes a switch the right call over side-by-side, offering only meaningful
strategies — stay-put always, buy only with a buy price, rent only when a sale is set); the **house-sale milestone**
now lands (year 0, household-level) for a sell strategy; the **PDF** ladder follows the scenario's variant too. The
displayed-figure provenance invariant (panel == CSV == PDF) still holds, now on the *selected* variant. Income-floor
/ input-sanity notes deliberately stay on the raw (stay-put) projection. **Original spec follows.** The
"Year-by-year cashflow" table *was* a single deterministic projection of the household *as entered*
(`ScenarioForecaster::deterministic` ran the **raw** household; it did **not** apply the variant transforms, so it
reflected neither the sale nor the rent leg). Rob wanted the ladder to **showcase the differences between the housing
strategies** (stay put / buy cheaper / sell & rent) year by year — where the sale proceeds land, when rent starts,
when the mortgage + service charge stop on sale, how each strategy's usable wealth diverges.

**Results-page navigation (2026-06-29):** the page is long, so it gained a sticky **"on this page" side nav** (a
2-col grid on `lg+`, hidden on mobile) listing only the sections present this render, as real anchor links that work
without JS, with a CSP-safe `IntersectionObserver` scroll-spy (`resources/js/toc.js`). Browser-verified by Rob
(desktop); **mobile check deferred** to later in the dev timeline.

**7. [LEGIBILITY] Real-time cost toggles.** Let the user switch individual cost lines (and key assumptions) on/off
and see the forecast update live — e.g. toggle the mortgage, service charge or commute and watch the buy-vs-rent
answer move. Builds on the existing Livewire reactivity + the deterministic preview (cheap to re-run); a natural
companion to #1 (the same contingent-cost tags decide which lines are toggleable) and to the what-if/Compare feature.

**Guiding principle (Rob, 2026-06-29): trust comes from explanation.** "I can't trust the numbers because the
numbers have not been sufficiently explained by the output." So **explainability is the gate to trust, not a
polish item** — every headline figure must be traceable on screen to the inputs and assumptions that produced it
(show-your-working), or it cannot be trusted no matter how correct the engine is. This raises the workstream above
remaining go-live polish.

**Sequencing — original:** #1 first (it changes the numbers, and the legibility layers should explain *correct*
numbers); #6 builds the per-variant deterministic projection that #1's cost rules act on, so it pairs naturally with
#1; #2–#5 + #7 are the explanation/interaction layer and land incrementally on top. All stay education/guidance side
(banned-phrasing lint).

**Sequencing — revised 2026-06-29 (after Rob's browser pass; DECISIONS 2026-06-29 "everything user-editable" + docs/RESEARCH-editable-assumptions-ux.md).**
Rob chose to build the **legibility presentation layer first** (over #1), and it is **built** — the **sale explainer +
assumptions panel** (#3), **life-event milestones** (#2), the **results-page half of input-sanity** (#4) and itemised
per-year spend — pending his browser sign-off. His browser pass then set the remaining order:
1. ✅ **#1 contingent costs via option (b)** — auto-classify each expense line by category/label (mortgage / service
   charge / ground rent → while-owning; commute → while-working; else always) with a **per-line override** (the
   override *UI* is still to build; auto-classification gives the defaults today). The correctness fix; it changes
   the numbers and unblocks an honest buy-vs-rent.
2. ✅ **#6 per-variant deterministic ladder** — the projection #1's cost rules act on; also lands the house-sale
   milestone. **Built (2026-06-29).**
3. **Editable-assumptions layer** — make *all* thresholds/assumptions user-editable, deriving a user-tweakable
   **custom set** from the sourced presets. Subsumes **#7** (real-time toggles), the **per-line cost-condition
   override UI** (#1's remaining piece) and the **rate/£ half of #4** (builder validation).
   - ✅ **Core built (2026-06-29):** the six **economic assumptions** (investment growth blended-real, CPI, house /
     rent / salary growth, income yield) are editable on builder step 1, defaulting to the chosen preset and deriving
     a custom set stored as a **sparse `assumptionOverrides` delta** (engine `AssumptionSet::with*` + app
     `AssumptionOverrides` + applied once in `ScenarioForecaster::assumptions()`; results panel labels it
     *customised*). Reconciliation-tested. See DECISIONS 2026-06-29.
   - ✅ **All five follow-ons built (2026-06-30):** live in-builder preview · age-of-death / longevity-lever UX
     (modelled death year beside the lever) · decomposed editable cost components (agent + legal + EPC/removals,
     %/£ toggles) · the per-line cost-condition override UI (#1's remaining piece) · the per-line include/exclude
     toggle (#7's real-time cost toggles). See DECISIONS 2026-06-30 + HANDOVER Current state.
4. ✅ **Buy-vs-rent as a deliberate Compare / what-if — BUILT (2026-06-30,** one-click variant what-ifs + per-variant
   Compare**)**, with the per-option **#5** narrative shipped in advice mode as `Interpretation::compareNarrative`;
   then sharpened by the 2026-07-01 single-strategy-report restructure (DECISIONS 2026-07-01).

### Statement-driven onboarding + document import (2026-06-28) — PARKED, post-v1
Rob's ask: let the wizard **ingest uploaded documents** (bank statements, credit-card statements,
payslips, benefit/State-Pension statements), **pre-fill** every field it can extract, then **ask only
the remainder** — and build the budget from **what the household actually spends**, not "average user"
national figures. Full design + sector evidence: **[docs/RESEARCH-document-import.md](RESEARCH-document-import.md)**.
This is the correct framing of the [Ollama/local-AI question](RESEARCH-document-import.md): the model's
job here is **wrangling, not predicting**, and most of the feature is **deterministic matching**.

Key calls (settled in DECISIONS 2026-06-28):
- **Transfer-matching is deterministic-only.** The £1,258 "card payment looks like £2,516 of spend"
  case is an **internal transfer** — equal-and-opposite across the household's own accounts, matched by
  rules (opposite sign, equal pence, date window), **user-confirmed**, **excluded from spend**. This is
  the double-counting bug class the data-integrity rule exists to kill (DECISIONS 2026-06-25) — an LLM is
  the *wrong* tool for it (non-deterministic, unreliable arithmetic). Gets a reconciliation invariant +
  a real-file golden fixture with a known transfer pair.
- **Categorisation is rules-first; an LLM is an optional, walled-off, local-only assist** for the
  long tail of unknown merchants (rules handle 60–80% at perfect accuracy). A mis-tier never changes the
  grand total (completeness holds); statement data **never leaves the machine**.
- **Documents pre-fill different builder sections:** bank/CC → expense lines + recurring income +
  transfers; payslip → gross salary / pension contributions / NI / tax code; benefit statement →
  `IncomeStream` with **taxable vs tax-free** classified (the DLA-completeness rule applies). Extends the
  existing `PayAndExpenditures` mapping.
- **Actuals = the input baseline; PLSA stays the benchmark** (not the input). Imported spend is
  *today's* cost; the wizard marks which lines continue into retirement and the forecast adjusts (the
  mortgage ends, commuting stops, the spending smile).
- **Architecture:** an extension of `app/Import/` (a statement profile family producing
  `ImportResult::expenseLines` + `reconciliation`), app-layer only — the engine stays dependency-free.
  Output writes `builder_state.expenseLines` (the existing single source of truth). Open Banking
  (regulated AISP, online) is **out of scope** for the local-first v1; file import (CSV/OFX/QIF; PDF+OCR
  a flagged sub-phase) is the path.

**Phasing (each delivers alone; the model is the last, optional layer):** (1) deterministic
parse + dedup + transfer-matcher + reconciliation, tested — delivers the £1,258 fix + accurate spend
with zero AI; (2) rules categorisation → the 3 tiers + a review screen; (3) optional local-model
long-tail assist; (4) payslip/benefit income extraction; (5) wire it into the wizard's first step as
document-upload onboarding. **Gotchas** DI-1…DI-9 in the research doc (transfer double-count,
re-import dupes, over-annualising one-offs, saved-to-own-account double-count, tax-free drop, model
hallucination, data exfiltration, actuals-as-retirement-budget, PDF mis-read).

### In-app local-model assistant (2026-07-03) — Phases 1–2 BUILT, Phase 3 SPECCED, post-v1
Rob's ask: an in-page **"chatbot"** on a **local AI model** that (1) answers questions about the loaded
scenario + the project, (2) queues research requests, and (3) is the interface for building the
feature/task list. Full spec + feasibility: **[docs/RESEARCH-local-assistant.md](RESEARCH-local-assistant.md)**.
The correct framing (inherited from the document-import local-AI stance above): the model **explains and
captures, never predicts or calculates** — every number it says comes from the engine.

Key calls (Rob, 2026-07-03; recorded in DECISIONS 2026-07-03):
- **The model may only append to the dev backlog / work queue. It never builds anything, edits code, or
  offers to.** "Autonomous writes" = exactly one capability (`queueBacklogItem`, no confirm), to a
  dedicated attributed append-only store; everything else is read-only. Building stays a human/Claude Code act.
- **Local-only** (Ollama on localhost, already running with tool-calling models + `nomic-embed-text`) —
  the scenario is real financial PII and must never leave the machine (same rule as document-import DI-7).
- **Two retrieval modes:** scenario/figure questions use **tool-calls into the engine/`ResultPresenter`**
  (never embeddings — the user's own numbers must be structured, not hallucinated); methodology questions
  use **doc-RAG** over `docs/`. Mirrors `Interpretation` (sentences strictly from computed figures).
- **Two guardrails, both load-bearing:** G1 figure-grounding (a verification pass rejects any number not
  in the tool output); G2 the runtime phrasing partition (`OutputPhrasing::violations()` + the `interpret`
  gate). Safe in personal-use mode; a **flagged public-release blocker** (a runtime LLM can't be
  build-time-linted).

**Phasing (each delivers alone):** (1) ✅ **BUILT (2026-07-03)** grounded scenario-explainer with G1+G2 tested
(`App\Assistant\` + `ScenarioAssistant`, inert unless `config('assistant.enabled')`; prompt-stuffs the figure
snapshot rather than tool-calling; verified vs real `qwen3:14b`) — the real value; (2) ✅ **BUILT (2026-07-03)**
methodology doc-RAG (`nomic-embed-text`, hand-rolled cosine `DocIndex`, `assistant:index-docs`) — **prompt-stuff
top chunks, not intent-route**, over a **CURATED** methodology corpus (`config('assistant.methodology_docs')`), not
the whole `docs/` folder (live verification showed indexing planning prose surfaces build-status as "methodology"
and nomic's compressed cosines don't discriminate — LA-9); broader engine-computation methodology deferred to a
**/methodology page**; (3) research/feature **capture-and-route** — the `queueBacklogItem` write is the model's
only write and the ceiling of its agency. **Gotchas** LA-1…LA-9 in the research doc (ungrounded figure, banned
phrasing, PII exfiltration, "offers to build", Ollama-down silent failure, RAG-for-own-numbers, autonomous-write
corruption, right-number-wrong-meaning, planning-prose-as-methodology).

### Data Rob supplies for the demo couple (agree the shape now, needed at step 5)
Per person: DOB, employment status, (working partner) gross salary + planned retirement age + NI category, State Pension weekly forecast (or qualifying years) + deferral, sex (for life table). Per pension: type, and DC → value, contributions, access age, withdrawal plan; DB → accrued annual pension, NRA, revaluation + in-payment escalation, commutation option + factor, spouse fraction; State → weekly forecast/qualifying years + triple-lock assumption. Property: value, ownership, mortgage left, ever-let, running costs. Accounts: each ISA/GIA/cash balance + owner (+ GIA unrealised gain). Expenses: target annual spend split essential/discretionary + inflation basis + one-offs + survivor spend factor. Housing: assumed sale price, candidate purchase price, assumed rent + rent inflation. Region. Default assumption set. All anonymised.

---

## Verification (how we know it works)

- **Engine unit tests vs HMRC worked examples** (pure PHPUnit/Pest, exact pence):
  - **A. UFPLS £60,000, £20,000 other income, 25/26, rUK:** assert £15,000 tax-free + £45,000 taxable; taxable income £65,000 split across PA/20%/40%; then the **Month-1 emergency-tax** variant over-deducts at source, assert the over-deduction, the reclaim amount, and that **P55** applies.
  - **B. PCLS + drawdown, MPAA trigger:** DC pot £400k, 25% PCLS = £100,000 (within LSA, pcls_taken_to_date updated); £20k/yr drawdown triggers MPAA, further DC contributions capped at £10,000, carry-forward voided, warning emitted.
  - **C. Downsizing capital cliff:** sell home, £180,000 freed; assert home now assessable, PC tariff = (£180k − £10k)/£500 = £340/wk deemed income (pensioner rate), HB/CTS lost (over £16k), cliff warning emitted; boundary assertion at £15,900.
  - Plus SDLT bands incl. surcharge-on-overlap, PA £100k taper with a large UFPLS, dividend-rate difference 25/26 vs 26/27, SPA-from-DOB for someone born mid-transition, NI stopping at SPA.
- **Monte Carlo golden master:** fixed seed + fixed AssumptionSet + fixed inputs gives a byte-identical percentile-band array and success probability against a committed snapshot. Separate sanity test: zero volatility gives the deterministic result.
- **Feature/Livewire tests:** scenario-builder validation (salary required only if employed, reject negative pence, region forces Scottish-config-or-error); preview renders headline numbers as text; the fan chart's accessible `<table>` is present in the DOM; encryption round-trip (saved scenario decrypts to identical DTO); GDPR export returns all user data; GDPR delete hard-removes it; anonymous use writes nothing; the **banned-phrasing test** over all result templates.
- **Data-layer integrity tests (added 2026-06-25, see DECISIONS):** reconciliation invariants that
  assert aggregates equal the sum of their parts at every boundary — sum(imported monthly line
  items)×12 == reported essential spend; essential+discretionary == target; net sale proceeds ==
  sale − mortgage − costs − CGT; per-variant terminal wealth == liquid + property. Each spreadsheet
  profile is additionally pinned by a **sanitised real-file golden fixture** (a layout-faithful copy
  of the real workbook with fake figures, committed) so the double-counting class of bug is caught
  every build, not only by a manual run. Every displayed figure traces to one computed value
  (panel == CSV == interpretation), asserted by test. **Status: the importer guardrails
  (`tests/Fixtures/Import/GoldenWorkbooks.php` + `ImportReconciliationTest`), the displayed-figure
  provenance test (`DisplayedFigureProvenanceTest`) and the user-facing import reconciliation panel are
  all built (Tier-1 complete);** the importer guardrail caught and we fixed two double-count/mis-bucket
  bugs in the IWT CSP importer. **The PDF export surface is now also covered** by `DisplayedFigureProvenanceTest`
  (extended 2026-06-28 — it was the gap that let the PDF Monte-Carlo divergence slip through; see "Review findings" #1).
- **End-to-end:** run the app (`herd` + `php artisan serve` / Vite), build the demo couple, run a preview then a full 10k simulation, confirm live progress, and read the buy-vs-rent comparison with both fan charts and the lump-sum tax-shock panel.

---

## Critical files to create first
- `/packages/finance-engine/src/TaxYear/TaxYearConfig.php` — versioned, sourced, per-tax-year figure registry (25/26 + 26/27). The spine everything reads from.
- `/packages/finance-engine/src/Dto/` — canonical readonly DTOs shared by engine, storage and UI.
- `/packages/finance-engine/src/Pension/EmergencyTaxCalculator.php`, `Mpaa.php`, `UfplsCalculator.php` — the flagship pension-pitfall logic and P55/P50Z/P53Z determination.
- `/packages/finance-engine/src/Mortality/JointLifeSampler.php` (+ `CohortLifeTable.php`) — last-survivor death sampling feeding the paths.
- `/packages/finance-engine/src/MonteCarlo/Simulator.php` (+ `PathGenerator.php`, `ReturnModel.php`) — seeded, reproducible paths feeding both housing variants.
- `/packages/finance-engine/tests/` — HMRC worked-example tests A–C + the Monte Carlo golden master that gate the whole build.

---

## Risks / open items to watch during build
- Joint-life mortality + per-path tax + two housing variants is the heaviest part computationally. Keep the inner loop tight; the 10k run must stay responsive with live progress.
- ✅ **DONE 2026-06-27.** The gov.uk figure-verification pass is complete: every statutory figure was re-confirmed against gov.uk and stamped `verified_on: 2026-06-27`; no value changed. Only out-of-v1-scope items (Scottish bands, LBTT/LTT) remain unverified, and the region resolver throws for those.
- Scotland (income tax + LBTT) is out of v1; region resolver throws rather than guessing.
- The April-2027 pensions-in-IHT change is **now enacted** (Finance Act 2026, Royal Assent 18 Mar 2026, deaths on/after 6 Apr 2027); keep it behind the toggle.
- **2026-06-28 re-review findings — ✅ all resolved** (commits `fff3f07` + `86d5d82`; see "Review findings" above):
  `freezeEndYear` un-freezing implemented, GIA basis split drift-proofed, PDF/screen Monte-Carlo run aligned +
  provenance stamped + provenance test extended to the PDF, `medianDepletionYear` surfaced, cash-interest
  conservation test added.
