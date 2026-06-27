# UK Retirement / Downsizing Forecast Tool — Implementation Plan

## Context

Rob wants a UK financial-forecasting **decision-support tool**. The flagship worked example: an older couple (one still working, one retired) deciding whether to sell their home and either **buy somewhere cheaper outright** (no mortgage, invest the surplus) or **sell and rent** (hold all proceeds invested). The tool must make the consequences of **pension lump-sum withdrawals** visible (the tax shock most people walk into) and forecast whether the money **lasts for life** via Monte Carlo simulation.

The problem it solves: people in this situation make irreversible six-figure decisions (drawing a pension pot, selling a home, signing a tenancy) on gut feel, and the tax and benefit traps are invisible until it is too late. The intended outcome is a tool that lets them drive their own numbers and *see* the consequences, framed as **education/guidance, never regulated advice**.

### Locked decisions
- **Stack:** Laravel (latest stable) + Livewire 3, Filament for admin, Fortify for auth, Redis + Horizon for queues.
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

**Means-tested benefits:** Pension Credit capital, first **£10,000 disregarded**, then **deemed income £1/wk per £500** (pensioner tariff, not the working-age £6,000/£250 rule), **no upper limit for PC itself** ✅; **HB / Council Tax Support: same tariff but £16,000 upper cut-off** ✅; **downsizing converts an exempt home into assessable capital** (the killer interaction, warn on crossing £16,000) ✅; deprivation-of-capital surfaced as information only, never a recommendation. Capital tariff (£10k disregard, £1/wk per £500, £16k HB/CTS cut-off) ✅ verified 2026-06-27. (The PC weekly Guarantee Credit *payment* rates are not modelled — the engine models only the capital-tariff interaction, not the benefit award — so there is no GC figure to verify.)

**IHT (toggle):** NRB £325,000 ✅ (frozen to 5 Apr 2031), RNRB £175,000 ✅ (frozen to 5 Apr 2030) with £2m taper ✅, spousal transfer (up to £1m for a couple) ✅ structure (all verified 2026-06-27); **unused pension pots enter the IHT estate from 6 April 2027** ✅ — **now enacted** (Finance Act 2026, Royal Assent 18 Mar 2026), upgraded from "proposed"; model behind the toggle.

**Care (secondary):** England means-test upper £23,250 / lower £14,250 ✅ (verified 2026-06-27, frozen 15th year; £1/wk per £250 between limits); the £86k cap reform was **cancelled July 2024**, not in force (confirmed 2026-06-27), so not modelled; deprivation risk mirrors benefits.

**Build-time verification checklist — COMPLETED 2026-06-27** (was ⚠️): NI 26/27 ✅; tapered-AA thresholds ✅; LSA + LSDBA ✅; access-age-57 date (6 Apr 2028) ✅; CGT residential rates + £3,000 AEA ✅; PC/HB capital tariff (£10k/£500/£16k) ✅; IHT NRB/RNRB + £2m taper ✅ (NRB frozen to 5 Apr 2031, RNRB to 5 Apr 2030) + the **April-2027 pensions-in-estate change now enacted** (Finance Act 2026) ✅; care thresholds ✅ (£86k cap cancelled). Still out of v1 scope (not verified, region resolver throws): **Scottish income-tax bands** and **LBTT/LTT** (Wales/Scotland property taxes). Each ✅ figure carries its gov.uk citation + `verified_on: 2026-06-27` in config. See DECISIONS 2026-06-27.

---

## Monte Carlo + mortality

