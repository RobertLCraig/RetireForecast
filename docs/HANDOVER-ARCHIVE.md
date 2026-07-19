# HANDOVER archive — RetireForecast

> The detailed per-feature build record and older session-log narrative, moved out of
> HANDOVER.md on 2026-07-10 to keep the live handover small enough to load each session.
> Nothing here is load-bearing for picking up the project (the live HANDOVER.md carries
> that); this is the "how we got here" detail. Each item also has a dated DECISIONS.md
> entry and the full record in `git log`. Newest-first within each part.

## Multi-agent coordination (lanes CLOSED 2026-07-02 — single-session tree)
The concurrent A/B/C/D lanes are **closed**; this is a single-session tree again. The full per-lane build record
lives in `git log` + DECISIONS.md (2026-06-30 / 07-01). High level: **Lane A** post-v1 backlog
(annuitisation, historical stress-test, ONS mortality-refresh, care-cost stochasticity) — complete; **Lane B**
forced-housing workstream (means-tested benefits, mortgage-redemption + payment-stop, feasibility flags, input
clarity, **in-place forced sale**) — **complete** (the forced sale built 2026-07-03,
[docs/PLAN-in-place-forced-sale.md](build/PLAN-in-place-forced-sale.md)); **Lane C** withdrawal sequencing — core
shipped, #5/#6 handed off ([docs/PLAN-withdrawal-sequencing.md](build/PLAN-withdrawal-sequencing.md)); **Lane D**
multi-property — docs-only DRAFT awaiting Rob ([docs/PLAN-multi-property.md](build/PLAN-multi-property.md)). If lanes ever
reopen, honour [[concurrent-session-split]] (re-check `git` first, commit only your files, never push without Rob's
go-ahead). `PathProjector` was the cross-lane contention point.

## Build record ("Done" bullets, moved from Current state)

