# Session log archive — RetireForecast

> The dated prose session log, moved out of `docs/HANDOVER.md` on 2026-08-01 when the handover
> split into two artefacts: the handover for what is **true**, `docs/board/` for what is
> **moving**. A prose log inside the handover was a second copy of both the commit history and
> the decision log, and it was the section that grew fastest.
>
> Nothing here is load-bearing. The narrative is `git log --format='%ad %s%n%b'`, the
> rationale is [DECISIONS.md](../DECISIONS.md), and the older per-feature build record is
> [HANDOVER-ARCHIVE.md](../HANDOVER-ARCHIVE.md). Kept because deleting a record costs nothing to
> write and everything to recover. Newest first.

_Newest first. Only the recent live window; older sessions are folded into [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) + git log + DECISIONS._

_2026-07-31 (adviser-parity A3: the ISA cap, and a divergence note that pointed the wrong way)_ —
Took the last OPEN correctness gap from the adviser-parity sweep. **Measured it before building**, and
the measurement contradicted the record: the DATA-MODEL entry said the missing cap "biases most for the
highest-surplus sell-and-invest plans", but a sale's proceeds are invested into a **GIA** and surplus
banks to **cash**, so no housing variant ever sheltered a penny through it. The gap only ever bit on an
explicitly-entered ISA contribution above £20,000/yr, which no stored scenario has. Corrected the note
in place rather than leaving a wrong severity to mis-prioritise the next session — and recorded the
larger, opposite gap it was masking: the engine never *uses* the allowance either, so the
sell-and-invest plans are if anything **understated**. Built the cap anyway (it is a real rule the model
broke), spilling the excess to the GIA rather than dropping it, because dropping it would make the
household look poorer when the truth is it is more taxed. **The first version of the test was worthless
and looked fine** — at zero dividend yield an ISA and a GIA are indistinguishable, so every assertion
would have passed with the cap deleted; re-cut with a real 3% yield and then **verified to fail** by
disabling the cap.

_2026-07-31 (adviser-parity B1: the cost of advice, built from the one figure that could be sourced)_ —
Same session, straight on from B2. Re-verified the plan's ⚠️ fee figures first, and that decided the
design: the **0.83% ongoing fee** confirmed on NextWealth's own page and two trade reports, but the
drafted **~1.80% total cost of ownership** existed only in search-engine summaries no fetch could
confirm. Rather than ship a magic number wearing a citation, constructed the advised side as *the
household's own charge plus the fee* — which is also the more honest model, since the unverified part
(how much dearer an advised fund choice is) varies too much between an in-house model portfolio and a
whole-of-market tracker to assume, and guessing it would have invented the larger half of the answer.
Kept the fee **out of `AssumptionSet`**: the forecast never charges it, and an assumption set that
listed it would imply otherwise. Returns **null** when nothing is invested rather than "£0 either way",
which would read as "advice is free" when an adviser would in fact charge such a household a fixed fee.
**V2 finding:** advice barely matters to this household (~£1,278 lifetime on the stay-put base) because
it has almost nothing invested — except on **sell-and-rent (~£11,941)**, the one plan that genuinely
invests. Full suite green, pint clean, `scenarios:audit` clean.

_2026-07-31 (adviser-parity B2: the protection gap, and seven levers that could silently drop a field)_ —
Resumed via `/handover resume`. What's next #1 is Rob's sign-off and #2 is release-gated, so took the
plan's own next item: **B2 protection gap**. Verified the tax and IHT treatment against HMRC's Pensions
Tax Manual and gov.uk **before** modelling anything, rather than shipping the plan's ⚠️ figures on trust
— which is how the LSDBA test, the age-75 rule and the April-2027 IHT carve-out came to be modelled at
all. Built the stress through **existing DTO fields** (`LongevityAdjustment::fixedAge` for the death,
`CapitalReceipt` for the cover) rather than a new projector mode, so a stressed path IS the ordinary
projection and no tax, benefit or drawdown logic can diverge between the two.
**Two things the tests earned.** (1) A "the payout persists" assertion failed by £1,072 — chased it
rather than loosening the delta, and it was **real**: £160,000 of capital ends the survivor's Pension
Credit, the same trap the tool already shows on a house sale. Pinned as its own test instead of hidden.
(2) A threshold test that handed the household the solved sum as a capital receipt failed because a
receipt in the builder state arrives **whether or not anyone dies**, so it lifted the baseline too and
moved the very bar being measured; re-cut through death-contingent cover.
**Then the measurement changed the story.** The adviser reflex is to insure the earner. Here the
*working* partner's death leaves the survivor no worse off, while the *retired, disabled* partner's
death is the damaging one — it takes their State Pension, their disability benefit and the couple's
Pension Credit while the survivor still carries the stay-put mortgage. Checked that against the
stressed ladders line by line before believing it. Added the honest corollary to the panel: cover on
someone older or unwell may be expensive or unavailable, and a lump sum is only one way to close the
hole.
**Also closed a latent drift bug found on the way:** seven sweep levers each rebuilt `Household`
positionally, so a field added to the DTO and forgotten in a lever would be silently dropped from every
swept forecast. One private `copy()` now owns it, guarded by a reflection-driven test that enumerates
the DTO's own properties — **verified to fail** by dropping a field. Pint's `fully_qualified_strict_types`
fixer again tried to turn a `{@see}` into a real `use` (this time giving a Dto a Forecast dependency);
reworded to plain text with a note, as on 2026-07-30. Full suite green, pint clean, `scenarios:audit`
clean, assets rebuilt.

_2026-07-31 (adviser-parity A2: net-pay contribution relief, and the employer's contribution stops being
charged to the household)_ —
Continued the same session after A1. `applyContributions` took contributions from net surplus with no
relief; reading it for the fix surfaced two further defects in the same function, both structural rather
than a missing figure — the **employer's** contribution was funded from household surplus (charging them
for someone else's money, and **silently dropping it** in a year with none), and contributions ran for
ever after retirement. Modelled net pay as what it physically is: the contribution comes off gross pay
before the household sees it. That gives relief through the engine's single tax pass rather than a
parallel calculation that could drift, leaves NI correctly untouched, dissolves the
surplus-depends-on-tax-depends-on-relief circularity, and caps the contribution at pay so it ends with
the salary — no separate retirement gate to forget. Made `ReliefAtSource` **throw**: it is a real method
the projector does not model, and accepting it would give no relief while the input said otherwise.
**One test initially passed for the wrong reason** — the "employer contribution survives a year with no
surplus" case put the money in and the shortfall drew it straight back out of an accessible pot; re-cut
with the member below the access age so the pot is locked and the property is actually isolated.
**Then found the work changes nothing for the real household:** no V2 scenario records any DC
contribution at all. Raised as an open question rather than papered over by assuming an auto-enrolment
figure. Full suite green, pint clean, `scenarios:audit` clean.

