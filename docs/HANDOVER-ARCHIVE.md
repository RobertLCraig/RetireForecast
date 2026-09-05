# HANDOVER archive — RetireForecast

> The detailed per-feature build record and older session-log narrative, moved out of
> HANDOVER.md on 2026-07-10 to keep the live handover small enough to load each session.
> Nothing here is load-bearing for picking up the project (the live HANDOVER.md carries
> that); this is the "how we got here" detail. Each item also has a dated DECISIONS.md
> entry and the full record in `git log`. Newest-first within each part.

## Cards 0024 and 0028, moved out of the live handover on 2026-09-05

Folded out to keep HANDOVER.md loadable in one session. Nothing in either is load-bearing any more:
the figures and their sourcing gaps live in `docs/spec/ASSUMPTIONS.md` (§12), the gaps are carried
by cards 0085 and 0082, each `ENGINE_VERSION` stamp and what it moved is in the
`ScenarioForecaster::ENGINE_VERSION` docblock, and the owed re-runs are covered by the standing
"re-run every stored scenario" line at the head of the live handover's "What's next".

- **A mortgage payment is fixed nominal and not survivor-scaled, whatever shape the mortgage is.**
  Card 0024: the "Mortgage" expense line comes out of the CPI-and-survivor multiply always and is
  added back after it, so interest-only / RIO / buy-to-let / a serviced lifetime mortgage get the
  treatment the amortisation schedule already had. The Section 24 finance cost is nominal interest
  too. A borrowing plan's spend was overstated before this, so a figure stored under an earlier
  stamp is too pessimistic against selling and the ranked comparison moves. `ENGINE_VERSION` was
  `finance-engine/nominal-mortgage-payment`. The adjacent fault is card 0082: the Section 24 credit
  is still granted after the mortgage is redeemed or the home sold.

- **A service charge with no rate entered rises at CPI + 3%, and a major-works bill can die with the
  home.** `ExpenseProfile::propertyCostsRealGrowth()` supplies
  `DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` when the rate is null and the `while_owning_home` bucket
  is positive (an explicit rate, including zero, still wins), disclosed as an `assumed_figure` note
  reading that constant. A one-off cost row can carry `condition: while_owning_home`, so a Section 20
  demand is dropped on the buy/rent variants and after a mid-projection sale. Every stay-put plan
  carrying a service charge and no explicit rate spends more than it did before, so a figure stored
  under an earlier stamp is too favourable; `ENGINE_VERSION` was
  `finance-engine/property-costs-default-growth`. The 3% is the 2026-08-19 property reviewer's
  judgement, not a published series.

## Card summaries 0011 to 0016, moved out of the live handover on 2026-08-29

These were the tail of HANDOVER.md's "Last updated" line, which had grown to a 2,600-character
paragraph. Each also has a DECISIONS entry and the full record in `git log`; what still *stands* from
them is carried in the live handover, and the cards themselves are in `docs/board/`.

- **0016, adviser parity remainder** (the criteria it had): the engine now **uses** the ISA
  allowance as well as enforcing it (bed-and-ISA, on by default, disclosed as an assumed figure and
  switchable via `ForecastSettings::$useIsaAllowance`, so stored-scenario figures moved);
  contributions are capped by the annual allowance as well as the MPAA; and a non-earner can pay
  £2,880 for £3,600 of pot. A4 salary sacrifice, B3 the estate checklist and B4 the annual review
  were listed as tasks on that card, carry no acceptance criterion, and are **not built**.
- **0015, v1 refinements:** two of six closed (a resident's Pension Credit now counts into the care
  charge; CGT deemed-occupation absences are entered as such rather than by hand). The other four
  stay open on that card, three of them wanting a sourced modelling figure the repo does not hold.
- **0014, export all to PDF:** queued and built one forecast at a time past eight of them, arriving
  as a zip of one PDF each.
- **0013, nominal-pounds toggle** on the time-series charts, reading the projector's own
  pre-deflation year.
- **0011 capacity for loss** and **0012 release blockers**, the latter partly: nonce CSP and the
  guidance-only posture done, and a11y clean on every page a machine can reach with the
  unautomatable WCAG 2.2 criteria settled bar a human pass. The stress-test data licence and that
  human pass are still open.

## Per-feature build record, moved out of the live handover on 2026-08-01

These are the dated "Done" bullets that had accumulated in HANDOVER.md "Current state" until it
reached 1,007 lines. Each also has a DECISIONS.md entry and the full record in `git log`; the live
handover now carries a paragraph of present-tense state instead, and the work itself moved to
`docs/board/`. Newest first.

