# Research: competitive gap analysis — what the market has that we don't

_2026-06-30. A full-market scan (UK provider calculators, robo/aggregator apps, independent consumer
tools, prosumer/FIRE tools, UK adviser cashflow software, and the FCA regulatory framing) run to find
genuine **gaps** in RetireForecast and the lessons worth borrowing. Complements the two earlier notes:
[RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md) (the adviser sector — scenario/clone/compare,
line-item expenditure, the cashflow ladder) and [RESEARCH-editable-assumptions-ux.md](RESEARCH-editable-assumptions-ux.md)
(editable assumptions, buy-vs-rent, cost breakdowns). This note is the **whole-market competitive read**.
Every figure that would enter code carries the usual ⚠️ until sourced + `verified_on`._

> Method: multi-stream web research (2025–2026 sources). Several vendor support sites returned HTTP 403 to
> automated fetch; those claims rest on search-surfaced snippets of the cited pages plus third-party
> corroboration, and are flagged where they matter. Treat vendor marketing as "what it claims", not "verified".

---

## TL;DR — the strategic read

**RetireForecast's engine is not the gap.** Its stack — penny-accurate HMRC tax + Monte Carlo with
correlated real returns, stochastic inflation and **stochastic joint-life ONS-cohort mortality** —
**matches or beats the entire UK adviser sector** (where Voyant is deterministic-first and Timeline/CashCalc
use single-asset GBM/sector-calibrated returns), and **out-models every UK consumer tool**. No UK consumer
tool combines a stochastic engine + an HMRC-accurate tax engine + housing strategy. Only **Timeline** also
has stochastic mortality.

The gaps are a **decumulation-policy layer** and a **framing layer** on top of that engine:
1. **Dynamic / guardrail withdrawal strategies** (the single biggest sustainability lever).
2. **Tax-efficient withdrawal sequencing across wrappers** (ISA vs SIPP/DC vs GIA, "fill the band"), with the £ delta shown.
3. **Equity release as a 4th housing strategy** — the clearest unoccupied position in the whole market.
4. **The FCA's four named stress tests + UK historical "retire-into-a-bad-year" backtesting.**
5. **A single success-probability gauge + a Sankey** — cheap legibility wins on output it already computes.

**The winning combination is unoccupied.** Nobody UK-available has stochastic engine + HMRC tax + housing
strategy + decumulation policy. RF is ~two features (guardrails + wrapper-sequencing) plus equity-release
away from a position none of them hold. The local-first / no-aggregation stance is a **feature** (both
consumer aggregators exited the market; ProjectionLab omits aggregation on principle).

---

## 1. The landscape

| Tier | Examples | Engine | Housing | Vs RetireForecast |
|------|----------|--------|---------|-------------------|
| **Provider calculators** | Aviva, L&G, Standard Life, Royal London, Nutmeg/JPM, Moneyfarm | Deterministic single-path; indicative tax; DB & often State Pension **excluded** | Equity-release **siloed** in a separate lead-gen calc; never folded into income | RF out-models all |
| **Aggregator / advice apps** | Moneyhub, Multiply | Deterministic; live Open-Banking + Zoopla feeds | Property valued, not strategised | **Both exited the consumer market in 2025** → validates local-first |
| **Independent consumer** | **Guiide**, **retirecalc.uk**, Which?, PensionBee | Guiide deterministic; **retirecalc.uk = MC + Guyton-Klinger + 125yr historical** | None | retirecalc.uk is the **nearest direct peer**; beaten only on stochastic mortality, HMRC-grade tax, housing |
| **Prosumer / FIRE** | **ProjectionLab (UK mode)**, Pralana, Boldin, cFIREsim, FIRECalc, Honest Math | Rich: historical backtest + MC + tax/withdrawal optimisers | None (US-centric) | Ahead on decumulation policy; behind on tax fidelity & transparency |
| **UK adviser** | Voyant, **Timeline**, Dynamic Planner, CashCalc, Conquest, EValue | Voyant deterministic-first; Timeline MC+historical **with stochastic ONS mortality**; DP stochastic-by-default; EValue ESG | Manual cashflow events | RF engine ≥ the sector; gap is the workflow layer |
| **Regulator** | FCA TR24/1, Consumer Duty, AGBR | — | — | A free "what a good decumulation tool must show" spec |