_2026-07-31 (investment charges built; then six shipped figures found never to have reached a forecast)_ —
Resumed via `/handover resume`. What's next #1 is Rob's sign-off and #2 is release-gated, so took the item
the plan itself ranks above the refinements: **A1 fee drag**, the largest silent optimism in the model.
Re-verified the plan's ⚠️ figures against primary sources first (DWP's two surveys, the statutory cap,
Vanguard's own fee page) rather than shipping the drafted 0.50% on trust. **Departed from the standing
adverse-default rule on purpose, and recorded why:** the charge falls on invested wealth and not on housing,
so it is not monotonic in optimism — an over-adverse figure would tilt the sell-vs-stay comparison the tool
exists to make, so 0.50% is the adverse side of the *central* case, not the top of the range. Kept growth
**gross** and carried the charge as its own pounds figure, because netting it in would have satisfied the
arithmetic while breaching the no-invisible-figures rule.
**Then the measurement stage earned its keep.** The V2 scenarios came back **identical to the penny**, so
the charge was reaching nothing. Root cause: the app reads its assumptions from the `assumption_sets`
**table**, seeded once, and the mapper's back-compat null (right for a frozen run snapshot) silently means
"pre-feature behaviour" for the live set. Auditing every key found **six** shipped figures that had never
reached any of the 14 scenarios — including the stochastic house-price growth, stochastic salary growth and
above-CPI care escalation of 2026-07-18. Each was built, tested, documented and recorded as shipped; the
engine work was correct; the figure never arrived. Re-seeded (after checking the only differences were the
six absent keys, and backing up the payloads), and added the missing-key check to `scenarios:audit` with
`AuditScenariosTest` proving it fails on a stale set and passes on a freshly seeded one. Deliberately did
**not** "fix" it by defaulting `hydrate()` to the library, which would rewrite stored history to solve a
live-data problem. Also chased down an identical £2,765 delta across five structurally different plans
before believing it: real, not a bug — those plans accumulate surplus as **cash**, which correctly bears no
charge, so only their (identical) DC pot is charged. Full suite green, pint clean, `scenarios:audit` clean,
assets rebuilt.

_2026-07-30 (the PDF made a complete print of the results page, charts and all)_ —
Rob: *"Need to get all of the information in the webpage into the pdf download"*, then mid-task
*"the lack of graphs in the PDFs makes them very difficult to use for sharing as intended"*. Audited
screen against print first rather than guessing: the export carried roughly a third of the page, and
the omissions included the **assumed-figure disclosures** — so the artefact a reader is actually handed
breached the "no invisible figures" rule even though the screen satisfied it. **Probed the renderer
before designing around it** (a throwaway dompdf render, inspecting the inflated PDF content stream):
dompdf **silently ignores an inline `<svg>`** — only the `<text>` leaked into the page as flowed text —
but renders `<img src="data:image/svg+xml;base64,…">` as true vector ops, with `fill-opacity`,
`stroke-dasharray` and `text-anchor` all honoured. That result decided the design: a small `ChartSvg`
that draws from the **screen chart's own ApexCharts option blob**, so there is no second data pipeline
to drift. Rejected Browsershot/headless-Chrome (would print the real canvases, but adds Node + Chromium
to a local-first tool). **Found a live bug on the way:** the PDF selected the ladder strategy from
`$scenario->variant` while the screen clamps to a strategy the inputs configure, so a scenario stored as
"sell & rent" with no sale price printed a *rented* ladder against the screen's stay-put — the
"`deterministic()` ignores the variant" trap for the third time, now behind one `LadderContext` with a
parity test. Made completeness **derived, not listed**: the test reads the results component's own view
data and fails on any key the export drops, with a documented interactive-only allowlist (run controls,
lever sliders, the threshold explorer, the assistant). **No rasterizer on this machine** (no poppler /
ImageMagick / Ghostscript), so the charts were verified numerically instead — a geometry audit over a
real scenario's four charts confirming axis spans, no coordinate or label overflow, and stacked axes
that span the stack rather than the tallest series. Also caught Pint's `fully_qualified_strict_types`
fixer importing a **test** class into the production controller from a `{@see}` docblock; reworded to
plain text. **Then Rob reviewed the output and rejected the presentation** — charts too small, layout
unlike the web view, and the fan's screen-only "Include home value" toggle leaving one of the two views
unprinted. Reworked: the results page's own idiom (cards, coloured stat tiles, verdict pills, badges, row
tints) rebuilt as layout tables, charts redrawn page-width at 1000×480, and **both** fan bases printed.
**Reviewing his PDF also surfaced a defect neither of us had named:** the ~24-column ladder overflowed
the paper and dompdf silently **clipped** it — the final total-wealth column read `£225,5`. Split into
two tables sharing the Year/Age key. The first clipping guard written for it was **worthless and looked
fine** — asserting the figure appears in the PDF passes even when it is painted off the page — so it was
replaced with one that decodes the content streams, maps text through the graphics-state transform into
page coordinates, and was **verified to fail** by inflating the table font until it overflowed. Separately
confirmed that the "Commute Fuel" tier headings in Rob's PDF were a live `expenseBreakdown()` variable-
shadowing bug affecting the screen too, already fixed in-tree by the concurrent session — not this work.
**A third review round** (Rob, scoped to *both* surfaces): monthly beside annual on the spending plan;
suppress all sale content for a plan that does not sell; and echo the **income** side back the way the
spend side always has been. The last is the substantive one — `incomePlan()` covers what comes in, where
the capital sits and how each source turns on and off, with the timeline **derived from the forecast**
rather than restating inputs so it reconciles with the ladder. Checking it against the real base surfaced
that the household has **no savings accounts at all**, so its early liquid wealth is accumulated surplus,
now said out loud. The stay-put report lost a page on net despite gaining a whole section.
**A fourth round after checkpoint `cc87f49`:** Rob found the stay-put report still disclosing *"we've
assumed 1% of its value a year"* for a home it never buys — the sale gate had not been carried to the
**assumed-figure notes**, which keyed off "was a buy price entered?" while a base carries one so Compare
can run every variant. Fixed at the root with `ResultPresenter::housingActionFor()`, now the single home
for the rule across the results page, the PDF **and `scenarios:audit`** (auditing the raw action would
otherwise demand a disclosure the reader must not see). Regression test verified to fail with the gate
removed; `scenarios:audit` clean across all 18 scenarios.
Full suite green, pint clean, assets rebuilt. Awaits browser sign-off (open a PDF and read it on paper).