The full per-feature build record is in **[docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md)** (each item also has a dated DECISIONS entry + git history). High level:
- **Done — everything through the post-v1 backlog is built:** the HMRC-accurate deterministic engine (income tax + NI; the pension lump-sum suite incl. Month-1 emergency tax + reclaim; State Pension; SDLT/CGT/PRR; means-tested benefits; IHT; care) + Monte Carlo with stochastic joint-life mortality; the full app (encrypted DTO persistence, Fortify auth, GDPR, Filament, queued runs with progress/cancel, Livewire UI + charts, spreadsheet import, PDF export, 2FA, CSP); the rebuild (Phases A–D); the adviser-legibility presentation layer; **decision-support (Phases 0–6)** — lever thresholds, the "How far can we go?" panel, combination comparison, the survivor-cliff story + 5 levers, the 2-D trade-off map, the hash-gated assistant tie-in; the **local-model assistant** (grounded explainer + methodology doc-RAG + idea capture); **IHT wired into the forecast** (+ relationship status); the **care means-test tail**; the **age-varying spending smile**; the **equity-release lifetime mortgage**; the **BTL finance-cost tax reducer**. Nearly all of the post-2026-06-29 cluster **awaits Rob's browser sign-off** (What's next #1).
- **Done 2026-07-16 — no-magic-money purchase funding (DECISIONS 2026-07-16):** a buy above the sale proceeds is
  funded savings-first (cash → GIA → ISA, never pensions; the RIO borrows only the remainder); anything unfunded is
  charged as a year-0 cost so the plan **visibly fails** instead of being handed the home for free (the old
  floor-the-surplus behaviour is gone); a year-0 GIA draw pays real CGT. New **`CapitalReceipt`** builder input
  (step 3) models documented one-off money from outside the plan (family gift / outside-asset sale) — the ladder
  shows it as "One-off receipt". Awaits browser sign-off with the rest (What's next #1).
- **Done 2026-07-17 — "What you can afford" screen (DECISIONS 2026-07-17):** a plain-English `/scenarios/{base}/afford`
  surface for the elder couple who can't read the ladders/fans — one yes/no per plan (do the essentials last for
  life?), working plans first, failing ones collapsed with the year each runs short, a factual "bottom line" naming
  the strongest plan (+ a gated "lean towards" line). Verdict is the fast deterministic projection; stored Monte
  Carlo "how sure" shown beside it, with a one-click **Check how sure** that queues the full runs and hands off to
  Compare's progress UI. Pure presentation (no shape change). Linked from the dashboard, Compare and Results.
  Awaits browser sign-off with the rest (What's next #1). **Finding:** sell-and-rent at £2,000/mo fails at any
  realistic sale price (out 2037 on a £290k sale); affordable rent ceiling on a £290k sale ~£1,000/mo; sell-and-buy
  cheaper is the strongest plan. Five limit-test what-ifs added to the app (DB scenarios 33–37, not repo data).
- **Done 2026-07-18 — stochastic house-price growth in the Monte Carlo (DECISIONS 2026-07-18):** house growth was a
  deterministic straight line in the MC, so a home (the biggest, most variable slice of wealth) carried no risk and
  home-heavy plans looked artificially certain. Now the MC draws a per-year house shock (sourced 9%/11% real vol,
  low ~0.2 house–equity correlation). Opt-in via a nullable `AssumptionSet::houseGrowthVolatility`, so the
  deterministic projection, every existing set and every stored run are byte-identical (no DB migration). The
  low correlation is the point: it's why sell-and-invest diversifies concentrated housing risk. Salary growth in the
  MC stays deterministic (remaining refinement). Completeness-tested; no browser sign-off needed (engine + fan width).
- **Done 2026-07-18 — stochastic salary growth in the Monte Carlo (DECISIONS 2026-07-18):** the last deterministic
  straight line in the MC. A still-working household's future pay rises (and the savings/pension the surplus funds)
  now carry earnings risk. Same opt-in/null-safe contract as the house change: nullable `AssumptionSet::salaryGrowthVolatility`
  (+ a deliberately LOW `salaryEquityCorrelation` 0.1, weaker than housing's 0.2 — aggregate real wage growth is
  near-acyclical), so the deterministic projection, every existing set and every stored run stay byte-identical
  (no DB migration). Sourced 2.0%/2.5% real vol (SF Fed: real wage growth ~half GDP-growth volatility; ONS sanity
  check). Completeness-tested (`StochasticSalaryGrowthTest`); sanity magnitudes: a salary-driven working couple's
  p10–p90 terminal spread widens £104k → £115k at the shipped 2% (median ~unchanged). No browser sign-off needed
  (engine + fan width). **No MC-growth-determinism divergence remains.**
- **Done 2026-07-19 — the three hero time-series charts (C1/C2/C3, DECISIONS 2026-07-19):** a new "Money over
  time" section before the cashflow ladder draws the deterministic projection as three stacked-area pictures —
  **C1** income staircase (every source over time), **C2** wealth composition (pensions / savings / home
  equity, summing to net worth), **C3** costs (essential vs discretionary, the spending smile). Presenter +
  Blade only (`ResultPresenter::timeSeriesCharts`), no engine change — all data was already on `YearResult`.
  Built from the SAME `ForecastResult->years` the ladder reads, each with a `<details>` table twin reconciling
  to the ladder cell-for-cell (`TimeSeriesChartsTest`). Real-terms only; the **nominal-pounds toggle is
  deferred** (needs the engine's pre-deflation figures exposed, not a presenter re-inflation). Third build-order
  item of docs/build/PLAN-output-inflation-and-charts.md; **awaits browser sign-off** with the rest (visible UI).
- **Done 2026-07-19 — voluntary overpayments on a rolled-up lifetime mortgage (DECISIONS 2026-07-19):** the
  equity-release roll-up could only model "no payments"; now `Property::mortgageOverpaymentAnnual` (`?Money`,
  null = pure roll-up) subtracts a fixed-nominal overpayment from the balance each year after it compounds
  (NNEG-capped, floored at 0), so overpaying a lifetime mortgage slows the roll-up and preserves the estate.
  The cash to fund it rides on the "Mortgage" expense line, so the model shows the honest trade-off (lower
  balance vs the cashflow that pays for it). Builder-wired as an optional field; `LifetimeMortgageRollUpTest`
  (penny-exact + strictly-lower-than-pure-roll-up). Built to evaluate a real equity-release proposal.
- **Done 2026-07-18 — care in the deterministic path as an "if care is needed" stress (A2, DECISIONS 2026-07-18):**
  care was Monte-Carlo-only, so the plain-English Affordability verdict ("lasts for life? Yes") was computed on a
  care-free path — falsely reassuring for the least-numerate reader. Now `DeterministicPathDraws` accepts injected
  `CareEpisode`s (empty = byte-identical care-free path) and `DeterministicForecaster::forecastWithCareStress`
  places one adverse ~4-year nursing spell (£1,800/wk, `CareStressScenario`) on the last-surviving partner,
  means-tested + CPI+2%-escalated. The Affordability screen shows the care-stress verdict beside each plan's
  care-free verdict (and a bottom-line care caveat), so "for life" is never shown unqualified; the ordering stays
  the care-free expected path. Not averaging (per FCA / pro cashflow tools). `DeterministicCareStressTest` +
  `AffordabilityTest`. **Awaits browser sign-off** with the rest (What's next #1 — it's a visible UI change).
  **Still open:** a care-stress params editor, a probability-weighted "typical" option, the stress line on the
  main results ladder.
- **Done 2026-07-18 — care fees escalate above CPI (A1, DECISIONS 2026-07-18):** the engine drew one CPI series
  and modelled care as a flat-real cost, so care — the fastest-inflating major UK retirement category — rode flat
  CPI and understated the tool's headline late-life risk. New `AssumptionSet::careCostRealGrowth` (`?Percent`;
  null = flat-real, back-compat; shipped presets CPI+2% real, sourced/adverse-default, user-editable as the 7th
  economic assumption) compounds the sampled self-funder fee above CPI to the year the spell falls, mirroring
  `propertyCostsRealGrowth`. Null-safe: every stored care run reproduces byte-identically (`CareCostInflationTest`,
  `MappingRoundTripTest`). First slice of docs/build/PLAN-output-inflation-and-charts.md; **A2 (care in the deterministic
  path) is the next item.** No browser sign-off needed (engine + a panel row).
- **Done 2026-07-18 — sex-differentiated late-life care probability (DECISIONS 2026-07-18):** the stochastic care
  risk drew one flat 0.25 lifetime probability for everyone though `Person::sex` was already threaded to the
  `Simulator` before being dropped at the sampler. Now `CareAssumptions` carries male 0.20 / female 0.30 (~1.5:1,
  mean anchored to the Dilnot/PSSRU ~1 in 4), threaded into `CareCostSampler`'s per-person Bernoulli. No data-shape
  change; a threshold swap, not an extra draw, so seeded runs reproduce byte-identically and only fresh care-modelled
  runs shift. Age-conditioning of the rate + a sex split of the *duration* remain flagged. Completeness-tested
  (`CareCostSamplerTest`: the split reaches incidence). No browser sign-off needed (engine + fan tail).
- **Done 2026-07-18 — "Hide non-viable plans" toggle on Compare (DECISIONS 2026-07-18):** a checkbox that drops any
  plan whose usable-wealth line falls below £0 (runs out of money on the deterministic path) from the Compare table,
  burndown chart and Monte-Carlo cards, so the reader can focus on the plans that last. Shown only when there is a
  non-viable plan to hide; "Re-run all" still queues every plan, not just the visible ones; the burndown is re-keyed
  so the `wire:ignore`d chart re-renders the filtered series. Pure presentation, no shape change. Awaits browser
  sign-off with the rest (What's next #1).
- **Done 2026-07-18 — PDF sale-funding waterfall:** the downloadable/print report now renders the "If you sell"
  block (net-proceeds waterfall → sell-&-rent → sell-&-buy funding: savings drawn, mortgage, unfunded-gap failure),
  built from the SAME `ResultPresenter::saleExplainer` + engine decomposition the results page uses, so print cannot
  drift from screen. Closes the last PDF open item; guarded by a `ScenarioPdfTest` assertion. Awaits browser sign-off
  with the rest (What's next #1).
- **Done 2026-07-29 — repayment (capital & interest) mortgages amortise (DECISIONS 2026-07-29):** the engine
  modelled a repayment mortgage's balance as **static** and its payment as an ordinary expense line, so it
  inflated a contractually fixed instalment with CPI, shrank it by the survivor factor on a death, never
  stopped it at the end of the term, and understated net wealth + the IHT estate by every pound of capital
  repaid. New `Property::$repaymentTerms` (`RepaymentMortgageTerms` + `MortgageRatePeriod`) and an
  `AmortisationSchedule` now own **both** legs — the balance amortises to zero and the fixed-nominal
  instalment is charged as essential spend (added after the CPI/survivor multiplies), **replacing** the
  "Mortgage" expense line. Mutually exclusive with `mortgageRollUpRate` (throws). Pinned to a real lender
  illustration — the LiveMore ESIS of 2026-07-29 reproduces **within 21p at any row over 16 years**, both
  monthly instalments exact. Null terms = byte-identical, no migration. Closes the DATA-MODEL divergence.
- **Done 2026-07-29 — the V2 Stay-put base moved onto the real LiveMore quote:** the base's hypothetical
  "£90k found → £118k RIO at £7,080/yr" is replaced by the actual quote (**£160k over 16 years, C&I**,
  £1,318.54/mo then £1,384.65/mo). **Finding: it does not work** — affordable while both live, but the
  survivor carries £16,616/yr on ~£11.7k/yr, so the plan runs short in **2036** (was 2043), ~£15k/yr short
  until the loan clears in 2042; against that, terminal net wealth is **+£74,830** because the debt is
  genuinely repaid. It also needs ~£49,495 up front vs the ~£42k realistically available. Seven children that
  model a *different* mortgage product (17, 32 let-to-let; 27, 28, 31, 38, 39 lifetime mortgages) carry an
  explicit blank-term override; all others inherit. Figures + the entry recipe are in the gitignored
  `docs/SCENARIO-V2.local.md`.
- **Done 2026-07-29 — the V2 what-if family was cleared and rebuilt (Rob's call):** 23 children on drifting
  premises replaced by 9, organised around the three identifiable ways to keep the flat (repayment mortgage /
  lifetime mortgage / let-to-let) plus levers and two sell comparators. The previous 24 scenarios are backed
  up in full at the gitignored `docs/scenario-backup-2026-07-29.local.json`. **Finding: only two plans never
  run short** — the lifetime mortgage (which survives by consuming the whole estate) and sell-and-buy-cheaper;
  and **no lever rescues the LiveMore mortgage** (YCC working 5 more years moves the shortfall 2036 → 2042; an
  £80k art/jewellery sale buys 1–4 years). The let-to-let BTL rate was **repriced 6.5% → 5.75%** on
  2026-07-30 — the "later-life premium" behind 6.5% does not exist, since BTL is underwritten on rental
  income, not the borrower's age (DECISIONS 2026-07-30). Figures + sources in the private V2 doc.
- **Done 2026-07-29 — Pension Credit severe-disability-addition follows the couple rule (DECISIONS 2026-07-29):**
  `PathProjector::meansTestedBenefitNominal` applied the severe-disability addition (SDP) whenever **any** living
  member received a disability benefit, so a couple with **one** disabled partner wrongly got it. Real rule
  (Turn2us): a couple qualifies only when **both** partners receive a qualifying disability benefit (or the other
  is registered blind). Fix: SDP now needs a single disabled pensioner or a both-disabled couple (**couple rate =
  2× single**); a new `Person::caresForPartner` flag wires the **carer addition** (the correct addition for a
  one-disabled-partner couple, via underlying entitlement, which does not remove any SDP). Quantified on the
  private V2 base before/after (figures in the gitignored benefits doc): a material cut to lifetime Pension
  Credit, confined to the both-alive years (survivor years were already SDP-free, unchanged). Engine-only; `caresForPartner`
  **builder-UI exposure deferred** (defaults false, no scenario/child-delta affected, immaterial to V2). Guarded
  by `PathProjectorTest` + `PensionCreditCalculatorTest`. Full benefits check for the couple is in the gitignored
  `docs/BENEFITS-CHECK-V2.local.md`.
- **Done 2026-07-30 — "available capital" + "monthly allowance", and a SOLVED affordable-spend figure
  (DECISIONS 2026-07-30, [docs/build/PLAN-spendable-view.md](build/PLAN-spendable-view.md)):** the tool
  reported only annual figures, and its nearest "available" number (`usableWealth`) counted pre-tax
  pension as cash. One presenter definition (`ResultPresenter::spendableFor`) now feeds the ladder,
  Compare, `/afford`, the CSV and the PDF: **available capital** (liquid only; home excluded, pension
  separate + labelled taxable) and **monthly allowance** (what the plan can FUND, split essential vs
  free-to-choose), with the survivor step-down on the Compare row. Plus `SustainableSpend` — a
  synchronous deterministic bisection on a new `DiscretionarySpendLever` — which **solves** "the most you
  could spend on treats and holidays every year", the one question a budget-bounded projection cannot
  answer. **V2 finding: sell & buy cheaper £865/mo, lifetime mortgage £652/mo, YCC-to-72 £212/mo, and
  every other plan (incl. the LiveMore stay-put base and both £80k art-sale variants) fails at zero
  discretionary spend** — the stay-put mortgage leaves no holiday budget at all.
- **Done 2026-07-30 — the park-home option: a bought home that costs what it costs and LOSES value
  (DECISIONS 2026-07-30, [docs/build/PLAN-park-home.md](build/PLAN-park-home.md)):** two optional
  `HousingAction` fields (`buyRunningCosts`, `buyGrowthOverride` — the latter accepting **negative**
  rates), a `home_depreciates` honesty note, and four new scenarios (£150k Tring / £128k Wokingham,
  each ± the £80k art sale). **Running costs raised £3,000 → £5,000/yr on 2026-07-30** once the home's
  own upkeep was researched (the pitch fee buys site maintenance only — DECISIONS 2026-07-30), which
  narrows the advantage: £128k Wokingham + art sale **£994/mo** free spending vs sell-and-buy-cheaper's
  £865/mo, but **£693/mo without the art sale**, so sell-and-buy wins on both spending and estate unless
  the art is sold. £150k Tring can't complete at all — no mortgage is available on a park home and the
  £46,412 gap exceeds their savings. **A full scenario audit ran
  clean** (see Session log) — variant labels, orphaned overrides, the mortgage line, monthly-figure
  reconciliation, depreciation reaching the result, and unfunded purchases being charged.
- **Done 2026-07-30 — hard rule "no invisible figures" + `php artisan scenarios:audit`
  (DECISIONS 2026-07-30):** new hard rule in CLAUDE.md — the model must never use a figure the user
  cannot see and interrogate. **Two live violations fixed:** a bought home's upkeep (1% of value/yr)
  and moving costs (£2,000) were private engine constants applied silently; both are now disclosed as
  `assumed_figure` notes reading the constant that owns them (never restating it). New
  `scenarios:audit` command sweeps every stored scenario on seven checks and exits non-zero so it can
  gate a release; `AuditScenariosTest` proves it catches each defect rather than merely passing.
  **It immediately found a real bug:** `Scenario::projectFrom()` defaulted the variant COLUMN to
  `Rent` while the forecast defaults to `stay_put`, so a scenario saved without an explicit variant
  was labelled "Sell & rent" everywhere while being projected as staying put (now `StayPut`).
- **Done 2026-07-30 — the PDF is a COMPLETE print of the results page, charts included
  (DECISIONS 2026-07-30):** the export carried about a third of the screen and **no chart at all**
  (dompdf runs no JavaScript; every screen chart is an ApexCharts canvas), which made it unusable for
  its actual purpose — sharing a plan with family or an adviser. Now every section the page renders is
  exported from the **same `ResultPresenter` calls** the Livewire component makes, including the
  previously missing input-sanity / **assumed-figure disclosures** (the "no invisible figures" rule
  applies to the artefact the reader is handed), the what-if delta, longevity, care risk, the
  interpretation panel, assumption sensitivity, Pension Credit how-to-claim, the IHT distribution,
  withdrawal sequencing, the stress test, the assumptions panel, the milestone timeline, and the
  **eleven ladder columns** the print had been dropping. New **`App\Export\ChartSvg`** re-draws all four
  charts (Monte Carlo fan + the three time-series) as vector SVG **from the screen chart's own option
  blob**, embedded as `<img src="data:image/svg+xml;base64,…">` (dompdf ignores an inline `<svg>`).
  Report is now **A4 landscape**. **A live divergence was found and fixed on the way:** the PDF read
  `$scenario->variant` directly while the screen clamps to a configured strategy, so a scenario stored
  as "sell & rent" with no sale price printed a *rented* ladder against the screen's stay-put — both now
  resolve through one `App\Forecast\LadderContext`. Completeness is guarded by **derivation** (the test
  reads the component's own view data), not a checklist.
  **Reworked after Rob's review of the first cut** (charts too small, layout unlike the web): the report
  now uses the results page's own idiom — white cards, the coloured stat tiles, verdict pills, badges and
  the ladder's row tints — charts are drawn page-width at **1000×480** (was 720×320), and **both fan
  bases print as separate charts** (spendable excl. home, then total wealth incl. home equity), because
  the screen's "Include home value" checkbox cannot be toggled on paper. **A second real defect was found
  and fixed:** all ~24 ladder columns as one table overflowed the page and dompdf **clipped** it — the
  final total-wealth column printed as `£225,5` — so the ladder is split into two tables sharing the
  Year / Age(s) key, guarded by a test that reads the rendered PDF's own text positions.
- **Done 2026-07-30 — income echoed back like spend; monthly beside annual; no sale talk without a sale
  (DECISIONS 2026-07-30) — on BOTH the results page and the PDF:** the tool detailed what a plan *spends*
  but never what *funds* it, so new **`ResultPresenter::incomePlan()`** adds a "Where your money comes
  from" section — the entered income sources with each one's start and stop, the capital pots with what is
  paid in and **how each is taxed on the way out**, and a **timeline** of when each source starts, stops
  and peaks, derived from the same `incomeBySource` the ladder reads so it cannot disagree with it. Every
  budget figure now carries a **monthly** twin, rounded per line and summed so the column adds up as
  printed. And **sale content follows the strategy on display** (`LadderContext::homeSold()`), not merely
  whether a sale price was entered — a base carries one so Compare can run the sell variants, which was
  handing a stay-put plan a sale waterfall, selling-cost assumptions and CGT signposting for a disposal it
  never makes. **Finding:** the V2 base has **no savings accounts at all** — its £18,573.68 of 2026 liquid
  wealth is exactly that year's surplus, not an opening balance — so an explicit "no savings to fall back
  on" note now says which it is instead of showing an empty table.
- **Done 2026-07-31 — six shipped assumption figures had never reached a single forecast
  (DECISIONS 2026-07-31):** the app reads a scenario's assumptions from the `assumption_sets`
  **table**, seeded once from `AssumptionSetLibrary`; a figure added to the library afterwards is
  absent from the stored payload, where the mapper's back-compat rule (correct for a frozen run
  snapshot) reads it as null. So **stochastic house-price growth, stochastic salary growth and the
  above-CPI care escalation — all built, tested and documented on 2026-07-18 — had never been active
  in any run Rob has seen**, along with the new investment charge. Re-seeded (verified first that the
  only differences were the six absent keys, so no admin edit was overwritten). **`scenarios:audit`
  gained a pre-flight check** for a stored set missing any shipped key, proved in both directions by
  `AuditScenariosTest`. **Measured:** scenario 9's terminal p10–p90 widens £338,965–£486,042 →
  £219,543–£688,165 (3,000 paths, seed 424242) — the housing risk that had been missing from every
  fan. Stored MC runs are frozen and unaffected; **a re-run will now differ, and should.**
- **Done 2026-07-31 — investment returns are no longer gross of charges (adviser-parity A1,
  DECISIONS 2026-07-31):** the largest open correctness gap. `AssumptionSet::$investmentCharge`
  (`?Percent`, null = byte-identical) reaches the projector via `PathDraws::investmentChargeRate()`
  on all three drivers, and `growState` deducts it from each invested balance after growth — DC pots,
  ISAs, GIAs; **cash deposits and the home bear none**. Shipped **0.50%** across the presets, sourced
  to DWP's 0.48% workplace average / 0.28% median AMC / the 0.75% cap that does not bind in
  decumulation (ASSUMPTIONS §10), and **deliberately not the most adverse figure** — the charge falls
  on invested wealth, so an over-adverse rate biases sell-vs-stay rather than adding safety. Editable
  as the 8th economic assumption. `YearResult::$investmentCharges` reports the pounds and growth stays
  **gross**, so opening + growth − charges reconciles and the charge is visible; a lifetime total
  prints under the ladder on screen and in the PDF. **V2 effect:** sell-and-rent runs short **2042
  instead of 2043**; lifetime charges £814 (stay-put base), £2,404 (lifetime-mortgage / sell-and-buy —
  their surplus piles up as cash, so only the DC pot is charged), **£8,150 (sell-and-rent)**, whose
  proceeds are genuinely invested.
- **Done 2026-07-31 — pension contributions: net-pay tax relief, and the employer's money is the
  employer's (adviser-parity A2, DECISIONS 2026-07-31):** contributions came from *net* surplus with
  no relief, so the engine modelled a pension's cost and none of its point. New
  `DcPension::$reliefMethod` (`?PensionReliefMethod`; null = relief not modelled, back-compat, and
  raised as a `no_relief_method` input note). **Net pay** is modelled by subtracting the contribution
  from gross earnings *before* both the income-tax pass and the spendable total — relief through the
  engine's one tax pass with no parallel calculation to drift, NI correctly unaffected, and the
  surplus/tax circularity dissolved. Capped at pay, so it stops when the salary does.
  **`ReliefAtSource` throws** rather than accepting the input and giving no relief. Two structural
  defects fixed with it: the **employer's contribution is no longer funded from household surplus**
  (credited while the member works, prorated in a part-year; a year with no surplus previously
  **dropped it silently**), and contributions no longer run for ever after retirement. **Effect on V2:
  none — no stored scenario records any DC contribution at all** (see Blockers).
- **Done 2026-07-31 — the protection gap: what a death next year costs, and the cover that vanishes
  at retirement (adviser-parity B2, DECISIONS 2026-07-31):** the engine already computed the survivor
  cliff as a *percentage*, so it knew the size of the hole but never named the instrument that fills
  it. Two halves. **Engine:** `Person::$deathInServiceCover` (`?DeathInServiceCover`; null = no cover,
  byte-identical) pays an employer group-life lump sum to the survivor when a member dies **while
  still employed** — a multiple of the salary in the year of death, or a fixed (nominal) sum assured.
  Registered-scheme tax rules verified against HMRC PTM073010: tax-free under 75 up to the remaining
  LSDBA, taxable as the recipient's income above it and in full at 75+, all through the engine's one
  tax pass. **Outside the estate for IHT**; **capital, not income, for Pension Credit** — so a payout
  can end a survivor's Guarantee Credit, which the forecast now shows. New `death_in_service` income
  source. **App:** `ProtectionGap` bisects for the smallest lump sum that leaves the survivor's money
  lasting at least as long as the couple's own plan does (a **relative** bar — an absolute one is
  unanswerable for a plan that already runs short), and prices the same death a year after retirement,
  when the cover has ceased. Deterministic and synchronous (~10–50 ms, no queue worker), pinned to the
  strategy the ladder is showing. **Finding — the exposure runs the opposite way to the adviser
  reflex:** the *working* partner's death leaves the survivor no worse off; the *retired, disabled*
  partner's death is the damaging one (it removes their State Pension, disability benefit and the
  couple's Pension Credit while the survivor still carries the stay-put mortgage), moving the
  shortfall 2036 → 2030 and needing ~**£108,000** to restore the plan (~£110,000 on sell-and-rent,
  **£0** on sell-and-buy-cheaper, which is immune). Also collapsed **seven sweep levers' positional
  `Household` rebuilds** into one `copy()` behind withers, guarded by a reflection-driven
  `HouseholdWitherTest` — a field added to the DTO and forgotten in a lever was silently dropped from
  every swept forecast. **Awaits browser sign-off** (a visible new section on results + PDF).
- **Done 2026-07-31 — what paying for advice would cost (adviser-parity B1, DECISIONS 2026-07-31):**
  now the engine charges investment costs at all, the cost of advice is the same projection run twice —
  once bearing the charges it already bears, once with an adviser's ongoing fee on top — reported as
  lifetime pounds, terminal wealth and the year the money runs out. **The advised side is the plan's own
  charge PLUS the fee and nothing else:** the drafted ~1.80% "total cost of ownership" could only be
  found in unverifiable search summaries, and how much dearer an advised fund choice is varies too much
  between firms to assume, so building the total that way would have invented the larger half of the
  number. The **0.83%** ongoing fee (NextWealth 2026, re-verified at build time) lives in
  `config/advice.php` — **not** in `AssumptionSet`, because the forecast never charges it — and is
  editable per scenario (blank = the benchmark, stored sparsely so no scenario predating it gains a
  delta). Panel is framed as a **cost, not a verdict**, and says what it cannot value (behavioural
  coaching; the cost of getting something wrong without an adviser; an initial one-off fee).
  **V2 finding:** advice costs this household very little (~**£1,278** over the whole stay-put plan,
  moving the shortfall 2036 → 2035) because it has almost nothing invested — **except sell-and-rent at
  ~£11,941**, the one plan whose proceeds are genuinely invested. Awaits browser sign-off.
- **Done 2026-07-31 — the ISA subscription cap enforced, and a "known divergence" that had it
  backwards (adviser-parity A3, DECISIONS 2026-07-31):** new `IsaParameters` in the tax-year registry
  (£20,000 overall per person per year, sourced, plus the dated April-2027 cash-ISA cut);
  `applyContributions` caps ISA subscriptions per person per year and **spills the excess to that
  person's GIA** rather than dropping it — the household still saves the money, just somewhere taxable.
  `IsaSubscriptionCapTest` is **verified to fail** with the cap removed. **The DATA-MODEL entry was
  wrong and is corrected in place:** it claimed the bias was "largest for the sell-and-invest plans",
  but a sale's proceeds are invested into a **GIA**, not an ISA, and surplus banks to **cash**, so no
  housing variant ever sheltered anything through the gap. It only ever bit on an entered ISA
  contribution above £20,000/yr, which no stored scenario has, so nothing moves. **The bigger half is
  now recorded as still open:** the engine never *uses* the allowance either (no bed-and-ISA), which
  **understates** the sell-and-invest plans — an action to decide on, not a rule to enforce.
- **In progress:** nothing mid-edit. Live carry-over: the real **V2 couple's data** is captured privately in the gitignored `docs/SCENARIO-V2.local.md` (never commit) — the durable source to rebuild after a DB wipe; **read that doc before touching any V2 figure.** The base's
  "money found from outside" convention can now be modelled honestly: **Rob re-enters it as a capital receipt**
  (year 2026, the real source as the label) — see the V2 doc's note. It now needs **~£49,495**, not ~£90k.
- **Operational note (found 2026-07-10):** a `queue:work` daemon started **before** the 2026-07-09 Postgres migration keeps polling the old SQLite `jobs` table and processes **no** Postgres jobs — an in-app "Re-run all" hangs against it. **Restart every queue worker after the DB change** (`queue:work` caches its DB connection at boot). See How to pick up.
- **Known bugs:** none open. The queued-Monte-Carlo reproducibility bug is **RESOLVED** (Postgres) and **independently re-verified 2026-07-10** (Session log). Documented v1 scope limits (all flagged in code) live in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" — e.g. Scotland income tax throws; emergency tax models the over-deduction magnitude, not PAYE-table pennies. **A repayment mortgage now amortises properly** (DECISIONS 2026-07-29 — the static-balance divergence is CLOSED; lender fees, ERCs and the 10%/yr overpayment allowance remain unmodelled). **House AND salary growth are now both stochastic in the Monte Carlo** (DECISIONS 2026-07-18) — no growth factor is a deterministic straight line any more.

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