**Structural fact:** FCA COBS 13 Annex 2 forces the *prominent* projection in any regulated pension tool
to be a **standardised deterministic** one (low/mid/high). Stochastic is permitted only as a secondary
feature — which is why nearly every provider tool is single-path, and Monte Carlo lives in adviser software.
RF does the thing the regime discourages providers from foregrounding.

---

## 2. Where RetireForecast already leads (so the gaps land in context)

- The only tool in the intersection **stochastic engine + HMRC tax + housing strategy**.
- Engine ≥ the UK adviser sector on asset + mortality stochastics; **only Timeline** also draws a random
  age of death (everyone else plans to a fixed age / uses survival probabilities deterministically).
- Already meets most of the FCA TR24/1 checklist: real-terms net-of-tax cashflow, beyond-average-life-expectancy
  (the longevity distribution), lower-percentile outcomes, sourced + dated assumptions (`verified_on`), and
  the income-floor concept (≈ capacity for loss).
- Already has ingredients others charge for: success probability, longevity distribution, PLSA benchmark,
  editable assumptions, what-ifs + Compare, partial-PRR CGT, contingent costs.

---

## 3. The gaps (split: ALREADY ON BACKLOG vs NET-NEW from this study)

### Cluster A — Decumulation strategy *(biggest, most consistent gap across every stream)*

- **Dynamic / guardrail withdrawal strategies — NET-NEW, top priority.** RF models **fixed-real** spend only.
  The evidence (Okusanya *Beyond the 4% Rule*; Morningstar) is emphatic that this is the single biggest
  lever: a UK fixed-real safe rate of **~3.0–3.9%** rises toward **~5%+** with flexibility. Implement at
  least **Guyton-Klinger** (±20% bands → ±10% adjustments, 6% inflation cap), a **Vanguard dynamic** collar
  (+5%/−2.5% YoY), and the **Kitces ratchet**. The state of the art is **Income Lab's risk-based guardrails**:
  the spend target is a percentile (default 20th) of *your own* Monte-Carlo sustainable-spend distribution,
  recomputed each year, and **asymmetric** (raise fast, cut slowly) — in the GFC, ~3% cut vs ~28% under
  classic Guyton-Klinger. This is a natural extension of RF's existing **income-floor readout + longevity
  distribution**; the machinery exists. Timeline already ships GK / Floor-Ceiling / Ratchet / Vanguard
  Dynamic with tunable bands.