_2026-07-29 (V2 benefits check + Pension Credit severe-disability-addition couple-rule fix)_ —
Rob asked which benefits the V2 couple could claim, then whether claiming Carer's Allowance or Attendance
Allowance would cut FRC's DLA, then for a full benefit check. Researched against authoritative sources (Turn2us,
Age UK, entitledto, gov.uk; verified 2026-07-29): **neither CA (underlying entitlement) nor YCC's own AA reduces
FRC's DLA** — the only interaction is the Severe Disability Premium, which *paid* CA would remove but underlying
entitlement does not, and which this one-disabled-partner couple does not get anyway. Wrote a durable benefits
check to the gitignored `docs/BENEFITS-CHECK-V2.local.md` (benefit × life-phase table; the safe
carer-underlying-entitlement action; SMI-loan at 3.66% vs the equity-release proposals; Council Tax reductions;
Pension Credit gateways). While checking the engine's Pension Credit modelling, **found and fixed a real bug**:
`PathProjector::meansTestedBenefitNominal` OR-ed a per-person disability flag into the household SDP decision, so a
couple with one disabled partner wrongly received the addition. Fixed to the couple rule (both partners must
qualify → couple rate = 2× single) and wired the carer addition via a new `Person::caresForPartner` flag.
**Measured on the private V2 base before/after** (figures in the gitignored benefits doc): a material lifetime
Pension Credit overstatement removed, entirely the both-alive years (survivor years were already SDP-free).
Engine-only; DECISIONS + DATA-MODEL + METHODOLOGY updated; full suite green (954 pass, 1 advice-mode skip).
`caresForPartner` builder-UI exposure deferred (defaults false, immaterial to V2). **Not committed:** the tree
also carries the concurrent session's uncommitted repayment-mortgage + park-home work, which shares
`PathProjector.php` and the doc files, so a clean split needs coordination (see [[concurrent-session-split]]).

_2026-07-29 (adviser-parity plan — three open correctness gaps found; no code changed)_ —
Rob asked what could be learned from a Damien Talks Money Q&A video (28 Jul 2026), then widened it to "what
else does a financial adviser provide that we should model, to obviate needing one". The video itself was
unreadable (YouTube serves a JS shell; no transcript), so Rob pasted the page metadata and **the chapter list
was used as a topic checklist only** — every claim was then verified against gov.uk / primary sources, never
against the video. Mapped ~30 retirement-relevant chapters against the code. **Most already covered** (MPAA,
emergency tax, care, sequencing risk, fiscal drag, CGT, IHT, DB-as-bonds; the triple-lock earnings leg is a
*decided* adverse divergence, not an oversight). **Three genuine gaps found — all now in DATA-MODEL "Known
divergences" as OPEN:** (1) **no investment-cost model at all** — returns are gross of platform/fund charges,
the largest silent optimism in the model and a bias in the reassuring direction; this was an open *confirm*
in the June competitive scan (Cluster E) and is now confirmed absent; (2) **no pension contribution tax
relief** — the code's own docblock flags it; (3) **no ISA subscription cap** — and the bias is largest for the
highest-surplus (sell-and-invest) plans, so it is not neutral across the plans being compared. The
adviser-services sweep found the remaining reasons to hire one are **coverage and cadence, not
sophistication** — RF already beats the adviser sector on modelling and leads on means-tested benefits.
Wrote **docs/build/PLAN-adviser-parity.md** (Part A engine correctness, Part B adviser-service parity, with
three explicit non-goals: fund/product selection, DB-transfer advice, attitude-to-risk psychometrics —
capacity for loss is kept because it is objective and already computable). **Rob resolved all four scope
questions**, which reordered the build: A3 ISA drops to generality (both partners are 65+ before the April-2027
cash-ISA cut, so it misses this household); A2 implements `net_pay` first (their actual scheme — full marginal
relief, no NI saving); A4 sacrifice drops to a "would it be worth asking your employer?" what-if; **B2
protection gap promoted to #3** (death-in-service confirmed in force — and it *ceases at retirement*, a real
cliff-edge RF is well placed to surface). Personal detail (DOB, scheme, cover) deliberately kept **out** of the
tracked plan per [[pii-leaks-into-tracked-files]] — it lives in the gitignored SCENARIO-V2 doc. **No code
changed**; no DECISIONS entry per the established convention (a DRAFT plan earns its entry when built).

_2026-07-30 (checkpoint: BTL repriced after a wrong assumption; both open figures closed out)_ —
Rob challenged the checkpoint for filing the park-home depreciation rate and the let-to-let BTL rate in
**Blockers**, when he had asked me to research both and the standing rule is research → adverse default →
expose as editable, never hand the call back. He was right; both had in fact been researched, and filing
them as questions was the error. Re-checking them to close them out **found a wrong assumption in the BTL
figure**: 6.5% rested on an inferred later-life specialist premium that **does not exist** — buy-to-let is
underwritten on **rental income (ICR), not the borrower's earnings**, so age is not the binding constraint
(BM Solutions lends to 99; several specialist lenders publish no maximum age; Shawbrook single lets from
4.84%, TML 5-yr from 4.74%). Repriced scenario 43 to **5.75%** (the market-average 5-year fix, ~1pt above
best buys), £13,520 → £11,960/yr, ICR 160% → 181%. Depreciation stays **-8%/yr**: searched for a neutral UK
index and there isn't one — the government's own park-homes research is policy analysis and publishes no
price series, so the figure is an openly-labelled judgement between a campaigning source and a marketing
one, with four sensitivities shipped. Both now recorded as decided, not open. `scenarios:audit` clean.

