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

One immutable `TaxYearConfig` per tax year via `TaxYearRegistry::for('2025-26')`; region income-tax bands from a `RegionProfile`. **Every figure carries a `source` URL and `verified_on` date.** A build-time job confirms each ⚠️ item against gov.uk before go-live. ✅ = web-verified 2026-06-24.

**Income tax (England/Wales/NI default):** PA £12,570 ✅ (taper £1/£2 over £100k, gone by £125,140 ✅); basic 20% to £50,270 ✅; higher 40% to £125,140 ✅; additional 45% above ✅; **freeze to April 2031** (corrected). Ordering: non-savings then savings then dividends.

**Scotland (flag, region-pluggable):** own bands/rates on non-savings, non-dividend income; savings/dividend/NI/pensions stay UK-wide. Ship rUK first. **If region=scotland and no Scottish config loaded, throw, never silently apply rUK bands.** ⚠️ verify Scottish bands at build.

**Savings and dividends:** PSA £1,000 basic / £500 higher / £0 additional ✅; starting-rate-for-savings £5,000 at 0% tapered ✅; dividend allowance £500 ✅; **dividend rates 25/26 = 8.75/33.75/39.35%, 26/27 = 10.75/35.75/39.35%** (keyed by tax year) ✅.

**National Insurance (working partner):** Class 1 employee 8% between £12,570 and £50,270, 2% above ✅ (⚠️ confirm 26/27). **NI stops at SPA; pension income bears no NI** (guard against charging it).

**Pensions (the headline pitfalls):** 25% tax-free PCLS subject to **LSA £268,275** ✅ (track pcls_taken_to_date); UFPLS 25% tax-free / 75% taxable per chunk ✅; drawdown taxable as earnings, no NI ✅; **Month-1 emergency-tax on the first flexible withdrawal** (non-cumulative code treats a one-off as 1/12 of annual income, large over-deduction at source) ✅, model the over-deduction *and* the reclaim, and report which form applies: **P55** (part-withdrawal, pot not emptied, other income), **P50Z** (whole pot emptied, no other PAYE income), **P53Z** (whole pot emptied, still has other taxable income) ✅; **MPAA £10,000** ✅ (triggered by flexible access of DC, not PCLS-only; caps money-purchase AA and kills DC carry-forward, warn when a planned withdrawal trips it while the working partner still contributes); Annual Allowance £60,000 ✅, tapered AA −£1/£2 over £260k adjusted income, floor £10k ⚠️; access age 55 now, **57 from 6 Apr 2028** (model the step).

**State Pension:** new SP full rate £230.25/wk (25/26) to £241.30/wk (26/27, +4.8% triple lock) ✅; basic SP £176.45 to £184.90/wk ✅; **SPA 66 to 67 rising 6 May 2026 to 6 Apr 2028 by DOB, computed from DOB not hard-coded** ✅; deferral uplift ~5.8%/yr ✅; **State Pension is taxable, fed through the income-tax calc** (the full new SP now sits just under the frozen PA, so many start paying tax on it around 27/28) ✅.

**CGT — Private Residence Relief:** main-home sale fully relieved, no CGT ✅. Edges: `ever_let` restricts relief; a second/non-main property is chargeable; final 9 months always relieved. CGT on GIA holdings separate (rates ⚠️ verify post-Oct-2024; AEA £3,000 ⚠️).

**SDLT (buying the cheaper home, England/NI):** 0% to £125k, 2% to £250k, 5% to £925k, 10% to £1.5m, 12% above (from 1 Apr 2025) ✅; **+5% additional-property surcharge** if they own two homes momentarily (buy before sell), reclaimable within 36 months, model the timing ✅. Scotland/Wales use LBTT/LTT (different taxes) ⚠️, swap by region, ship SDLT first.