- **Real terms throughout** (user thinks in today's money), but model **inflation as its own stochastic series** to convert to nominal for the tax interaction: frozen nominal thresholds mean **real fiscal drag** is a modelled feature, not a footnote.
- **Correlated annual real returns, lognormal compounding** (Cholesky on the correlation matrix). Annual step.
- **Joint-life mortality:** sample each partner's age of death per path from **ONS cohort life tables** (by sex/age), independent by default (a documented "broken-heart" correlation option later). Household runs until the **last survivor** dies (hard cap age 105). On first death: one State Pension stops, DB survivor fraction kicks in, spend drops by `survivor_spend_factor`, single-person council-tax discount applies. So each path carries both a return sequence and a mortality outcome.
- **Defaults are sourced, never invented.** Asset classes (global equities, gilts/bonds, cash) and inflation each carry expected real return, volatility and a `source` string, editable in admin/UI. Presets: FCA-derived (default), DMS/EGS-derived, OBR/BoE-inflation-blended. A **compare-assumptions** view overlays them, each labelled with its source and method.
- **Outputs:** 10/25/50/75/90 percentile fan; **success probability** reported two ways (essentials always met; full target met); **depletion-age distribution** (at what age money runs out across failing paths); a dedicated **sequence-risk** surface (the p10 path's first-five-years drawdown), not buried.
- **Reproducibility:** seeded PRNG (`\Random\Randomizer` with `Mt19937(seed)`). Seed set gives byte-identical results for golden-master tests and shareable runs; seed always recorded.
- **Performance, no silent long-runs:** preview ~1,000 paths synchronous (~1–2s, still shows progress); full 10,000 paths queued (Horizon), chunked 10×1,000 so progress is granular and cancellable. UI shows live progress via Livewire `wire:poll` (~1s) for v1, with a cancel; Reverb/Echo push is a later upgrade. Pre-draw the random matrix; convert to `Money` only at year boundaries.

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
- ⬚ **Surface the headline outputs still missing from the results page (step 4 — STILL OPEN).** (a) The
  **lump-sum tax-shock panel** — headline output #1, already computed by
  `ScenarioForecaster::deterministic()`'s first-year tax, just not rendered yet; (b) the
  **compare-assumptions overlay** — a run per assumption set feeding a third chart + accessible table
  (`ScenarioForecaster` already takes the set, so it is a loop over sets). Do these when the results
  page is next touched.
- ✅ **"No silent failure" hardening — BUILT (steps 3–4).**
  - *GDPR export* now includes the user's `simulation_runs` + `results` (decrypted, portable); erase
    cascades (user_id FK on households/scenarios/simulation_runs, results via simulation_run_id); tests
    cover runs+results in **both** export and erase.
  - *Dead worker:* `RunScenarioSimulation::failed()` marks the run `Failed` with the reason so a
    timeout / OOM / killed worker reaches a terminal status instead of stranding the page on `Running`.
  - *Owner-scoping:* `ScenarioResults::currentRun()` now scopes by `user_id`, so a forged `$runId`
    cannot load another user's run.
- ⬚ **Accessibility + form UX, against the mandatory WCAG 2.1 AA bar (STILL OPEN — step 6 a11y audit).**
  The scenario builder's field errors are not programmatically associated (`aria-describedby` /
  `aria-invalid` missing on invalid inputs), the top-of-form error list has no focus-to-first-error,
  Save has no double-submit guard / loading state (a fast double-click creates two forecasts), and
  there is no `endAge ≥ startAge` cross-field check or draft-save on the long form. Do a focused a11y
  pass and wire axe/Pa11y in CI per step 6.

### External review triage (2026-06-25) — post-v1 enhancement backlog
A second-opinion review (MS Copilot, from the doc set) was triaged. Much of it re-surfaced our own
deferred "v1 modelling refinements" (GIA/cash income tax + CGT-on-disposal — **now built (A5, 2026-06-27)**;
stochastic house/salary growth, post-2031 reindexing, per-scheme DB escalation — still logged) or things already built (login
rate-limiting is in `FortifyServiceProvider`; the first-run acknowledgement + banned-phrase lint are
the planned compliance step). The genuinely new, aligned items, kept as a post-v1 backlog:

- **Outputs that exploit engine results we already compute (cheap, high adviser-value):** a
  **cashflow timeline table** (income-by-source / spend / net / balance straight from `YearResult`);
  a **longevity distribution** visual (median / p10 / p90 last-survivor age, P(live past 95) from the
  joint-life sampler); a **stress-test panel** feeding historical sequences (1973–74, dot-com, GFC,
  1970s inflation) through the deterministic engine; **what-if sliders** (±retirement age, ±spend,
  year-1 market shock, longevity shock).
- **Modelling depth (v2 scope):** an **annuitisation option** (partial/level/escalating, joint-vs-single
  — priced off the mortality tables we already have) and **care-cost stochasticity** (uncertain entry
  age / duration / weekly cost) for a more realistic tail.
- **Neutral diagnostics — adopt ONLY behind the `OutputPhrasing` lint:** implied per-year withdrawal
  rate (as a fact, **not** "compare to a safe 3–4% range" — that reads as a target), critical yield,
  replacement rate, a neutral narrative-report generator, a capacity-for-loss *definitions* panel.
  These edge toward the advice line, so in the public/neutral view they stay strictly "here is the
  number / the definition"; their directive form (e.g. withdrawal-rate-vs-range) is available only via
  the admin-granted interpretation toggle (§Regulatory/compliance, DECISIONS 2026-06-25).
- **Hardening + process (cheap):** a **Content-Security-Policy** header (charts are embedded; none set
  today); an optional tamper-evident SHA-256 over each run's assumption snapshot + seed + input DTO
  (reproducibility/audit); a build-time **source-freshness** check (fail/warn if any `verified_on` is
  older than N months) extending the gov.uk verification pass; an **annual ONS mortality refresh**
  ingest script + diff; caching deterministic forecasts by input hash (pure function).

**Declined (over-engineering or misaligned for a local-first single-user tool) — see DECISIONS 2026-06-25:**
per-row/envelope encryption, a native (Rust/WASM/SIMD) Monte Carlo accelerator, and automated gov.uk
scraping.

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
  (panel == CSV == interpretation), asserted by test. **Status (2026-06-25): the importer guardrails
  are built** (`tests/Fixtures/Import/GoldenWorkbooks.php` + `ImportReconciliationTest`); they caught
  and we fixed two double-count/mis-bucket bugs in the IWT CSP importer. The displayed-figure
  provenance test and the import reconciliation panel are still to do.
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