- **Done — deterministic engine:** money layer; per-year `TaxYearConfig`/`TaxYearRegistry` (2025-26 + 2026-27, England/Wales/NI; Scotland throws); income tax (combined savings/dividend stacking) + NI; pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + P55/P50Z/P53Z, MPAA, AA + taper) — **worked examples A & B**; State Pension (SPA-from-DOB, deferral, triple lock); SDLT (+surcharge) + CGT (PRR); benefits capital tariff + £16k cliff — **worked example C**; IHT (pensions-in-estate toggle) + care means-test. Plus DTOs, `AssumptionSetLibrary` (3 sourced sets), ONS cohort mortality, `Forecast/` (`PathProjector` + deterministic + Monte Carlo), `Housing` buy-vs-rent on identical seeds. **A5** (GIA/cash income tax + CGT-on-disposal) complete.
- **Done — app layer:** encrypted DTO persistence + Fortify auth + GDPR export/erase + Filament admin; forecast/scenario services (`ScenarioForecaster`, `SimulationRunner` + queued `RunScenarioSimulation` with progress + cancel); Livewire UI + ApexCharts (auth screens, builder wizard, results page, Compare); compliance layer (partition lint, first-run disclaimer gate, walled-off interpretation toggle); spreadsheet import (CSV/`.xlsx`, calibrated profiles); lump-sum tax-shock panel; compare-assumptions overlay.
- **Done — rebuild:** Phase A (engine enrichments: ongoing contributions, longevity, usable-vs-total wealth, income-by-source), C3 (results usable-vs-total + cashflow ladder), B (`builder_state` storage inversion + edit-in-place + stale-run invalidation), C2 (delta-child what-ifs + Compare), C1 (3-tier line-item budget, core + fast-follow), C4 (PLSA Retirement Living Standards benchmark).
- **Done — Phase D Tier-1 (trust), COMPLETE:** A5; the gov.uk ⚠️ figure-verification pass (every statutory figure re-confirmed + stamped `verified_on: 2026-06-27`, no value changed, pensions-in-IHT now enacted); admin-panel lockdown (`is_admin`); forecast-boundary reconciliation invariants; displayed-figure provenance; the user-facing import reconciliation panel.
- **Done — Phase D Tier-2 (go-live polish), BUILD COMPLETE:** demo preset/seeder; 10k-path Monte Carlo perf (lean `IncomeTaxCalculator::totalPence()` + worker JIT); CSP + security headers; 2FA enrolment UI; PDF results export (dompdf, reuses `ResultPresenter`); a11y CI scaffold + a first local sweep (3 contrast fixes); queued-run "waiting for a worker" hint (`SimulationRun::isAwaitingWorker()`).
- **Done — adviser-legibility presentation layer (2026-06-29):** the house-sale waterfall (`ResultPresenter::saleExplainer` + `HousingPurchase`), the assumptions panel (real-vs-nominal, engine's blended return), itemised per-year spend, life-event milestones (`ForecastResult::deathCalendarYears`), input-sanity notes. Each carries a reconciliation/labelling guard; all guidance-only.
- **Done — #1 contingent-cost correctness fix (2026-06-29, option b):** an expense line carries an auto-classified condition (mortgage/service charge → while owning; commute → while working); `ExpenseProfile` gains `propertyCosts`/`employmentCosts` markers; sell variants build with `withoutPropertyCosts()`; `PathProjector` drops the commute when no one earns; `HousingComparison::variantInputs()` is the single source of the three variant households; PLSA excludes property costs on its outright-ownership basis.
- **Done — #6 per-variant deterministic cashflow ladder (2026-06-29):** `ScenarioForecaster::deterministicVariants()` runs each housing strategy through `DeterministicForecaster` on the variant household from `HousingComparison::variantInputs()`; results-page strategy selector; the house-sale milestone lands at year 0; the PDF ladder follows the scenario variant.
- **Done — builder highlights a what-if's changed inputs + shows the base value (2026-06-30):** each input differing from the base is ringed amber and shows "was £X"; `ScenarioBuilder::changedFromBase()` + a CSP-safe morph-aware `resources/js/builder-diff.js`.
- **Done — one-click "quick what-ifs" (2026-06-29):** "Retire 2 years later" / "Live 10 years longer" preset buttons via `QuickWhatIfController` + `App\Forecast\QuickWhatIf`, stored as ordinary delta-children.
- **Done — what-ifs highlight what they changed from the base (2026-06-29):** the "What this what-if changes" panel (base → new), dashboard change tags, Compare change chips; one presenter `App\Forecast\WhatIfChanges` + `BuilderStateDelta::valueAt()`.
- **Done — editable-assumptions layer, core (2026-06-29):** the six economic assumptions editable on builder step 1, stored as a sparse `assumptionOverrides` delta; engine `AssumptionSet::with*` + `App\Forecast\AssumptionOverrides::apply()`, applied at the single point `ScenarioForecaster::assumptions()`.
- **Done — results-page "on this page" side nav (2026-06-29, desktop-verified):** sticky 2-col grid nav on lg+, CSP-safe IntersectionObserver scroll-spy (`resources/js/toc.js`). Mobile check deferred by Rob.
- **Done — editable-assumptions layer UI (2026-06-30):** a sticky live in-builder preview (one cheap deterministic forecast on a transient scenario); modelled age at death beside each lifespan lever; selling costs decomposed into per-component %/£ lines (`SellingCostComponent`); the per-line cost-condition override ("Applies"); the per-line include/exclude toggle.
- **Done — buy-vs-rent compare + personal-use advice mode (2026-06-30):** a "Compare buy vs rent" button (`BuyVsRentCompare` + `BuyVsRentController`); `config('compliance.personal_use')` (default true) turns the `interpret` Gate on for everyone; `Interpretation::compareNarrative` ranks the plans.
- **Done — what-ifs can add/remove items (2026-06-30):** an added row stored whole at its id path, a removed row as a `BuilderStateDelta::REMOVED` sentinel.
- **Done — partial-PRR CGT on selling a let former home (2026-06-30):** occupation-driven (gov.uk HS283) — `CgtHistory` on the engine `Property`, `CgtPrivateResidenceCalculator` extended for joint owners, wired into `HousingComparison::saleProceeds`; a "Capital gains on sale" wizard.
- **Done — longevity distribution (2026-06-30):** `LongevityDistribution` on `SimulationResult` (last-survivor age p10/p50/p90, planning horizon, P(reach 95/100)); a neutral "How long the money may need to last" panel.
- **Done — source-freshness guardrail (2026-06-30):** a `figures:freshness` command (`App\Finance\FigureFreshness`) flags any tax year's gov.uk `verifiedOn` older than `--months` (default 12), non-zero exit for CI.
- **Done — per-year surplus/shortfall + configurable safety floor (2026-06-30):** the cashflow ladder classifies each year surplus/drawing/shortfall on usable money, flags years below a safety buffer (default 2 months of essentials).
- **Done — what-if sliders + retirement-year proration (2026-06-30):** an "Explore the levers" panel (retire/spend/return/live sliders, throwaway deterministic re-run); the engine prorates salary in the retirement year (`PathProjector::workFraction`).
- **Done — annuitisation (2026-07-01):** a DC pot can buy a lifetime annuity with part of its value at a chosen age; `AnnuityPurchase` DTO; rate is a user input (sourced ~7.2% default).
- **Done — historical stress test (2026-07-01):** a sequence-of-returns backtest replaying past UK real returns + inflation (`HistoricalSequenceDraws`, `HistoricalBacktester`); data = the JST Macrohistory database (CC BY-NC-SA, **flagged as a public-release blocker**).
- **Done — ONS mortality refresh + integrity guardrail (2026-07-01):** a `mortality:refresh` command verifying `OnsPeriodMortalityData` against its JSON source (5100 cells), flagging staleness, diffing a fresh ONS release.
- **Done — care-cost stochasticity (2026-07-01):** `CareCostSampler` draws a per-person end-of-life care spell; wired via `PathDraws::careAnnualCost` → charged as essential spend → `ForecastResult::careCostReal`; `Simulator` aggregates a `CareImpact`. Opt-in (`ForecastSettings::modelCareCost`).
- **Done — in-place forced sale (2026-07-03):** a `ForcedSale` home is sold in place at the redemption year (`PathProjector` event) via the shared `HousingProceeds::compute`; frees net proceeds into GIA, clears home + debt, stops housing costs, charges the entered rent. `ForcedSaleTest` pins wealth conserved to the penny.
- **Done — mortgage-maturity is a user-modelable input (2026-07-03):** `mortgageMaturityAction` + `mortgageRedemptionYear` editable in the builder (refinance / repay-from-capital / forced-sale); the "Stay put" plan resets a `forced_sale` to `refinance`.
- **Done — local-model assistant, Phase 1 (grounded scenario-explainer) (2026-07-03):** an in-page results-page "chatbot" on a local model (Ollama). `App\Assistant\`: `OllamaChatClient` behind `ChatClient`; `ScenarioContext` (the engine figure snapshot + grounding allow-list, carrying the ladder, MC probabilities, lump-sum shock, home-sale waterfall); `FigureGrounding` (G1); `OutputPhrasing` (G2); `AssistantService` with one corrective retry. Inert by default (`config('assistant.enabled')`). A docked side panel.
- **Done — local-model assistant, Phase 2 (methodology doc-RAG) (2026-07-03):** local embeddings (`nomic-embed-text`); `EmbeddingClient`, `DocChunker`, `DocIndex` (hand-rolled cosine, no vector DB), `MethodologyRetriever`; `assistant:index-docs` builds a gitignored index. **The corpus is CURATED** (LA-9) — limited to the sourced-methodology docs.
- **Done — /methodology page + doc (2026-07-03):** `docs/METHODOLOGY.md` — one source, two homes (the `/methodology` page + the assistant's methodology corpus). Written from a code-grounded engine survey.
- **Done — local-model assistant, Phase 3 (idea capture) (2026-07-03):** the "Ideas" tab captures a reader's idea to `assistant_backlog_items` (append-only, attributed, reversible) via `BacklogCapture`. The model never builds; `assistant:backlog` lists the queue.
- **Done — wealth-over-time charts continue below £0 (funding gap) (2026-07-04):** `SimulationResult::netPositionFanChart` = usable − Σ unmet spend (one continuous line dipping negative); `charts.js` `gbpAxis` sign-aware.
- **Done — Compare page shows live Monte-Carlo progress (2026-07-04):** `ScenarioCompare` tracks the batch run IDs and polls until every run is terminal (aggregate bar, per-plan bars, Cancel all, awaiting-worker hint).
- **Done — NI category is derive-default-plus-override (2026-07-04):** the per-person NI field shows only when Employed, defaults Standard, offers Standard/Reduced/Deferred, drops the auto over-SPA and not-liable options.
- **Done — IHT wired into the forecast, relationship-status aware (2026-07-04):** `ForecastSettings::modelIht` drives `PathProjector` to value the estate at each death (`Iht\EstateValuer`) → `ForecastResult::iht`; `Household::relationshipStatus` drives the spousal exemption + transferable band; `homeToDescendants` toggle; results IHT panel + Compare column + PDF; a Monte-Carlo `IhtDistribution`. See docs/PLAN-iht-and-relationship-status.md.
- **Done — "later-life care isn't modelled" heads-up (2026-07-04):** with the care toggle off, an amber note names the ~1-in-4 six-figure risk + points at the builder toggle.
- **Done — two Blade `word@if` gluing bugs fixed + guarded (2026-07-04):** a directive glued to a word char silently fails to compile; guarded app-wide by `BladeDirectivesCompileTest`. See [[blade-directive-word-glue-gotcha]].
- **Done — decision-support Phase 1 (queued threshold backend) (2026-07-05):** `App\DecisionSupport\ThresholdRunner` + `RunLeverThreshold` job + `ThresholdResult` model (run + store, inputs-hash keyed, two-layer edit-invalidation); owner-scoped CSV.
- **Done — decision-support Phase 2 ("How far can we go?" panel) (2026-07-05):** `App\Livewire\ThresholdExplorer` — instant deterministic net-position line, "Find the limit" queued threshold + green→red meter, a 10-dot natural-frequency pictograph, analyst full-sweep disclosure. `ThresholdPresenter` reuses `ResultPresenter::burndown`.
- **Done — decision-support Phase 3 (combination-comparison surface) (2026-07-05):** a Compare-page section scoring each plan on its latest completed run (word-band chip + net-position sparkline + drill-down + CSV); unordered in guidance mode, best-first + narrative behind the `interpret` gate.
- **Done — decision-support Phase 4 (survivor-cliff story + all 5 levers) (2026-07-05/06):** `ResultPresenter::incomeFloor()` survivor-year twin + before/after dumbbell; levers — DB survivor fraction, joint-life annuity survivor %, per-person longevity (first per-person-parameterised lever, `lever_param` column), defer the survivor's State Pension (required a State Pension deferral correctness fix), care on/off pinned (first settings-flipping lever, a pin-and-compare not a sweep).
- **Done — decision-support Phases 5 + 6 (2026-07-07):** the 2-D trade-off map (buy-price ceiling at each held retirement age, success heatmap whose tint boundary is the iso-line); the assistant states computed limits, hash-gated (`ThresholdFacts` feeds fresh computed limits into `ScenarioContext`, muting stale rows). **Decision-support feature COMPLETE (Phases 0–6).**
- **Done — builder: a free-text "Note" on each Other-income row (2026-07-05):** an optional label, a pure visual aid (round-trips through `builder_state` but reaches no engine figure).
- **Done — age-varying spending "smile" (2026-07-06):** a `SpendPath` value object (piecewise-real bands); `ExpenseProfile` holds essential/discretionary as `SpendPath`s beside the scalars (scalar == first band, enforced); `HouseholdAssembler` sums each line's bands; `PathProjector` charges at the reference person's age. A flat plan is byte-identical to the pre-smile engine. Builder band editor. See docs/RESEARCH-under-spending-smile.md.
- **Done — equity-release lifetime mortgage modelled + two V2 what-ifs (2026-07-06):** `Property::mortgageRollUpRate` makes an unpaid lifetime-mortgage balance roll up (NNEG-capped), feeding the IHT estate; `YearResult::mortgageBalance` keeps the debt visible.
- **Done — the assistant turn is queued to the worker (2026-07-08):** `ask()` persists a transient encrypted `assistant_turns` row + dispatches `RunAssistantTurn`; the panel polls at 1.5s; context assembly moved to `AssistantTurnRunner`. Fixed the synchronous-generation 504. **Assistant answers now need the queue worker running.**
- **Done — all reported wealth is NET of the mortgage (2026-07-08):** `YearResult::totalWealth` derived in the constructor as liquid + pension + home equity (NNEG-floored); the then-identical `netWealth()` deleted; every surface inherits; `ENGINE_VERSION` → `finance-engine/phase-3-net-wealth`. Guard `WealthReconciliationTest`. All 15 what-if children renamed to state their modelled changes.
- **Done — care years are means-tested in the projection (2026-07-08):** `CareMeansTest::annualCharge()` is the one home for the charge rule; `PathProjector` assesses per resident (England's individual assessment); the PEA is a new sourced figure (DHSC LAC circular). `ENGINE_VERSION` → `finance-engine/phase-3-care-means-test`.
- **Done — a bought home carries standard 1% maintenance (2026-07-08):** `HousingComparison::newHomeRunningCosts` applies a sourced 1%-of-value maintenance default when there is no scaled basis. `ENGINE_VERSION` → `finance-engine/phase-3-home-maintenance`.
- **Done — plan #23 child-help is tax-free (2026-07-08):** no work exchanged → a gift → `taxable=false`; disregarded for income tax, Pension Credit and the care means test.
- **Done — home-ownership costs can outpace inflation (2026-07-08):** `ExpenseProfile::propertyCostsRealGrowth` compounds the while-owning-home bucket at a real rate on top of CPI. The V2 base set to 1.5%.
- **Done — a let property's mortgage interest gets the BTL finance-cost tax reducer (2026-07-09):** `PathProjector` cuts household tax by 20% × min(mortgage interest, rental income) when `isLet` (the April-2020 restriction); #17's rental set to £1,800/mo, moving its depletion 2030 → 2035. `ENGINE_VERSION` → `finance-engine/phase-3-btl-finance-cost`.
- **RESOLVED — the queued Monte Carlo reproducibility bug (2026-07-09, re-verified 2026-07-10):** stored MC runs from `queue:work` used to recompute to a different success probability at their own seed (up to 14 points, intermittent). Root cause: SQLite could not handle the `database` queue driver's concurrent access. Fix: the app DB is now Postgres 18. See DECISIONS 2026-07-09 + 2026-07-10.

## Archived session log (older sessions)

_2026-07-09 (the let plan given the BTL finance-cost tax relief; #17 rental → £1,800/mo)_ —
Rob asked whether "Let out & rent elsewhere" (#17, 0%) counted rental income. It did (£17,500/yr
reaching the forecast as taxable), but the rent was taxed with no relief for the mortgage interest —
wrong for a BTL since the April-2020 finance-cost restriction. Built the 20%-of-min(interest, rent)
tax reducer on `isLet` properties (engine + `BuyToLetFinanceCostTest`); set #17's rental to £1,800/mo.
Both together move #17 from depleting 2030 → 2035, but it still fails: keeping the £208k mortgage AND
paying rent elsewhere is uncovered by any realistic rent — only selling clears the £208k. Reconsidered
and REJECTED the "let flat over-charges utilities" caveat I'd raised: charging the flat's council
tax/utilities is a fair proxy for the occupier costs they'd pay at the rented home (one set, not
double). Family re-run on the new stamp.

_2026-07-08 later (child-help resolved tax-free; bought-home maintenance built; a self-inflicted mortgage error caught and reverted)_ —
Rob confirmed the child's £300/mo involves no work → gift → tax-free; #23 flipped + renamed. Built the
bought-home 1% maintenance default (the buy plans had modelled zero upkeep on the new freehold because
the flat's upkeep lives in its stripped service charge). Then made a real mistake: I flagged the
stay-put plans' **£118k** mortgage as a "bug" versus the disposal plans' £208k, and changed the base to
£208k / £16,170.96 — **without reading docs/SCENARIO-V2.local.md**, which documents that the £118k is
Rob's deliberate 2026-07-03 design (the £208k BTL cannot be kept while occupied, so "Stay put" models a
~£90k paydown + BTL→residential RIO conversion). **Reverted** the base to £118k / £7,080. Rob's
current-mortgage figures (£208k BTL, £1,347.58/mo) confirm the model's starting point, not a base error.
Also corrected a separate self-error: the service-charge lever moves the base's depletion 2045 → **2043**
(not 2041 as I'd said; verified with/without growth). MC family left stale (maintenance bumped the
engine stamp) pending Rob's go-ahead to re-run for maintenance + #23.

_2026-07-08 later (the two open questions moved; the service-charge lever built + applied)_ —
Rob answered question (1): the child's £300/mo would be paid through the child's own business, which
he read as taxable. Research says the route doesn't decide it — support-not-wages stays a gift (no
income tax for the recipient; the company payment is the CHILD's tax problem, likely a drawing or a
close-company distribution, CTA 2010 s.1064/1069), while genuine work for the business IS taxable
earnings. **Plan #23's row stays `taxable` until Rob confirms which fact pattern applies** (flip it
in the builder or ask here); question (2) (£208k redemption) still open. Then Rob challenged CPI
cost growth with the flat's 12-year service-charge history: analysis showed 2.4%/yr vs ~2.9% CPI
long-run (real DECLINE, the recent £280 rises are post-surge catch-up) but ~CPI+1.5 real over the
last 3 years; his ruling "assume the worst" produced the **propertyCostsRealGrowth lever** (Done
bullet; DECISIONS + DATA-MODEL 2026-07-08), the V2 base set to 1.5% (children inherit; sell/rent
children naturally inert) and the family re-run on runs #321–336. Also verified in passing: the
service-charge line's empty condition auto-classifies safely to while-owning-home, and nearly every
V2 spend line is marked `essential` (incl. Netflix / lottery / charity) — flagged to Rob as worth a
reclassification pass since it inflates the essentials floor behind "Essentials covered", the safety
buffer and the survivor/care analyses.

_2026-07-08 late (resume: the care means-test tail built; V2 family re-run on the new stamp)_ —
Resumed from the handover; suite and git reconciled clean, and the "re-run before reading MC wealth"
carry-over was found already done (16 completed runs on the net-wealth stamp), so the stale note was
dropped. Then the first What's-next-#3 refinement, picked by value (every V2 plan models care): care
years are now charged at the household-borne means-tested amount instead of the gross self-funder fee
(DECISIONS 2026-07-08; the PEA sourced from the DHSC 2026-27 charging circular). The engine stamp
bumped, so the morning's 16 runs went stale for care figures; the family was re-queued headlessly
(runs #289–304, the same dispatch Compare's "Re-run all N" makes) and completed on
`phase-3-care-means-test`. Panel/builder/assistant copy updated to the household-borne reading; the
methodology doc rewritten for the means test and its v1 flags; the assistant doc index rebuilt (44
chunks). **The two input questions for Rob below remain open** (the taxable child-help row on the buy
plan; the £208k redemption figure).

_2026-07-08 evening (Rob caught the gross-wealth headline; all wealth went net; every what-if renamed)_ —
Compare's "most money at the end" answer credited the LTM roll-up combination with its home **gross**
(£595,694.63); Rob challenged it — a rolled-up lifetime mortgage consumes the equity. Root cause: the
2026-07-06 build kept `totalWealth` gross and surfaced `netWealth()` beside it, but nothing displayed
`netWealth()` and no test pinned the **terminal headline** — every "incl. home" figure (Compare, results,
PDF, CSV, MC percentiles, assistant) read gross. Fix (Rob: "all figures should be net; gross is
irrelevant… she can't eat the building"): the net-of-mortgage total is now derived in `YearResult` — one
definition, every surface inherits (Done bullet; DECISIONS + DATA-MODEL 2026-07-08). Corrected V2
ranking: best total "YCC works to 72 + full SP £241/wk, no disability benefit" £479,604.55; best
spendable "Sell (repay £208k) & buy cheaper £165k" £172,613.70; the old "winner" is really £277,187.58
(~£121k real equity survives the roll-up — house growth partly races the 6.5%). Stay-put totals dropped
~£75k (the static £118k repayment balance, real-deflated, now visibly counts — see the new v1 limit).
Then **all 15 what-if children renamed** to state their modelled changes (worst: "Let to Rent + Retire 5
years later" contained no letting — it models retire-at-72 + full SP + disability benefit off). **Two
input questions for Rob:** (1) the buy plan's child-help income is stored **taxable** while the stay-put
ones are tax-free — a gift isn't taxable income, so that plan is likely under-credited; (2) every
disposal plan raises the mortgage owed £118k → £208k at sale — confirm £208k is the true redemption
figure. Scratch scripts (dump/rename/re-run) in the session scratchpad, not the repo.

_Older sessions (2026-07-08 afternoon and earlier)_ cover: the assistant-504 fix plus the composed
LTM/part-time what-if; the freshness-CI and a11y housekeeping; decision-support Phases 0 to 6; the
spending "smile"; equity release; IHT wired into the forecast; the local-model assistant (all three
phases); the Phase A/B/C/D builds; the five silent-drop completeness fixes; and the personal-data
scrub. Each has its Done bullet above, a dated DECISIONS entry, and the full record in git log.