_2026-07-30 (hard rule: no invisible figures; the audit made permanent)_ —
Rob, on finding the £0 mortgage line: *"the model shouldn't ever be able to use a figure that the user
cannot see / interrogate in some way."* Encoded as a hard rule in CLAUDE.md and enforced. **Looked for
live violations first rather than only guarding the fixed case, and found two:** a bought home's upkeep
(1% of value a year) and moving costs (£2,000) were private engine constants moving the result with
nothing on any screen. Both now disclose via `ResultPresenter::assumedFigures()`, which **reads the
owning constant** (made public for the purpose) rather than restating it — a disclosure that drifts from
the figure in use is worse than none, so a test asserts a bigger home moves the disclosed pounds.
Turned the scratchpad sweep into **`php artisan scenarios:audit`** (7 checks, non-zero exit so it can
gate a release) plus `AuditScenariosTest`, which asserts it CATCHES each defect — a guard that always
passes manufactures confidence. **The audit earned its keep on first run against the test fixture:
`Scenario::projectFrom()` defaulted the variant COLUMN to `Rent` while the forecast defaults to
`stay_put`**, so any scenario saved without an explicit variant was labelled "Sell & rent" on every
screen while being projected as staying put. Fixed to `StayPut`. Rob's own scenarios all carry an
explicit variant so none were affected, but the trap was live. Also fixed an `array_keys` slip in the
audit that would have printed indices instead of naming broken overrides. Full suite green (993), pint
clean, `scenarios:audit` clean across all 14 real scenarios.

_2026-07-30 (the park-home option built, then a full scenario audit)_ —
Built [docs/build/PLAN-park-home.md](build/PLAN-park-home.md) after committing the spendable view
(`8f5b650`). Two optional `HousingAction` fields close both gaps; `buyGrowthOverride` accepts **negative**
rates, so `housing.buyGrowthReal` gets its own validation band (−25..25) rather than the 0-upwards `$rate`
rule. Added four scenarios (ids 51–54). **Corrected my own earlier claim to Rob:** I had written that the
cruise "only exists in plans that don't involve that mortgage" and treated the park home as settled — wrong,
since a park home is a sell-and-buy with **no mortgage at all**, the opposite category. Rob pushed back and
was right; his ~£1,300/mo instinct was if anything conservative (the freed outgoings are ~£1,750/mo: the
£1,318.54 instalment plus £682.11 of service charge and levy, less the £250 pitch fee).
**Also fixed a display defect Rob found on the live results page:** a repayment mortgage's "Mortgage" spend
line is deliberately zeroed (the schedule owns the payment), and echoing that £0 back read as "the mortgage
isn't being charged". Verified the charge is correct first (the year-on-year difference IS the instalment
once deflated — my first comparison script wrongly compared nominal to real), then made the budget panel
show the schedule's own first-full-year instalment, tag it as computed, and count it in the totals.
**Then audited all 14 scenarios** on six checks: variant column vs modelled variant, orphaned overrides,
the mortgage line vs what is charged, monthly-figure reconciliation every year, a depreciating home
actually depreciating *and* raising its note, and an unfunded purchase being charged rather than conjured.
Three initial flags were **my audit check being naive, not bugs** — an unfunded gap need not surface as
unmet spend, because the year's income and savings may legitimately cover it (that is the point of charging
it); corrected the check to assert the gap lands in the year's spend instead. **Audit clean.** Full suite
green (979), pint clean. Awaits browser sign-off.

_2026-07-30 (spendable view: available capital, monthly allowance, and a solved affordable-spend figure)_ —
Built [docs/build/PLAN-spendable-view.md](build/PLAN-spendable-view.md) after the other session's Pension
Credit fix landed (it committed my repayment-mortgage work with it — `11b67e9` is a combined commit).
Presenter-only for the read figures; **two defects surfaced by my own tests, both worth keeping in mind:**
(1) rounding the monthly allowance, essential and free independently let the parts disagree with their
total by 1p on a year with a **1p** shortfall — "free" is now the remainder, so the parts sum by
construction; (2) more seriously, the whole read-only approach is **bounded by the entered budget**, so it
can never answer "what could we afford?" — it only says whether the plan worked. That needed a solve.
Added `DiscretionarySpendLever` + `SustainableSpend` (deterministic bisection, ~20 forecasts, synchronous —
the Monte Carlo threshold explorer needs a queue worker, this does not), made **variant-aware** so a
sell-and-rent plan is searched as a renter (`deterministicForecastAt` models stay-put — the trap flagged in
this handover, which bit me twice in two days). **A third defect caught by inspecting output, not tests:**
the first solver bar (essentials met) was **degenerate** — income alone covers essentials, so the search
was insensitive to the lever and every plan returned "£500,000+/yr" for a household on ~£30k. The bar is
now "full budget funded every year"; recorded in code so it is not reintroduced. Also flagged (not fixed)
in DATA-MODEL: `usableWealth` counts pre-tax pension as cash, which additionally makes the safety-buffer
warning fire late. Full suite green (967), pint clean. **Awaits browser sign-off** (visible UI on four
surfaces).

_2026-07-29 (real repayment mortgages; the V2 Stay-put base moved onto the LiveMore quote)_ —
Rob supplied a real indicative quote (LiveMore Capital ESIS, 29 July 2026, via broker When The Bank Says No):
**£160,000 over 16 years, capital & interest**, 6.23% fixed for 60 months then 7.24% SVR. The engine could not
represent it — a repayment mortgage's balance was **static**, and its payment was an ordinary expense line, so
the model inflated a contractually fixed instalment with CPI, shrank it by the survivor factor on a death,
never stopped it at the term end, and understated wealth/estate by all capital repaid. Built the real thing
(accuracy-first, not an approximation): `RepaymentMortgageTerms` + `MortgageRatePeriod` DTOs and an
`AmortisationSchedule` (monthly, nominal-rate/12, instalment recomputed at each rate tier, final payment trued
up to land on zero), with the schedule owning **both** the balance and the payment and dropping the "Mortgage"
expense line so they cannot double-count. The loan amount stays in one home (`outstandingMortgage`); amortising
and rolling up are mutually exclusive (the DTO **throws**). **Pinned to the lender's own table as a worked
example** — every quoted balance within **21p over 16 years**, both instalments (£1,318.54 / £1,384.65) exact,
total interest within 11p. Wired through assembler + builder (6 new fields, all defaulting empty so no child
delta shifts) + a results-page modelling note. Then moved base 9 onto the quote, per Rob's call to let children
inherit. **The mutual-exclusion throw earned its keep:** testing every child against the proposed base *before*
writing found **4 that broke outright** (27, 31, 38, 39 — lifetime mortgages) and **3 that would have changed
silently** (17, 32 let-to-let; 28), so those seven carry an explicit blank-term override. Also caught myself
measuring the sell/rent children with `deterministic()`, which ignores the housing variant — the trap already
flagged in this handover; re-measured via `deterministicVariants()`, after which they correctly read as
unchanged. **Verdict on the quote: it does not work** — the survivor carries £16,616/yr on ~£11.7k/yr, so the
plan runs short in **2036** (was 2043) and stays ~£15k/yr short to 2042, though terminal wealth is **+£74,830**
because the debt is actually repaid; and it needs ~£49,495 up front against ~£42k realistically available.
Full suite green, pint clean. Awaits browser sign-off.

