# Research (delta pass) — uncertainty communication, household composition, adviser outputs, accessibility/mobile, methodology disclosure

_Last updated: 2026-07-02_

> **Scope.** A **delta** research pass over the five topics the 2026-06-30 competitive scan
> ([RESEARCH-competitive-gap-analysis.md](RESEARCH-competitive-gap-analysis.md)) and the earlier
> research notes left as **residuals or gaps**. The four already-settled topics — scenario
> presentation, tax-modelling tiers, cashflow-ladder shape, assumptions-management UX — were **not**
> re-researched (see that scan + [RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md) +
> [RESEARCH-editable-assumptions-ux.md](RESEARCH-editable-assumptions-ux.md)). Every load-bearing claim
> below was **adversarially source-checked**; corrections from that pass are folded in and the residual
> caveats flagged. Net-new backlog items land in [PLAN.md](../build/PLAN.md) "Delta-research backlog (2026-07-02)".
> UK-only throughout; US findings are marked and used only for directional patterns.

---

## 1. Communicating uncertainty (Monte Carlo presentation)

**Top line.** RetireForecast's **deterministic-ladder-first** layout already matches the UK regulatory
convention, and its table+CSV-per-chart already beats most tools. The evidence points to a handful of
cheap, high-value framing upgrades — not a redesign.

**Key findings (sourced):**
- **UK convention is deterministic-leads, stochastic-as-supplement.** SMPI/AS TM1 statutory illustrations
  are a single deterministic projection; FCA COBS 13 permits a stochastic projection only where the client
  can understand it and **the most prominent projection must be deterministic**; the FCA **scrapped PRIIPs
  percentile performance scenarios** (PS22/2, in force 25 Mar 2022 / comply by 31 Dec 2022) as
  "significantly over-optimistic" and misleading. So percentile fans shown *without context* are a
  regulator-flagged hazard. _(Source-check note: the "deterministic must be most prominent" rule sits in
  COBS 13.5.1R/13.5.2R, not 13 Annex 2; AS TM1 v5.2 was published 6 Feb 2026, **effective 6 Apr 2026**.)_
- **Quantified uncertainty does not erode trust and aids comprehension.** van der Bles et al. (PNAS 2020,
  n=5,780 incl. a BBC field experiment): numeric ranges barely dent trust; verbal hedging hurts more.
  ESCoE's UK GDP experiments: intervals/density strips/bell curves beat text and "no communication".
- **Frequency formats + icon arrays beat bare percentages** for low-numeracy audiences (Gigerenzer;
  Springer 2018), **but "1-in-X" phrasing inflates perceived risk** vs a fixed out-of-100 denominator.
- **The lay failure mode is "deterministic construal"** — reading band edges as point predictions
  (Joslyn/Savelli); the Bank of England's own fan-chart literature notes the persistent "fan covers 100%"
  misconception (BoE fans cover 90%).