**Means-tested benefits:** Pension Credit capital, first **£10,000 disregarded**, then **deemed income £1/wk per £500** (pensioner tariff, not the working-age £6,000/£250 rule), **no upper limit for PC itself** ✅; **HB / Council Tax Support: same tariff but £16,000 upper cut-off** ✅; **downsizing converts an exempt home into assessable capital** (the killer interaction, warn on crossing £16,000) ✅; deprivation-of-capital surfaced as information only, never a recommendation. PC weekly Guarantee Credit rates ⚠️ verify 26/27 uprating.

**IHT (toggle):** NRB £325,000 ⚠️, RNRB £175,000 ⚠️ with £2m taper, spousal transfer (up to £1m for a couple) ✅ structure; **unused pension pots enter the IHT estate from April 2027** ⚠️ (directly relevant to draw-down vs preserve), model behind the toggle.

**Care (secondary):** England means-test upper £23,250 / lower £14,250 ⚠️ (the £86k cap reform appears scrapped/delayed, do not assume it is in force); deprivation risk mirrors benefits.

**Build-time verification checklist (⚠️):** Scottish bands; NI 26/27; tapered-AA thresholds; LSDBA; access-age-57 date; CGT rates + £3,000 AEA; LBTT/LTT; PC/HB weekly rates 26/27; IHT NRB/RNRB freeze-end + the April-2027 pensions-in-estate change; care thresholds. Each ✅ figure still gets a gov.uk citation in config.

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

- Generic guidance only, user-driven inputs, illustrative consequences. Never "you should".
- **Disclaimers:** persistent global footer; a first-run modal the user acknowledges (timestamp stored for logged-in users); a per-result disclaimer block on every Result render and every export; a reusable **signposting component** (Pension Wise, MoneyHelper, "find an FCA-regulated adviser") beside every pension-withdrawal or benefits output.
- **Wording guard:** an `OutputPhrasing` lint with banned recommendation patterns ("you should", "we recommend", "the best option", "is better for you"); all user-facing result strings pass through a neutral formatter ("Under these assumptions...", "This illustrates...", "One consequence is..."). **An automated test fails the build if any Result/warning template contains a banned phrase.** Comparisons present both options neutrally.
- **Audit trail:** every saved run stores the assumption snapshot + tax-year config version + engine version, so any output is demonstrably input-driven, not advice. The deprivation/care/IHT outputs (highest-risk for sounding like advice) stay strictly "here is the rule and how your numbers interact with it" plus a hard signpost.

---

## Build phasing (one deliverable, sane internal order)

1. **Engine, test-driven, no UI.** Money VO; TaxYearConfig 25/26 + 26/27 with sourced figures; IncomeTax/NI/Savings/Dividend; Pension (PCLS/UFPLS/drawdown/emergency-tax/MPAA/AA); SP-from-DOB; CGT-PRR; SDLT; Pension Credit capital; IHT/care. Worked examples A–C (below) are the acceptance gate.
2. **DTOs ↔ persistence + auth.** Eloquent models, encrypted payload, Fortify, anonymous-vs-saved, GDPR export+delete, Filament admin for AssumptionSet + tax-year config audit.
3. **Forecast stepper + mortality + Monte Carlo.** Deterministic annual cashflow first; then joint-life sampler, correlated returns, seeded reproducibility, percentile aggregation, success prob, depletion distribution, golden-master, queue + progress.
4. **UI / charts.** Livewire scenario builder; preview (sync) vs full (queued + `wire:poll` progress + cancel); ApexCharts fan + buy-vs-rent + compare-assumptions, each with the mandatory accessible table; headline numbers as text.
5. **Demo preset** (the anonymised couple, data supplied by Rob, fields listed below).
6. **Polish:** compliance hardening, PDF export with disclaimers, a11y audit (axe/Pa11y in CI), 10k-path perf tuning, Scotland config pack if in scope.

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
- All ⚠️ figures need the gov.uk citation pass before anything is shown as real.
- Scotland (income tax + LBTT) is out of v1; region resolver throws rather than guessing.
- The April-2027 pensions-in-IHT change is still bedding in; keep it behind the toggle and re-verify before go-live.