- **Tax-efficient withdrawal sequencing across wrappers — NET-NEW, highest on-brand value.** The UK analogue
  of US Roth/taxable/tax-deferred ordering is **ISA vs SIPP/DC pension vs GIA**, CGT-aware GIA disposals, the
  25% PCLS, and **"fill the band"** (draw pension up to the personal-allowance / basic-rate ceiling; realise
  GIA gains up to the CGT annual exempt amount; steer around the £100k–£125,140 personal-allowance taper and
  the High-Income Child Benefit cliff). **Quantify the lifetime-tax £ delta** of a sequencing choice
  (RightCapital advertises ~$868k saved; Timeline shows success rising to 94% from re-ordering). Even *pro*
  tools mostly let the adviser *specify* the order (Voyant savings order; Conquest's funding-preference menu)
  rather than auto-optimise it — Conquest's AI decumulation "Bytes" were still in development in the 2025–26
  sources. A UK consumer tool that optimises and shows the £ is genuine white space, and it's the most
  on-brand addition given RF already owns the HMRC engine. Vanguard UK's own withdrawal-order white paper
  gives the heuristic baseline.
- **Spending "smile" / phased real decline — ALREADY ON BACKLOG.** Blanchett: real spend declines ~1%/yr to
  ~age 84 (trough ~26% below start) then rises on health costs; Bernicke steeper; Kitces age-banding models
  per-category. RightCapital ships the smile + Go-Go/Slow-Go/No-Go staging. Flat-real over-states required
  wealth and **biases the rent/downsize verdicts** RF exists to compute. Engine change.
- **Annuitisation / partial-annuity floor — ALREADY ON BACKLOG (v2).** Buy a guaranteed floor (now or
  deferred) vs stay invested — Morningstar's "floor + guardrails" finding; pairs with the income-floor
  readout. UK-live theme (rates ~7.9% in 2026; Nest/Rothesay deferred-annuity defaults).
- **Amortisation / VPW withdrawal mode — NET-NEW.** Balance ÷ remaining life expectancy: a never-deplete
  rule. RF can uniquely use **its own ONS cohort survival curve** as the divisor.

### Cluster B — Stress-testing *(FCA-shaped; RF has the machinery, not the packaging)*

- **Historical-sequence backtesting on UK data — ON BACKLOG (stress-test panel).** Replay actual ordered
  UK gilt/equity/CPI sequences ("retire into 1973–74 / 2000 / 2008"), not just MC draws — the
  FIRECalc/cFIREsim/ProjectionLab/Timeline strength. **Block-bootstrap MC** (sample consecutive-year blocks
  to preserve autocorrelation; ProjectionLab v4.4) is a NET-NEW refinement, strictly better for sequence risk
  than IID draws.
- **The four FCA-named stress tests as one-click scenarios — NET-NEW framing of a backlog item.** TR24/1
  prescribes exactly: (1) a rare-but-feasible asset fall **at the start** of withdrawals (sequencing /
  "pound-cost ravaging"); (2) reduced net-of-inflation returns; (3) a lower-percentile stochastic outcome;
  (4) higher withdrawals depleting the fund sooner. RF computes all the ingredients; packaging them as the
  named set maps the tool to the regulator's own spec. cFIREsim's "Specific Years" and Empower's "Recession
  Simulator" are the UX references.
- **Stochastic, longevity-correlated care-cost shock — ON BACKLOG (care-cost stochasticity).** RF has a
  deterministic care means-test; making it a probabilistic late-life balloon (likelier the longer you live)
  is white space — no consumer tool models care stochastically — and matters *more* now the **£86k care cap
  is cancelled** (29 Jul 2024; not delayed) → unbounded self-funder liability. This is where housing wealth
  gets consumed and where the stay-vs-sell verdict diverges most. Add an **NHS Continuing Healthcare** branch
  (means-test bypass on a primary-health-need trigger) and the **spousal home-disregard** while one partner
  remains in residence.
- **Fat-tailed returns + sensitivity analysis — NET-NEW.** Honest Math's Lévy-process tails capture crash
  kurtosis a correlated-normal model understates; Flexible Retirement Planner's "which input moves success
  most" ranking is cheap and high-decision-value.

### Cluster C — Housing *(their flagship domain — RF's single biggest strategic opportunity)*

- **Equity release / lifetime mortgage as a 4th strategy — NET-NEW, clearest white space in the study.**
  Every major UK provider (Aviva, L&G, Royal London) *sells* equity release but **not one folds it into a
  retirement-income forecast** — it lives in an isolated "how much can you release" lead-gen calculator that
  doesn't even graph the roll-up. Adviser tools (Voyant, CashCalc) model it only as a manual cashflow event.
  **No holistic tool anywhere puts stay-put / downsize / rent / equity-release on identical Monte-Carlo
  paths.** RF already does the first three on identical seeds. Add a roll-up/drawdown lifetime mortgage:
  **compounding fixed-for-life interest** (e.g. a £50k loan ~doubles in ~11 years at ~6.35%), a **No-Negative-
  Equity Guarantee** cap (debt ≤ sale value), **LTV by age** (≈20% at 55 → ~50%+ at 85), min property value
  (~£70k; flats valued ~85%), drawdown reserve vs lump sum — **with the IHT-estate interaction**. For a couple
  weighing "liquidate now vs stay and borrow vs keep the house as a care war-chest," this is the missing lever.

### Cluster D — Presentation & legibility *(things they do better on the surface)*

- **Single headline "probability of success" gauge — NET-NEW framing.** RF *computes* `success_probability`
  already; surface it as one legible gauge (with the ~85–95% advisory-target framing) + the **first year of
  shortfall** in failing paths. Present as "probability under stated assumptions", not pass/fail (keeps the
  banned-phrasing line). Kitces' point: a "50% success" can be fine because you adjust en route.
- **Longevity-adjusted success rate — NET-NEW.** Timeline blends *survival probability × portfolio
  sustainability* into one number ("the portfolio outlives you in X%"). RF has both ingredients (longevity
  distribution + MC) but reports them separately; blending is a clean win.
- **Sankey cash-flow diagram — NET-NEW.** Now table-stakes (ProjectionLab, Boldin 2025, Monarch). Strong fit
  for income → tax → wrappers → spend.
- **Live what-if sliders — ON BACKLOG** + a **free-form spending-curve editor** (hand-draw the smile / one-offs
  — ProjectionLab) — NET-NEW.
- **Reverse goal-solving — NET-NEW.** Guiide's "what pot / retirement age / contributions hit £X after-tax for
  life?" inverts the projection.
- **Tangible framing — minor.** PensionBee's "cost of a pint of milk at retirement"; Which?'s *named* growth
  presets (3.3 / 5.6 / 7.5%); PensionBee's nominal-vs-real twin lines. RF already has sourced, labelled presets.

### Cluster E — Data authenticity & integration *(the providers' real edge — mostly out-of-scope-by-design)*

- **Live account/property aggregation** (Moneyhub + Zoopla/Hometrack) — but both consumer aggregators
  **exited** the market, and ProjectionLab deliberately omits aggregation on privacy grounds. **Aligned
  against** RF's local-first posture → a deliberate non-goal, not a gap.
- **Real data authority** — gov.uk gives the actual State Pension entitlement + NIC top-up cost; the
  **Pensions Dashboard** (statutory connection deadline 31 Oct 2026; consumer launch ~2027) will auto-find
  every pot. RF can't replicate these; a **deep-link to the gov.uk State Pension forecast** and a future
  Dashboard / Open-Finance import are the pragmatic moves.
- **Fee-accurate net-of-cost projections** — robos bake in real platform + fund drag. Confirm RF models an
  explicit ongoing-charges figure per pot, not just gross-of-fees returns.
- **Planning → execution / advice handoff** — Guiide auto-pays withdrawals "like a wage"; Pension Wise offers
  a free human appointment. RF won't transact, but an **adviser-shareable plan pack** + an explicit "take this
  to Pension Wise" handoff closes the perceived gap.

---

## 4. What they implement *better* (quality, not just presence)

- **Decumulation depth on a shallow/black-box base.** Pralana, Boldin and ProjectionLab carry rich withdrawal
  optimisers on top of often opaque tax engines. They **disagree materially on "chance of success" from
  identical inputs** (Rob Berger's tests) — the strongest argument *for* RF's transparency/sourcing
  discipline. RF's job is to graft their policies onto *its* trustworthy engine.
- **Income Lab's risk-based guardrails** beat classic Guyton-Klinger precisely because they recompute off the
  *whole plan* and cut asymmetrically — the reference design for Cluster A.
- **RightCapital always quantifies the £** of a tax strategy. RF should show the lifetime-tax *delta* of a
  sequencing choice, never just the choice.
- **Pralana's tri-method single view** (deterministic line over MC + historical bands) + IRS-form-grade tax
  transparency — the closest tool philosophically to RF, and a good north star for the results page.
- **Timeline's longevity-adjusted success rate** + dual MC/historical engine with published mechanics.

---

## 5. Lessons / strategic takeaways

1. **The moat is the engine; the gaps are policy + framing.** Highest leverage = bolt *proven* decumulation
   rules (guardrails, wrapper sequencing, the smile, named stress tests) onto the engine RF already trusts.
2. **The winning combination is unoccupied.** No UK-available tool has stochastic engine + HMRC tax + housing
   strategy + decumulation policy. RF is ~two features away from a position nobody holds.
3. **Validate the local-first stance; don't chase aggregation.** Both consumer aggregators failed
   commercially; ProjectionLab omits aggregation on principle. It's a feature.
4. **FCA TR24/1 is a free product spec** — even for personal use, the cleanest checklist of what a good
   decumulation tool shows; RF already meets most of it.
5. **Go try retirecalc.uk** — the nearest direct competitor and the best sense-check of where the bar sits.

---

## 6. Recommended shortlist (ranked by impact × on-brand fit)

1. **Tax-efficient wrapper withdrawal sequencing + "fill the band", with the £ delta shown** — highest value,
   most on-brand (uses the HMRC engine; white space even vs pro tools). _NET-NEW._
2. **Dynamic guardrail withdrawals** (Guyton-Klinger first, then Income-Lab risk-based) — the biggest
   sustainability lever; extends the income-floor + longevity distribution. _NET-NEW._
3. **Equity release as a 4th housing strategy** — the clearest unoccupied position; builds on the
   buy/rent/stay-on-identical-paths machinery + IHT engine. _NET-NEW._
4. **The FCA four stress tests + a UK "retire into a bad year" historical mode** — sharpens the planned
   stress-test panel. _Sharpens a backlog item._
5. **A single success-probability gauge (+ first-shortfall-year, + longevity-adjusted success rate) + a
   Sankey** — cheap framing wins on output already computed. _Mostly NET-NEW._

---

## 7. Two source-freshness flags (for the `figures:freshness` discipline)

- **SDLT nil-rate band reverted to £125,000 on 1 April 2025** (FTB relief £425k → £300,000). The 2026-06-27
  gov.uk verification pass post-dates this, so the engine's `SdltParameters` should already carry it —
  worth a one-line confirm. (Engine SDLT bands are parameterised per tax year, not hardcoded.)
- **The £86k care-cost cap is cancelled** (announced 29 Jul 2024), not delayed; the Care Act ss.15–16
  provisions remain un-commenced. Modelling **no cap** is correct. England 2025/26 means-test (frozen 15th
  year): upper capital £23,250, lower £14,250, tariff income £1/wk per £250 between — confirm against the
  engine's `care` config. The Casey Commission (Phase 1 reports 2026, final 2028) is the live reform vehicle.

---

## Sources

**Independent / consumer:** [Guiide](https://www.guiide.co.uk/) · [retirecalc.uk](https://www.retirecalc.uk/) +
[methodology](https://www.retirecalc.uk/methodology.html) · [Which? drawdown calc](https://www.which.co.uk/money/pensions-and-retirement/accessing-your-pensions/pension-income-drawdown/income-drawdown-calculator-making-your-money-last-ajWVD8L3bN92) ·
[PensionBee calc](https://www.pensionbee.com/uk/pension-calculator) · [Moneyhub](https://moneyhub.com/) ·
[Multiply](https://www.multiply.ai/)

**Prosumer / FIRE:** [ProjectionLab UK](https://projectionlab.com/united-kingdom) · [PL cash-flow/Sankey](https://projectionlab.com/cash-flow) ·
[Pralana analysis & optimisation](https://pralanaretirementcalculator.com/analysis-optimization/) ·
[Boldin Roth Conversion Explorer](https://help.boldin.com/en/articles/6888336-boldin-s-roth-conversion-explorer) ·
[cFIREsim](https://www.cfiresim.com/) · [FIRECalc](https://www.firecalc.com/intro.php) ·
[Honest Math](https://www.honestmath.com/) · [Income Lab guardrails](https://incomelaboratory.com/retirement-income-guardrails-complete-guide/) ·
[Kitces risk-based guardrails](https://www.kitces.com/blog/risk-based-monte-carlo-probability-of-success-guardrails-retirement-distribution-hatchet/) ·
[RightCapital tax planning](https://www.rightcapital.com/tax-planning/) · [Rob Berger 3-tool comparison](https://www.bogleheads.org/forum/viewtopic.php?t=444911)

**Decumulation research:** [ERN SWR series](https://earlyretirementnow.com/safe-withdrawal-rate-series/) ·
[Guyton & Klinger 2006 (PDF)](https://www.financialplanningassociation.org/sites/default/files/2021-11/2006%20-%20Guyton%20and%20Klinger%20-%20Decision%20Rules%20and%20SWR%20(1).PDF) ·
[Okusanya, *Beyond the 4% Rule* (Monevator)](https://monevator.com/beyond-the-4-rule-abraham-okusanya/) ·
[Morningstar UK safe withdrawal 2026](https://www.morningstar.com/retirement/whats-safe-retirement-withdrawal-rate-2026) ·
[Retirement spending smile (Kitces)](https://www.kitces.com/blog/estimating-changes-in-retirement-expenditures-and-the-retirement-spending-smile/) ·
[Bogleheads VPW](https://www.bogleheads.org/wiki/Variable_percentage_withdrawal) ·
[Vanguard UK withdrawal order (PDF)](https://www.vanguard.co.uk/content/dam/intl/europe/documents/en/whitepapers/withdrawal-order-making-the-most-of-retirement-assets-uk-en-pro.pdf)

**UK adviser tools:** [Voyant Monte Carlo (UK)](https://support.planwithvoyant.com/hc/en-us/articles/360019522991-Monte-Carlo-More-about-the-Monte-Carlo-Insight-UK) ·
[Timeline — MC vs historical](https://help.timeline.co/en/articles/2423846-monte-carlo-vs-historical-simulation) +
[longevity chart](https://help.timeline.co/en/articles/6976010-explaining-the-longevity-chart) +
[UK Income Tax module](https://www.timeline.co/resources/introducing-timelineapp-uk-income-tax-module) ·
[FE CashCalc Monte Carlo](https://professionalparaplanner.co.uk/cashcalc-embeds-monte-carlo-simulation-into-cashflow-tool/) ·
[Dynamic Planner risk profiling](https://dynamicplanner.com/investment-risk-profiling/) ·
[Conquest SAM](https://conquestplanning.com/en-ca/sam) · [EValue asset model](https://www.ev.uk/ev-asset-model) ·
[Oxford Risk — accumulation vs decumulation](https://www.oxfordrisk.com/blog-posts/how-should-risk-tolerance-assessments-differ-between-accumulation-and-decumulation) ·
[Selectapension DB transfers](https://selectapension.com/solutions/defined-benefit-transfers/)

**Providers / housing / care:** [Aviva equity release calc](https://www.aviva.co.uk/retirement/equity-release/equity-release-calculator/) ·
[L&G lifetime mortgage calc](https://www.legalandgeneral.com/retirement/lifetime-mortgages/lifetime-mortgage-calculator/) +
[interest roll-up](https://www.legalandgeneral.com/retirement/lifetime-mortgages/interest-rates/) ·
[Royal London equity release](https://www.royallondon.com/retirement-planning/releasing-equity/equity-release-calculator/) ·
[Saga — true cost of downsizing](https://www.saga.co.uk/money-news/the-true-cost-of-downsizing-uncovered) ·
[GOV.UK SDLT rates](https://www.gov.uk/stamp-duty-land-tax/residential-property-rates) ·
[GOV.UK social-care charging 2025–26](https://www.gov.uk/government/publications/social-care-charging-for-local-authorities-2025-to-2026/social-care-charging-for-care-and-support-2025-to-2026-local-authority-circular) ·
[King's Fund — care cap abandoned](https://www.kingsfund.org.uk/insight-and-analysis/blogs/labour-cap-on-care-costs) ·
[Equity Release Council — housing wealth](https://www.equityreleasecouncil.com/news/unlocking-property-wealth-for-retirement-could-inject-21bn-each-year-into-uk-economy-by-2040/)

**FCA / regulatory:** [TR24/1 (PDF)](https://www.fca.org.uk/publication/thematic-reviews/tr24-1.pdf) ·
[Dear CEO letter](https://www.fca.org.uk/publication/correspondence/dear-ceo-letter-thematic-review-retirement-income-advice.pdf) ·
[Cashflow-modelling article](https://www.fca.org.uk/firms/undertaking-cashflow-modelling-demonstrate-suitability-retirement-related-advice) ·
[RIAAT](https://www.fca.org.uk/firms/retirement-income-advice-assessment-tool-riaat) ·
[Consumer Duty PRIN 2A](https://handbook.fca.org.uk/handbook/prin2a) ·
[PS25/22 targeted support](https://www.fca.org.uk/publications/policy-statements/ps25-22-consumer-pensions-investment-decisions-rules-targeted-support) ·
[COBS 13 Annex 2](https://www.handbook.fca.org.uk/handbook/COBS/13/Annex2.html)