_2026-07-29 (V2 what-if family cleared and rebuilt around the three keep-the-flat options)_ —
Rob: "clean up the scenarios and restart, based on the 3 easily identifiable options." Backed up all 24
scenarios to the gitignored `docs/scenario-backup-2026-07-29.local.json` (added `/docs/*.local.json` to
.gitignore first — only `*.local.md` was covered, so a raw export would have been committed with real
financial data), deleted the 23 children, and rebuilt 9 via the same path the app uses
(`QuickWhatIfController`: empty `builder_state`, sparse `overrides`, `projectFrom()` for the structural
columns — two NOT-NULL columns caught a hand-rolled first attempt). The set: lifetime mortgage / let-to-let /
YCC working +2, +4, +5 years / the £80k art-and-jewellery sale modelled BOTH ways (funding the remortgage gap
vs additional, Rob's call) / and — Rob's call against the original three-option brief — sell-and-buy-cheaper
and sell-and-rent kept as comparators, which proved right: they are the only plans that work. Verified
completeness rather than assuming: rental income reaches the forecast as taxable, both £80k receipts land, 47's
spend is **exactly** £49,495 above 48's, and the salary really extends to 2029/2031/2032. **Findings: only the
lifetime mortgage (estate consumed to £63,587) and sell-and-buy-cheaper (£303,506) never run short, and no
lever rescues the LiveMore mortgage** — +5 working years moves the shortfall 2036 → 2042, the £80k sale buys
1–4 years. Two judgement calls flagged in the V2 doc: the **let-to-let BTL rate had no quote behind it** (set
at 6.5% here on a later-life premium — **since disproved and repriced to 5.75%**, DECISIONS 2026-07-30),
and a **£160k lifetime mortgage is likely unavailable** (max release is governed by the younger
borrower — the adviser's own quote capped at £144k). Also flagged: **CGT on chattels is not modelled**, so both
£80k children are optimistic by whatever tax the paintings and jewellery attract. No code changed.

_2026-07-19 (doc-structure migration to the canonical layout)_ —
Ran the Project-Doc-Standard migration (triggered by `/handover save`). The four anchors moved from the repo
root into `docs/`; `METHODOLOGY`/`ASSUMPTIONS`/`MORTALITY`/`A11Y` → `docs/spec/`; `PLAN` + `PLAN-*` →
`docs/build/`; `RESEARCH-*` → `docs/research/` (all `git mv`, history preserved). `HANDOVER-ARCHIVE.md` and the
gitignored `*.local.md` / `*.xlsx` stayed at `docs/` root. Rewrote every relative cross-reference (doc-to-doc
and links to source files, at both new depths) plus the code/config that names a doc path — root `CLAUDE.md`,
`HandoverHygieneTest`, `MethodologyController` + `MethodologyPageTest`, and `config/assistant.php`
`methodology_docs`. Verified with a link checker over all relative links in every tracked `*.md` (0 broken) and
the full suite green. Committed on its own (`docs(structure): …`), separate from the charts work. **Note:** stale
path *prose* in the historical docs (DECISIONS/PLAN/RESEARCH bodies) was left as-is — the clickable links all
resolve; only this living HANDOVER's prose was updated (rewriting append-only logs is worse than the minor
staleness). The SessionStart orient hook already discovers anchors at `docs/` root, so it is unaffected.

_2026-07-19 (C1/C2/C3: the three hero time-series charts)_ —
Resumed and picked up build-order #3 of docs/build/PLAN-output-inflation-and-charts.md (A1/A2 done). The review found
nearly every chart a user wants was already computed per year on `YearResult` and thrown at a table — only the
Monte-Carlo wealth fan was drawn. Added a "Money over time" section before the cashflow ladder with three
stacked-area charts: **C1** income staircase (every income source over time), **C2** wealth composition
(pensions / savings & investments / home equity, summing to net worth), **C3** costs (essential vs
discretionary — the spending smile). Presenter + Blade only (`ResultPresenter::timeSeriesCharts` +
`partials/time-series-charts.blade.php`), no engine change. Built from the SAME `ForecastResult->years` the
ladder reads, so a chart can't drift from the table; each ships its `<details>` table twin reconciling to the
ladder cell-for-cell (`TimeSeriesChartsTest`: income cols → total, wealth legs → net worth, ess+disc → spend,
all cross-checked vs the ladder; non-negative stacked pounds; the >8-source fold keeps every source in the
table). **Caught in the test:** the income "total" is the sum of the *sources* (the stack height — includes
tax-free cash, savings drawn, one-off receipts), NOT `grossIncome` (taxable only), which reconciled to a
different, smaller figure. Categorical colour from the validated dataviz reference palette (CVD-checked on the
app's white surface); C1 caps at the 8 palette hues, folding any extra income sources into a neutral "Other"
band on the chart only (the table keeps them all — completeness). Milestone verticals overlaid on all three.
**Real-terms only; the nominal toggle is deferred** (needs the engine's pre-deflation figures exposed to avoid
a presenter re-inflation drifting from the projector). Full suite green, pint clean, assets build. **Awaits
browser sign-off** (visible UI). Next by build order: B1 verdict-first landing.

_2026-07-18 (A2: care in the deterministic path as an "if care is needed" stress)_ —
Continued the output/inflation plan (build-order #2). Care was Monte-Carlo-only, so the deterministic
Affordability verdict read a care-free path — "lasts for life? Yes" computed without the household's biggest
late-life expense, falsely reassuring for exactly the least-numerate reader the screen is built for. Per the
plan's resolved design (and the FCA / pro-cashflow-tool convention), modelled care as a **stress shown beside a
labelled care-free base, never expected-value-averaged** (averaging a right-skewed tail understates the person
in the tail). Engine: `DeterministicPathDraws` gained optional injected `CareEpisode`s (empty = byte-identical
care-free path — the care-free central estimate is untouched); new `CareStressScenario` (one 4-year nursing
spell @ £1,800/wk on the last-surviving partner — the adverse means-test position, and discriminating where a
both-partners worst case would sink every plan) + `DeterministicForecaster::forecastWithCareStress`; reuses the
A1-escalated, means-tested projector care leg. App: `ScenarioForecaster::deterministicCareStressVariants` mirrors
`deterministicVariants` on the same variant inputs; `AffordabilityAssessment` adds a care-stress verdict per card
+ a bottom-line care caveat (tier/ordering stay the care-free expected path — a strong plan still ranks strong);
the Affordability view renders a 🏥 care line on every card. Guarded (`DeterministicCareStressTest`: reaches the
result, tips a marginal single-person household, care-free carries no care; `AffordabilityTest`: every card
carries the verdict + the caveat). Design calls (single spell / last survivor / £1,800×4yr) are the adverse
defaults per [[adverse-default-user-editable]], flagged user-editable (a params UI is a later refinement).
**Awaits browser sign-off** (visible UI). Full suite green, pint clean. Next: C1–C3 hero charts.

_2026-07-18 (A1: care fees escalate above CPI — first slice of the output/inflation plan)_ —
Resumed and picked up docs/build/PLAN-output-inflation-and-charts.md build-order #1 (the highest-value item by the
accuracy-first rule now the plan's open questions are resolved). The engine drew one CPI series and modelled care
as a flat-real cost, so care — the fastest-inflating major UK retirement category (self-funder fees ran ~10%/yr to
Dec-2025) — rode flat CPI and understated the tool's headline late-life risk. Copied the proven, null-safe
`propertyCostsRealGrowth` mechanism exactly: new `AssumptionSet::careCostRealGrowth` (`?Percent`), threaded through
the `PathDraws` interface (all three drivers) and escalated in the `PathProjector` care leg by `(1+g)^yearIndex`
before the means test. Shipped default **CPI + 2% real** across all presets (sourced most-adverse standing value —
care is NLW-pinned staff cost, PSSRU/OBR escalate care unit costs on earnings ~2% real; the ~10%/yr run-rate is an
NLW+NI spike, not standing), exposed as the **7th user-editable economic assumption** (`careCostGrowth`) per the
adverse-default-user-editable rule. Null-safe throughout: null keeps care flat-real, the mapper hydrates a pre-A1
snapshot to null, so every stored care run reproduces byte-identically; new runs carry 2%. Guarded by
`CareCostInflationTest` (compounds by the expected factor; null/zero byte-identical) + `MappingRoundTripTest`
(reaches storage; pre-A1 → null). Fixed a stale METHODOLOGY caveat in passing (care probability is split by sex,
contradicting an adjacent "no split by sex or age" line). Full suite green (913 + 1 advice-mode skip; +2 tests),
pint clean. **A2 (care in the deterministic path) is the next build-order item** — care is still MC-only, so the
Affordability verdict still reads a care-free path.

_2026-07-18 (adversarial output/inflation/charts review → a DRAFT plan)_ —
Rob asked for an adversarial review of the project + docs (wrong assumptions; data gathered-but-unused; how
to make the information-heavy output more legible + how other forecasters do it; what charts to add; how
inflation changes costings). Ran two code-grounded mapping passes (the Blade/ApexCharts output layer; the
engine's `YearResult`/`SimulationResult` fields vs what any view reads) plus web research for every figure.
**Findings:** (1) correctness — the engine draws one CPI and models everything else as a real spread, so
**care fees ride flat CPI** (understating the tool's headline risk; real self-funder fees ran ~10%/yr to
Dec-2025), care is **absent from every deterministic surface** the Affordability screen reads, MC returns are
**Gaussian** (left tail optimistic ~10–17pts per the literature), and the triple lock **drops the earnings
leg**; (2) the results page is ~1,170 lines / 18 sections front-loading up to four banners before the first
number, every chart doubled by an inline table; (3) **only one time-series chart exists** though
`incomeBySource` (11 sources/yr), the spend split, wealth legs, tax and mortgage balance are all on
`YearResult` — six more charts are a presenter/Blade job, not an engine change. Wrote
**docs/build/PLAN-output-inflation-and-charts.md** (Part A correctness / Part B legibility / Part C charts; each
decision carries reasoning + a research link; six open questions flagged for Rob; build order + files-to-touch
map). Noted the concurrent session's `21e0efe feat(care): sex-differentiated care probability` had just landed
(distinct from this plan's care *inflation* items); re-checked git before editing per [[concurrent-session-split]].
**Then resolved all six of the plan's open modelling questions** via a three-way parallel research pass (care;
return distribution; triple lock + probability wording) — Rob delegated the calls ("not my field", saved as
[[adverse-default-user-editable]]): research the industry figure, default to the **most adverse** where several
are defensible, expose each as a user-editable UI control. Sourced defaults landed in the plan (care inflation
CPI+2%; care-stress variant ON not expected-value-averaged, adverse fees/duration from LaingBuisson/PSSRU;
Student-t d.o.f. 3 + negative skew + a stagflation inflation↔return coupling; State Pension CPI-only — the one
place adverse departs from current law, flagged; probability word-bands reserving "on track" for ≥80%). **No
code changed** — plan + handover/sibling-doc index only.

_2026-07-18 (sex-differentiated late-life care probability in the Monte Carlo)_ —
Picked up What's next #3. Chose the care sex-split over CGT deemed-occupation absences (near-moot for the V2 couple's
continuously occupied main home — PRR already relieves the whole gain) and the annuitisation month override (narrow).
The care sampler drew one flat 0.25 for everyone though `Person::sex` was already carried to the `Simulator` and
dropped at the sampler boundary — a collected-but-under-consumed use of sex, and a real understatement of a woman's
care tail (a headline "does the money last" driver). Researched sourced figures (women's lifetime care-home use
consistently ~1.5:1 vs men — NHS HSE 2021 28/24 ADL, US NEJM 38/21 nursing-home, HHS ASPE 55/38 paid LTSS); set male
0.20 / female 0.30 calibrated to preserve the Dilnot/PSSRU ~1 in 4 mean at an even split (so a mixed couple's
aggregate risk is essentially unchanged, no unexplained drift). Threaded `sex` into `CareCostSampler`; it's a
threshold swap, not an extra draw, so seeded runs reproduce byte-identically and care being opt-in means no
default/non-care run changes. Guarded (`CareCostSamplerTest`: asymmetry + the split reaches incidence over 2,000
same-seed draws). Fixed two adjacent stale honesty lines in docs/spec/METHODOLOGY.md while there: the care "no sex/age
split" caveat, and the "house/salary growth have no volatility" MC caveat (both were made stochastic earlier today).
Full suite green (911 + 1 expected advice-mode skip), pint clean.

_2026-07-18 ("hide non-viable plans" toggle on Compare)_ —
Resumed to find a complete, green, uncommitted feature in the tree (the handover said "nothing mid-edit"): a
Compare-screen checkbox that hides plans whose usable-wealth line falls below £0 (deterministic depletion) from the
table, burndown and MC cards. Confirmed it coherent + green (`ScenarioCompareTest` 16/16), Rob confirmed it was this
work to land, so committed it: pint clean, added the DECISIONS entry + handover Done bullet. "Non-viable" is defined
off the deterministic `depletionCalendarYear` (same as the "Money lasts: No" column), not an MC probability; pure
presentation, no shape change; "Re-run all" still queues every plan; the burndown is `wire:key`ed so the ignored
chart re-inits with the filtered series. Toggle shows only when a non-viable plan exists.

_2026-07-18 (stochastic salary growth in the Monte Carlo — the last deterministic growth line)_ —
Picked up from What's next #3 (Rob chose it over the public-release blockers and sign-off prep). With house growth
made stochastic earlier the same day, salary growth was the only remaining deterministic straight line in the MC, so
a still-working couple's accumulation looked artificially certain. Mirrored the house pattern exactly: `AssumptionSet`
gains nullable `salaryGrowthVolatility` + `salaryEquityCorrelation` (0.1, deliberately LOWER than housing's 0.2 —
researched: aggregate real wage growth is near-acyclical, ~0.51× GDP-growth volatility per SF Fed WP 2011-23, ~2% in
the UK ONS record); `ReturnModel` draws a per-year salary shock correlated to the equity shock, **drawn last** so a
null-salary set consumes no extra draw and every stored run reproduces byte-identically; `SampledPathDraws` reads the
sampled path; mapper round-trips the pair with null back-compat; the assumptions panel gains a salary-volatility
"show-your-working" row. **Corrected an over-claim mid-build:** "drawn last" does NOT keep later years' house/asset
streams identical when salary vol is on (each extra draw advances the shared RNG for subsequent years) — the real,
narrower guarantee is that a *null*-salary set draws nothing extra; fixed the docblock and the test to assert exactly
that. Sourced figures + judgement note in docs/spec/ASSUMPTIONS.md; DECISIONS 2026-07-18 supersedes the house entry's
"salary stays deterministic". Full suite green (908), pint clean; sanity magnitudes verified via a scratchpad script
(not committed): shipped-2% widens the p10–p90 spread £104k → £115k, median unchanged.

_2026-07-18 (stochastic house-price growth in the Monte Carlo)_ —
Highest-value accuracy refinement from What's next #3: house growth was a deterministic straight line in the MC, so
the home carried no risk and stay-put/buy plans looked artificially certain vs sell-and-rent (whose invested proceeds
were already stochastic) — wrong for a tool whose whole point is a housing decision under uncertainty. Researched
sourced figures (real house vol ~9%, low ~0.2 house–equity correlation, per JST "Rate of Return on Everything"
NBER w24112 — housing far less volatile than equities, low equity–housing covariance / diversification gains).
Implemented: `AssumptionSet` gains `houseGrowthVolatility` (`?Percent`) + `houseEquityCorrelation` (float);
`ReturnModel` draws a per-year house shock correlated to the equity shock (single scalar correlation, not a 4th
matrix row — keeps the asset-class matrix contract); `SampledPathDraws` reads the sampled house path; presets +
mapper + assumptions panel updated. **Key design for safety: nullable vol = opt-in** — a null-vol set draws no house
shock, so the deterministic projection, all existing sets/tests and every stored run are byte-identical (no DB
migration, MC reproducibility preserved). Completeness-tested (`StochasticHouseGrowthTest`): a £500k-home couple's
terminal spread widens p10–p90 £169k → £759k (median ~unchanged), collapses to the mean at zero vol, reproduces under
a seed. Salary growth in the MC left deterministic (narrower, lower value). Full suite green, pint clean. Sanity-run
magnitudes verified via a scratchpad script (not committed).

_2026-07-18 (PDF sale-funding waterfall — last PDF gap closed)_ —
The buy-funding waterfall (net proceeds → savings → mortgage → unfunded gap) rendered on results/Compare/assistant
but the PDF summary omitted the sale explainer entirely. Fixed by mirroring the screen, not re-deriving: added the
same `ResultPresenter::saleExplainer(...)` call to `ScenarioPdfController::data()` (fed by the scenario's own
`housingComparison`/`assumptions`/`allocation`, deterministic, null when no sale is configured) and an "If you sell"
section to `pdf/partials/report.blade.php`, placed just before the cashflow ladder to match the on-screen money-flow
order. Used only the inline `@php(...)` form (block/inline mixing is the known Blade raw-block gotcha); added an `h3`
rule to the PDF stylesheet. Guarded with a `ScenarioPdfTest` assertion (the rich fixture sells & buys). Full suite
green, pint clean; the `%PDF` download test confirms DomPDF renders the new markup. No shape change, no DECISIONS
entry (mechanical parity with the screen under the existing displayed-figure-provenance rule).

_2026-07-17 ("What you can afford" screen + affordability limit-tests)_ —
Rob: the current tool is good for him but hard to communicate to the elder couple — "they just want a this-is-what-
I-can/should-do". Built a plain-English `/scenarios/{base}/afford` screen (`Affordability` Livewire + pure
`AffordabilityAssessment` presenter + view; linked from dashboard/Compare/Results): base + every what-if reduced to
one yes/no (do the essentials last for life?), working plans first, failing ones collapsed with the year each runs
short, a factual bottom line naming the strongest plan (directive "lean towards" gated behind `interpret`). Verdict
is the fast deterministic projection (covers new what-ifs with no stored run); stored Monte-Carlo "how sure" shown
beside it — an important honesty gap here (several plans "work on the expected path" yet are only ~55–62% in MC; the
base stay-put is 28%). One-click **Check how sure** queues the full runs via `SimulationRunner` and hands off to
Compare's progress UI. Pure presentation, no shape change; suite green, pint clean. **Caught two bugs in build:**
`deterministic()` ignores the housing variant (must use `deterministicVariants()[$variant]` for a sell/rent plan —
my first probe mis-modelled the sells), and an inverted sort comparator briefly put the weakest plan as the bottom
line. **Answered Rob's limit test:** sell-and-rent at £2,000/mo fails at any realistic sale price (out 2037 on a
£290k sale, 2040 even on £350k); affordable rent ceiling on a £290k sale ~£1,000/mo (tight); sell-and-buy cheaper
£165k is the strongest (survives the £290k price with ~£82k left; £135k / 87% MC on the £350k base). Added 5
limit-test what-ifs to the app (DB scenarios 33–37, not committed); noted them in the gitignored SCENARIO-V2 doc.

_2026-07-16 (no magic money: purchase-funding waterfall + documented capital receipts)_ —
Rob: scenarios that buy a home "seem to magic up the money required — show it accurately, without money
appearing without a documented income source (e.g. sale residual, income from work/kids)". Root cause: a
cash-only buy above the proceeds floored the surplus at £0 and still granted the home at full price (phantom
equity, flagged in the UI but silently modelled); savings were never drawn; no input existed for money arriving
from outside the plan. Built (all per DECISIONS 2026-07-16, Rob's three design calls made explicitly): the
savings-first funding waterfall with the loud unfunded-gap year-0 failure; real year-0 CGT on the GIA slice a
purchase draw sells (one shared AEA, exact-pence tests via the engine's own primitives); the `CapitalReceipt`
input end-to-end (engine → projector → builder step 3 → ladder → assistant). Fixed in passing: `withHousing`
dropped `relationshipStatus` (cohabiting buy/rent variants silently reverted to married IHT). The Compare
burndown now shows an unfunded mansion plunging £-millions negative — verified as the honest net-position line
(usable minus cumulative unmet), not a bug. PDF surfaces untouched (uncommitted work from a concurrent session
in those files — stage selectively). Suite green throughout; pint clean.

_2026-07-12 (private family sharing via Tailscale Serve — Hostinger rejected)_ —
Rob wanted family to view the current real scenarios **as-is** (not a public launch). Rejected the offered
Hostinger box: its SSH is shared hosting (port 65002), which is MySQL-only (no Postgres), cannot run a
persistent `queue:work` daemon or the Ollama assistant, and would force a DB migration + re-entering the
encrypted data on a third party + crossing the public-compliance line — all cost, no fit. Chose **Tailscale
Serve**: the app stays exactly as-is on this machine, reachable only inside the private tailnet, data never
leaves the box. Wired it up and verified end-to-end (the tailnet URL returns 200 with a valid auto cert):
serve Host-agnostically on `php artisan serve --port=8000` (Herd/Valet routes by Host and will not match the
`*.ts.net` name) + `tailscale serve --bg 8000`; new `APP_EXTERNAL_URL` pins absolute URLs/redirects to the
https origin so logins do not bounce to an unreachable host; `bootstrap/app.php` trusts the loopback proxy
for the forwarded scheme (not Host); `APP_DEBUG=false` while shared. Family log in with Rob's credentials
(scenarios are per-user; no read-only share built). See DECISIONS 2026-07-12 + How to pick up.

_2026-07-10 (queued-Monte-Carlo reproducibility independently re-verified; a stale worker found)_ —
Re-ran and re-verified the run-to-run-variance trust concern on Postgres. Built an independent test: an
in-process reference for all 18 scenarios (3 variants, 10k paths — the proven-deterministic path), then
dispatched the whole family **twice** through the real `queue:work` daemon (batch 1 = single worker;
batch 2 = **two concurrent workers**, deliberately heavier `jobs`-table contention than the original bug),
comparing each stored result to the reference on both the success probabilities and a full-payload md5.
**108 queued variant-results across 36 runs, every one byte-identical (max gap 0.0000 points, 0 hash
mismatches).** Confirms the SQLite→Postgres fix — in-app "Re-run all" is trustworthy. **Found a stale
`queue:work` daemon (started 2026-07-07, before the migration)** still polling the old SQLite `jobs`
table — it processed none of my Postgres jobs; a fresh worker drained them. `queue:work` caches its DB
connection at boot, so restart every worker after the DB change (DECISIONS 2026-07-10). Residue: the 36
verification runs (ids 437–472, seed 424242) are now each family scenario's "latest completed run" and
polluted `result_snapshots`; deleting them was safety-blocked (Rob's pre-existing data), so Rob's next
in-app "Re-run all" (after restarting the worker) supersedes them at canonical seeds. Verification scripts
stayed in the session scratchpad, not the repo. **No code changed.**

_2026-07-09 (queued-Monte-Carlo reproducibility bug RESOLVED via Postgres; BTL finance-cost reducer)_ —
A family figure swung more than 10k-path sampling noise allows. A long investigation proved the engine is
deterministic (same seed → identical across 6 processes, JIT and no-JIT) and every non-queue path
reproduces exactly; only runs through the `queue:work` daemon were intermittently wrong (up to 14pts).
Root cause: SQLite could not handle the `database` queue driver's concurrent access. Rob's fix: moved the
app DB to local Postgres 18 (data copied across, encrypted V2 family intact); the family re-run through the
identical queued path now reproduces exactly. Also this session: the let plan (#17) given the April-2020
BTL finance-cost tax reducer with rental at £1,800/mo (depletion 2030 → 2035, still fails). See DECISIONS
2026-07-09.

_Older sessions folded into [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md)._