- **"Probability of success" is the worst framing** (Kitces/Tharp: binary, fear-inducing, invites the
  "wrong-side-of-maybe" fallacy). The strongest adviser-market alternatives: **"probability of
  adjustment"** and guardrails in **currency** (Income Lab — "cut ~£X/month in the lean cases"), and
  **mortality-weighting the ruin risk** (Timeline decomposes a scary "26% ruin at 100" into "2.6% alive
  **and** out of money"; the "Rich, Broke or Dead" chart is the most-praised consumer visual of this).
- **UK anchors are concrete, not probabilistic** — the PLSA minimum/moderate/comfortable standards were
  built as comprehension "rules of thumb"; income-coverage framing beats terminal-pot framing
  (Goldstein/Hershfield/Benartzi "illusion of wealth").

**Recommendations for RF** (all reuse figures the engine already computes):
- **[small]** Headline the risk as a **natural frequency with a fixed /100 denominator + a 10×10 icon
  array** coloured by the existing successEssentials / successFullSpend / depletion split ("in 12 of 100
  simulated futures the money runs short"). Never "1 in 8".
- **[medium]** Add **consequence metrics** beside the probability: the median depletion age (already
  computed) and the **£/month spending cut** that brings the ran-short share below ~5/100 (a solver loop
  over the engine); directive phrasing behind the `interpret` gate.
- **[medium]** **Mortality-weight the ruin risk** using the existing joint-life model — report "chance of
  being alive when the money runs short" (start with the single number; a Rich-Broke-Dead panel later).
- **[small]** **Harden the fan chart** against deterministic construal: in-plot band labels, a "1-in-10
  futures fall below the fan" line, an optional thin-sample-path overlay.
- **[small]** **Record the deterministic-leads ordering as a decision** citing COBS 13.5 + the PRIIPs
  removal, so a future redesign can't invert it into a public-release problem.

---

## 2. Household composition + a third adult contributing to upkeep

**Top line.** **No mainstream tool supports a third adult as a planning subject** — so RF's couple ceiling
is the market norm, not a gap. Rob's actual interest ("3 adults contributing to upkeep") is best served by
a **person-less household income stream + a lightweight presence record**, which would make RF the only
tool modelling the UK mechanics natively.

**Key findings (sourced):**
- **Market ceiling is a couple.** Boldin: "the Planner models only one user/couple per plan"; Timeline: a
  household is "a main profile plus a spouse"; ProjectionLab: single/couple + dependants, with a 239-vote
  **unbuilt** family-account request; Guiide: single-person only (run two, add by hand). **Voyant** is the
  most flexible (add any number of spouse/partner/child *people*, distinguishes legal vs "non-legal"
  partners for IHT/net-worth) but extra people are **dependants, not co-planned adults**.
- **The person-less pattern already has a precedent.** Voyant's **official** answer for lodger/board income
  is a manual workaround: "The tax rules for the Rent a Room Scheme are **not built into the software**" —
  enter £7,500 as non-taxable Other Income + the excess as a separate taxable stream. No tool applies
  Rent-a-Room automatically.
- **UK mechanics of a contributing third adult:**
  - **Rent-a-Room:** up to **£7,500/yr gross** receipts tax-free (halved to £3,750 if shared), automatic
    below threshold; receipts include rent + meals/laundry/utilities. _(Source-check: cite the main
    gov.uk/rent-room-in-your-home page — the HS223 helpsheet named "2026" actually covers **2025-26**.)_
  - **Family board money** (a relative paying "keep") in a genuine cost-sharing arrangement is **not
    taxable income at all** — never reaches the Rent-a-Room question. _(Strong-but-informal authority: HMRC
    states this only in community-forum answers, which were bot-blocked; practitioner consensus agrees, no
    HMRC-manual paragraph to cite.)_
  - **A resident non-dependant adult REDUCES entitlements:** loss of the **25% council-tax single-person
    discount**; loss of Pension Credit's **Severe Disability addition** (£86.05/wk single, £172.10 couple —
    requires living alone); non-dependant deductions in Housing Benefit (£20.40–£131.45/wk, 2026/27),
    Universal Credit (£96.55/mo), and PC housing-cost extras. A commercial lodger is treated **oppositely**
    to a family non-dependant across HB/UC.
- **This is the reconciliation rule in reverse:** a third adult must be able to **reduce** benefits, not
  only add income — else the forecast silently overstates entitlements.

**Recommendations for RF:**
- **[small]** **Record the decision (DECISIONS):** RF will **not** support a third full planning subject;
  "third adult" = a household member + a board contribution only. (Market evidence is unanimous.)
- **[medium]** A household-owned **`BoardContribution`** income stream (attached to the *household*, not a
  person, so it survives the first death and ends on its own departure date) with a **three-way tax enum**:
  `family_cost_sharing` (wholly non-taxable, default for family keep), `rent_a_room` (engine applies the
  £7,500/£3,750 exemption, excess taxable, threshold sourced + `verified_on`), `taxable_rent` (fully taxed).
- **[medium]** A lightweight **`HouseholdMember`** presence record (arrival/departure window + only the
  flags downstream needs: council-tax-disregarded?, has qualifying disability benefit / PC?, age band,
  works-16h+/income band) — **no mortality, no pension, no tax computation**. Keep it a distinct type, not
  a degenerate Person (one-definition-one-home).
- **[medium]** Wire the **two interactions that bite** for this demographic and stub the rest: suppress the
  **25% council-tax discount** when a non-disregarded member is present alongside one planning subject
  (e.g. after first death); suppress the **PC Severe Disability addition** while a non-dependant is present
  (unless flagged); treat family board money as **not** PC-means-test income; apply the £20+50% boarder
  disregard only for a commercial boarder. HB/UC/CTR deduction tables stay documentation-only unless RF
  models rented housing.
- **[small]** Reuse the same `HouseholdMember` shape for **dependent children with time-limited costs** (a
  presence window + bound expense streams — the other composition gap).

---

## 3. Adviser-grade outputs (a "take this to an adviser / Pension Wise" pack)

**Top line.** A real UK adviser report has a stable anatomy, and the FCA's own file-review tool (RIAAT) +
TR24/1 define exactly what a decumulation output must evidence. RF's PDF already covers the core; the gaps
are the **five statutory risk warnings**, a **scenario-comparison section with an interpretation block**, an
**assumptions annex**, and a **Pension Wise fact-find appendix**.

**Key findings (sourced):**
- **A real Voyant client report (25pp)** carries: branded cover with a **Client File Version** hash;
  Financial Summary; a **Default Assumptions page** (incl. withdrawal/liquidation order, per-wrapper fees,
  and an "assumptions may have been altered" caveat); Events & Goals timeline; per-goal funding as **"met
  for X of Y years"**; balance sheet; insight analyses each with a cashflow chart; year-by-year **"(Real
  Money)"** tables indexed by **both** partners' ages; estate/IHT incl. an "if both die today" scenario.
- **FCA cashflow-modelling expectations** (TR24/1 companion): real-terms **net-of-tax** figures; **all
  charges included**; returns not based solely on past patterns and **consistent across illustrations/risk
  tools/cashflow**; project **beyond average life expectancy**; **stress-test with plausible scenarios**;
  explain divergences from other communications.
- **RIAAT** (the FCA's file-review workbook) is a ready-made checklist: 8 information areas + a disclosure
  tab testing COBS 9.4.7R (demands & needs / why suitable / **possible disadvantages**) and the **five
  COBS 9.4.10G drawdown risk warnings**: capital may be eroded; returns may be less than illustrated;
  annuity rates may be worse in future; income may not be sustainable; there may be tax implications. **11
  of 67 reviewed files failed** to give these. A named poor-practice case: quoting "95% chance the fund
  lasts to average life expectancy" **with no interpretation** is itself a documented failing.
- **Timeline** caps its success rate at **99%** "to avoid giving the client an impression that there is a
  guarantee", and ships modular removable report sections. **A "take to Pension Wise" pack** maps 1:1 onto
  the published appointment-prep checklist (per-pension values + safeguarded-benefit flags, State Pension
  forecast, other income, essential-vs-discretionary spend, questions to ask).

**Recommendations for RF:**
- **[small]** Add the **five COBS 9.4.10G risk warnings** to the PDF as distinct factual bullets (+ the
  TR24/1 longevity/inflation framing). The banned-phrase lint already guards against drift into advice.
- **[small]** Promote a **Plan Version** identifier (input-fingerprint hash + engine version + tax-data
  `verified_on` + run date) to the PDF cover, Voyant-style — surfacing existing provenance.
- **[small]** Label every projection page **"real terms (today's money), net of tax"**; make fees/charges a
  **visible ladder line**, not an embedded assumption (two of the RIAAT's six named failure modes).
- **[small]** Plain-English **per-objective funding lines** ("essentials met in N of M years; first
  shortfall at age X") — reuses the essential/discretionary split TR24/1 faulted firms for not recording.
- **[medium]** An **assumptions annex** (every assumption + source URL + `verified_on`, "altered from
  default" markers, the single growth/inflation basis) — turns the no-magic-numbers discipline into a
  visible differentiator.
- **[medium]** A **scenario-comparison section** (base vs historical worst-start vs MC percentiles vs
  care-shock, same funding metrics) with a **fixed plain-English interpretation block** and a sub-100%
  sustainability figure.
- **[medium]** A **"Take this to Pension Wise / an adviser" appendix**: a pre-filled fact-find on the
  RIAAT 8 areas + the Pension Wise checklist, with a plan-specific "questions to ask" list. Converts the
  PDF from *results* into the starting document for a guidance conversation without giving advice.
- **[medium]** A **version-to-version change log** page (diff inputs/assumptions vs the previous run).
- **[large]** A **modular pack** ("self" / "adviser-Pension-Wise" / "full" presets). **[large, personal-use
  mode only]** a suitability-style narrative (objectives / ATR / options considered / chosen strategy +
  disadvantages) — must stay behind `compliance.personal_use`; the lint keeps it out of public posture.

---

## 4. Accessibility + mobile UX

**Top line.** **WCAG 2.2 AA is now the operative UK baseline** (the standard the direct domain leaders
build to). RF's current 2.1 AA target is one tier behind at launch; the delta is small for a form-heavy
app. The one current choice with **no market precedent** is **hiding the results nav on mobile** — that
makes content unreachable.

**Key findings (sourced):**
- **WCAG 2.2 AA is the operative UK standard.** GOV.UK service manual: "Services must achieve WCAG 2.2
  level AA"; GDS has monitored against 2.2 AA since **October 2024**; the MoneyHelper Pensions Dashboard,
  DWP pension services and **Aviva** build/target 2.2 AA — **L&G still claims only 2.1 AA** with listed
  exceptions. (Private tools aren't legally bound — Equality Act 2010 applies — but this is the norm.)
- **The 2.2 delta is six A/AA criteria:** Focus Not Obscured (AA), Dragging Movements (AA), **Target Size
  24×24px** (AA), Consistent Help (A), **Redundant Entry** (A), Accessible Authentication (AA); 4.1.1
  Parsing removed. Redundant Entry + Target Size are the load-bearing ones for a wizard. **axe/Pa11y
  automate almost none of these** — they need a manual checklist.
- **UK chart doctrine** (Gov Analysis Function + GDS): no chart is fully accessible, so every chart needs a
  **body-text description of its message** (not in the alt attribute; mark the image decorative) plus an
  accessible data download. RF's table+CSV already exceeds ApexCharts' built-ins (keyboard/ARIA + colour-
  blind palettes, but **no sonification, no data-table export** — unlike Highcharts); the missing cheap
  piece is the **message description**.
- **Mobile pattern for complex planners is responsive-web with desktop-recommended setup.** Boldin and
  ProjectionLab ship **no native app**; PensionBee's app is **monitor-only**; the MoneyHelper dashboard is
  responsive web that "works better on mobile… reduces cognitive load". The **GOV.UK question-pages**
  pattern (one thing per page → degrades to mobile for free; check-answers step; avoid rich progress bars)
  is the evidence-based UK form baseline.

**Recommendations for RF:**
- **[small]** Raise the target to **WCAG 2.2 AA** before public release; encode the six new criteria as a
  **manual checklist** (24px targets; focus not obscured by sticky headers; no drag-only sliders; help in a
  consistent place; never re-ask captured data; no cognitive-test auth).
- **[small]** Publish a **GOV.UK-style accessibility statement** at release (claimed standard, enumerated
  known failures, how/when tested, a contact route) — Voyant/Timeline publish nothing, so this is easy
  differentiation.
- **[medium]** Charts: keep table+CSV, add a **one-to-two-sentence body-text description of each chart's
  message** (SVG marked decorative), non-colour series encoding, and verify ApexCharts keyboard nav ships.
- **[medium]** Mobile: **stay responsive-web, desktop-recommended setup**; make the **results/monitoring
  surface** work on mobile and **replace the hidden results nav** with a real mobile navigation
  (accordion / sticky summary with jump links). State the posture: "plan on a big screen, review anywhere".
- **[large]** Restructure the ~70-input wizard toward **GOV.UK question-pages** (one topic per page +
  check-answers) — this is how a desktop-first wizard becomes mobile-capable for free (and satisfies WCAG
  2.2 Redundant Entry).
- **[medium]** Before release run **one manual assistive-tech pass** (keyboard-only, NVDA + VoiceOver, 400%
  zoom/reflow, chart fallbacks read aloud) and record it in the statement; keep axe/Pa11y as regression
  protection, not the conformance claim.

---

## 5. Methodology disclosure (a user-facing "how this forecast works" layer)

**Top line.** RF's **internal** provenance already **exceeds every public example found** (per-figure
source URL + `verified_on`, admin audit page, per-run snapshot, HMRC-worked-example tests) — but it is
invisible to users. A single `/methodology` page rendered partly from the existing registries would be a
**market-unique** trust signal.

**Key findings (sourced):**
- **The best pages share one anatomy** (retirecalc.uk is the closest UK exemplar; FI Calc/Boldin/ProjectionLab
  the others): an "audit us, don't trust a black box" intent statement; numbered TOC by model component;
  per-component plain-English mechanics with concrete parameters + tax-year labels; a consolidated **"what
  we don't model"** list; **named data sources**; an FAQ; a regulatory-posture disclaimer; and — rarest —
  **a dated changelog** (only ProjectionLab; the FRC's annual AS TM1 review is the statutory equivalent).
- **No tool found publishes a validation/accuracy claim against official worked examples**, and **none
  publishes per-figure verification dates** — both of which RF already has internally.
- **UK statutory disclosure vocabulary** (not legally binding on a free non-advice tool, but what UK users
  already recognise): SI 2013/2734 Sch 6 statements ("an illustration… not a promise or guarantee",
  "expressed in today's prices", "assumptions may not correspond with your actual investments"); COBS 13's
  today's-money/three-rate conventions; AS TM1's prescribed assumptions. _(Source-check: AS TM1 v5.2's
  volatility-group rates are 2/4/6/7% — many secondary sites still show the superseded 1/3/5/7%.)_

**Recommendations for RF:**
- **[medium]** Build a single **`/methodology`** page: intent → numbered TOC → one section per component
  (tax/NI; wrappers & sequencing; Monte Carlo; historical backtest; joint-life mortality; care risk;
  housing; State Pension & DB) each with plain-English + expandable technical + concrete values → a
  **"what this does not model"** list → an **auto-rendered sources-and-dates table** generated from
  TaxYearRegistry/MortalityDataset/CareAssumptions/HistoricalReturns → FAQ → regulatory-posture block. The
  fragmented results-page panels then deep-link into anchored sections of this one canonical page.
- **[small]** A **"How we test this"** section — the accuracy claim no competitor makes (reproduces HMRC
  worked examples; per-source completeness; reconciliation invariants). Derive the "as of" date from CI,
  don't transcribe test counts (doc-hygiene rule).
- **[small]** A **public assumptions changelog** (one dated lay line per statutory-figure/dataset update),
  generated from registry diffs with `verified_on` — the rarest trust signal.
- **[small]** Adopt the **UK statutory disclosure vocabulary** as fixed boilerplate on results + methodology
  (lint-safe), noting explicitly that AS TM1/COBS don't formally bind this tool but its assumptions are
  disclosed to a comparable standard.

---

## What was deliberately NOT re-researched (already settled)

Scenario presentation (base + what-if delta children + Compare), tax-modelling tiers, the deterministic
cashflow-ladder shape, and assumptions-management UX are covered by the 2026-06-30 competitive scan +
[RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md) +
[RESEARCH-editable-assumptions-ux.md](RESEARCH-editable-assumptions-ux.md); the data-source/licence study
is in [RESEARCH-stress-test-and-official-sources.md](RESEARCH-stress-test-and-official-sources.md). This
delta pass touched only the residual/gap slices of those topics.

## Source-check summary

All five topics passed adversarial verification as **trustworthy**; no substantive claim was refuted. The
corrections folded in above: cite gov.uk/rent-room-in-your-home (not the "2026" HS223, which is 2025-26)
for Rent-a-Room; the FCA "deterministic must be most prominent" rule lives in COBS 13.5, not 13 Annex 2;
AS TM1 v5.2 is effective 6 Apr 2026 with **2/4/6/7%** volatility rates; the one-page-summary requirement is
COBS 19.1 (not 9.4); the UC non-dependant £96.55/mo is confirmed current for 2026/27. Residual soft spots
(flagged, not load-bearing): family-board-money-not-taxable rests on HMRC forum answers + practitioner
consensus (no manual paragraph); the FCA handbook SPA and some vendor support pages block automated
fetching, so a few tool-behaviour claims rest on search snippets.
