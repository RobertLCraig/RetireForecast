# PLAN — adviser parity: contribution/wrapper correctness + the services that keep people paying an adviser

> **Status: PART-BUILT.** **A1 (fee drag), A2 (net-pay contribution relief) and B2 (protection gap)
> shipped 2026-07-31** — see DECISIONS
> 2026-07-31 and DATA-MODEL "Known divergences"; the figures below were re-verified against primary
> sources at build time and the shipped default is **0.50%**, with the reasoning for not taking the
> most adverse figure recorded in docs/spec/ASSUMPTIONS.md §10. Everything else here is still spec.
> Dated 2026-07-28. Two provenances:
> (a) a chapter-map of Damien Talks Money, *"Answering All of Your Investing Questions"* (28 Jul 2026)
> checked line-by-line against the code — the video was used as a **topic checklist only** (chapter titles,
> no transcript); every claim below was verified independently against gov.uk / primary sources, never
> against the video; (b) a first-principles sweep of what a UK IFA actually *delivers*, asking which of
> those services this tool could supply.
>
> Complements [RESEARCH-competitive-gap-analysis.md](../research/RESEARCH-competitive-gap-analysis.md),
> which compares RF to competing **tools**. This note is about **services** — the reasons a person still
> books an adviser after using a good tool. Honour the usual bar (CLAUDE.md): every figure lands in
> `docs/spec/ASSUMPTIONS.md` or a `TaxYear` record with `source` + `verified_on`, never as a magic number;
> build in green, committed slices. Figures marked ⚠️ are search-surfaced and **must be re-verified against
> the primary source at build time**.

---

## Why / motivating findings

Two independent findings, one theme — **the engine is excellent at decumulation and silent about the
money going in, and about what the money costs to hold.**

1. **Every rule governing contributions is missing.** Tax relief, salary sacrifice, ISA subscription
   limits, and the earnings cap on relief are all absent. The engine takes contributions from *net*
   surplus and drops them straight into a pot or wrapper with no cap and no relief
   ([PathProjector.php:1866-1912](../../packages/finance-engine/src/Forecast/PathProjector.php#L1866-L1912)).
   For a household with one still-working partner — which is precisely the V2 shape — this biases the
   accumulation leg in **both** directions and neither is small.
2. **Investment cost is not modelled at all.** A codebase-wide search for an ongoing charges figure,
   platform fee or fee drag returns nothing in the engine. Returns are **gross**. This was flagged as
   an open confirm in the competitive scan (Cluster E) and is now confirmed: it is a genuine
   correctness gap, and it is the single largest silent optimism in the model over a 30-year horizon.

The adviser-parity half (Part B) mostly *reuses* engine machinery RF already has. The correctness half
(Part A) takes priority under the accuracy-over-less-work rule.

---

## Part A — contribution & wrapper correctness (engine)

### A1. Investment costs / fee drag (highest priority) — ✅ BUILT 2026-07-31

**Built as specced, with two deliberate departures.** (a) The charge is a single `AssumptionSet` figure;
the per-`Account` / per-`DcPension` overrides are **not** built (the gap was the charge existing at all).
(b) The default is **0.50%** but chosen as the *adverse side of the central case*, not the most adverse
plausible: the charge falls on invested wealth and not on housing, so an over-adverse figure biases the
sell-vs-stay comparison rather than adding a safe margin. Cash deposits bear no charge. The pounds it
costs are reported per year on `YearResult` and totalled beneath the ladder, with growth left gross, so
the charge is visible rather than folded into a smaller growth line. Not put on the C3 costs chart after
all: that chart reconciles cell-for-cell with the ladder's spend, and a charge is a return drag, not
household spend. See DECISIONS 2026-07-31.

**Finding.** No charge model exists anywhere. `AssetClassAssumption` returns are applied gross; there is
no per-pot or per-account ongoing charges figure. Every projection, deterministic and Monte Carlo, assumes
the household holds its portfolio for free.

**Why it's wrong.** Cost is the most reliably predictable drag in the whole model — more certain than any
return assumption, and it compounds against the household every single year. At a 1.0%/yr total charge over
a 30-year horizon roughly a quarter of the terminal pot is consumed; the effect on a *depletion year* — RF's
headline output — is direct and monotonic. A tool that reports "your money lasts to 2058" on gross returns
is overstating the answer by a knowable amount, in the reassuring direction. This is the same class of
defect as care riding flat CPI (A1 of the output/inflation plan): a systematic bias toward comfort.

**Target shape.** A real (post-inflation is already the engine's convention) annual charge deducted from
each investable balance before growth is applied:
- `Account::ongoingChargePercent` (`?Percent`) and `DcPension::ongoingChargePercent` (`?Percent`) —
  **null = no charge, byte-identical to today**, following the proven null-safe opt-in pattern used by
  `houseGrowthVolatility` / `careCostRealGrowth`. No DB migration; every stored run reproduces.
- Plus an `AssumptionSet::defaultOngoingCharge` used when a pot/account carries none, so a user who
  doesn't know their platform fee still gets a non-zero, honest default rather than free money.
- Applied in the projector's growth step and in `SampledPathDraws`, so deterministic and MC agree.
- Surfaced in the assumptions "show your working" panel and as a line on the costs chart (C3).

**Default (most adverse of the plausible, user-editable per [[adverse-default-user-editable]]).**
DIY index investor: platform ~0.25% + fund OCF ~0.15% ⚠️ → **default 0.50%/yr** (rounded to the adverse
side of the 0.40% central case). The advised comparator is Part B's B1. Re-verify platform tiers at build
time — several changed in 2026, including Vanguard's.

### A2. Pension contribution tax relief — ✅ BUILT 2026-07-31 (net pay only)

**Built:** `DcPension::$reliefMethod`, net pay implemented by subtracting the contribution from gross
earnings before the engine's single income-tax pass (so no parallel relief calculation exists to drift,
and NI is correctly unaffected); capped at pay, so it ends with the salary. `ReliefAtSource` **throws**
rather than accepting the input and giving no relief. Two structural defects fixed alongside: the
employer's contribution is no longer paid out of household surplus (and can no longer be silently
dropped in a year with none), and contributions no longer continue for ever after retirement.
**Not built:** the £3,600 non-earner route, and the annual-allowance / MPAA cap on relievable
contributions — `AnnualAllowanceCalculator` exists but is not yet wired to the contribution step.
**Note:** no V2 scenario records any DC contribution, so this changes nothing for the real household
until that input is checked. See DECISIONS 2026-07-31.

**Finding.** Contributions are taken from net surplus with **no relief added back**. The code says so
itself: *"pension contributions are taken from net surplus and tax relief on them is not modelled, which
slightly understates the pre-retirement pot — flagged for the trust pass"*
([PathProjector.php:1857-1861](../../packages/finance-engine/src/Forecast/PathProjector.php#L1857-L1861)).

**Why it's wrong.** Relief is the entire reason a pension beats an ISA, and the engine currently models the
cost of a pension contribution without its principal benefit. "Slightly understates" undersells it: for a
basic-rate contributor the pot grows 25% faster per pound of net cost than modelled (£80 net buys £100
gross); for a higher-rate contributor, more. Any comparison the tool offers between pension and ISA saving
is currently rigged against the pension.

**Target shape.** Model the relief method explicitly rather than assuming one — they differ in what they
relieve:
- `relief_at_source` — contribution paid net, provider adds 20%; higher/additional-rate relief recovered
  through self-assessment (a **cashflow-timing** effect: the extra relief lands the following year, and
  RF's annual grain can credit it to the next year honestly).
- `net_pay` — taken from gross pay, full marginal relief immediate, **no NI saving**.
- `salary_sacrifice` — see A4.
- Relief is capped at **100% of relevant UK earnings**, or **£3,600 gross (£2,880 net) for a non-earner**,
  and by the annual allowance. **Reuse the existing `AnnualAllowanceCalculator` + MPAA machinery** — MPAA
  is already modelled (`FlexibleWithdrawalAssessor`), and once the retired partner has flexibly accessed a
  pot their allowance is £10,000, which must bind here. The non-earner £3,600 route is a real, commonly
  missed planning move for a retired spouse under 75 and falls out of this work for free.

**Interaction to get right.** Relief must be computed against the *same* income-tax pass the engine already
runs, not a parallel approximation — otherwise the two can drift, which the data-layer integrity rule
forbids. Extending contributions to be deducted before the tax computation (net pay / sacrifice) versus
grossed-up after it (relief at source) is the clean way to keep one definition.

### A3. ISA subscription limits and the April 2027 regime

**Finding.** `applyContributions` routes `ongoingContributions` into a cash/GIA/ISA bucket by account type
with **no allowance check of any kind**
([PathProjector.php:1894-1909](../../packages/finance-engine/src/Forecast/PathProjector.php#L1894-L1909)).
A household can shelter unlimited surplus in an ISA, tax-free, forever.

**Why it's wrong.** It lets the model shelter income from tax faster than the law permits, understating
lifetime tax and overstating terminal wealth — and it does so *most* for the households with the largest
surpluses, i.e. exactly the sell-and-invest housing variants the tool exists to compare. The bias is not
neutral across the plans being compared, which is worse than a uniform error.

**The rules to model** (all verified, all inside the engine's April-2031 tax-year horizon):
- Overall ISA allowance **£20,000/yr** (per person).
- From **6 April 2027**: cash ISA allowance cut **£20,000 → £12,000 for under-65s**; **savers aged 65+
  retain the full £20,000**. The overall £20,000 is unchanged — the balance must go to stocks & shares.
- From **6 April 2027**: a **22% charge on interest earned on cash held inside a stocks & shares ISA**
  (the anti-circumvention rule), and under-65s may not transfer S&S ISA → cash ISA (the reverse is
  allowed); the restriction lifts in the tax year the saver turns 65.
- Sources: [GOV.UK — ISA reform 2027 anti-circumvention factsheet](https://www.gov.uk/government/publications/fiscal-events-2026-factsheets/isa-reform-2027-anti-circumvention-rules-factsheet),
  [Practical Law — cash limit reduced to £12,000 for under-65s from April 2027](https://uk.practicallaw.thomsonreuters.com/w-048-6635),
  [Which?](https://www.which.co.uk/news/article/cash-isa-annual-allowance-slashed-what-you-need-to-know-aMLdQ9K6x9eX).

**Target shape.** Add `IsaParameters` to the per-tax-year registry (the established pattern — sourced,
`verified_on`, versioned), age-conditional on the saver's derived age. Cap contributions in
`applyContributions`; **the overflow must go somewhere visible** — spill to GIA with a `Warning`, never
silently discard, per the no-silent-failure and completeness rules.

**Scope — resolved 2026-07-28.** Both partners are 65+ before 6 April 2027, so the cash-ISA cut and the
under-65 transfer restriction **do not apply to this household**. A3 is therefore a generality/public-release
item, not an accuracy fix. Still binding and still worth building: the **overall £20,000 cap** (today
unenforced — the model shelters without limit) and the **22% charge on S&S-ISA cash**. See "Decisions
resolved".

### A4. Salary sacrifice (and the April 2029 NI cap)

**Finding.** Not modelled anywhere — zero occurrences in the codebase.

**Why it matters.** Sacrifice is the default mechanism in most modern workplace schemes. It relieves
**employee and employer NI** as well as income tax, so the same gross cost buys a materially bigger pot
than any other method — and employers commonly pass some of their NI saving into the pot too. Without it,
a still-working partner's accumulation is understated even after A2 lands.

**The dated cliff.** From **6 April 2029**, only the first **£2,000/yr** of salary-sacrificed pension
contributions remains NI-exempt; the excess attracts both employee and employer NICs. Announced at Autumn
Budget 2025; the National Insurance Contributions (Employer Pensions Contributions) Bill was introduced
4 December 2025. This lands **inside** the engine's tax-year horizon, so it is a `TaxYear`-parameterised
change, not a constant.
Sources: [ICAEW](https://www.icaew.com/insights/tax-news/2025/nov-2025/budget-nic-saving-on-salary-sacrifice-pension-contributions-capped),
[Commons Library CBP-10423](https://commonslibrary.parliament.uk/research-briefings/cbp-10423/),
[IFS assessment](https://ifs.org.uk/publications/assessing-governments-reform-national-insurance-treatment-salary-sacrifice-pension).

**Target shape.** Falls out of A2's relief-method enum: `salary_sacrifice` reduces gross pay before both
income tax and NI, with the NI-exempt portion capped per the tax-year record from 2029/30. The engine
already computes NI in the same pass, so this is a band adjustment, not a new calculator.

**Scope — resolved 2026-07-28.** This household's scheme is **net pay**, not sacrifice, so A4 is not an
accuracy fix for V2. It still earns a build slot as a **what-if**: sacrifice vs net pay is a real,
quantifiable planning question ("would it be worth asking my employer?"), and answering it with the £
delta is exactly the adviser-grade output this plan is chasing. See "Decisions resolved".

---

## Part B — adviser-service parity

What a UK IFA actually delivers, and whether RF can supply it. The honest read: RF already beats an adviser
on the *modelling*, and the remaining reasons to hire one are mostly **coverage** (things never modelled at
all) and **cadence** (someone reviews it annually), not sophistication.

| Adviser service | RF today | Verdict |
|---|---|---|
| Fact-find / data gathering | Builder + import | **Have** |
| Cashflow modelling | Beats the adviser sector | **Have** |
| Tax planning (income, CGT, allowances) | HMRC-accurate engine | **Have**, extended by Part A |
| At-retirement: annuity vs drawdown | Annuitisation panel | **Have** |
| Estate planning — IHT computation | IHT engine + relationship status | **Have** |
| Means-tested benefits | Benefits engine | **Have** — advisers routinely *miss* this; RF leads |
| Withdrawal sequencing across wrappers | Specced, gated | Backlog |
| Guardrail / dynamic withdrawal policy | Not built | Backlog (competitive scan #2) |
| **Cost of advice itself** | Nothing | **B1 — build** |
| **Protection / life cover review** | Nothing | **B2 — build** |
| **Estate & admin readiness (will, LPA, nominations)** | Nothing | **B3 — build** |
| **Ongoing annual review** | Nothing | **B4 — build** |
| **Capacity for loss** | Partial (income floor) | **B5 — build the objective half** |
| Attitude-to-risk psychometrics | Nothing | **Non-goal** — see below |
| Fund / product / platform selection | Nothing | **Non-goal** |
| DB transfer advice | DB modelled as income | **Non-goal** — see below |
| Behavioural coaching | Assistant, partially | Partial, can't fully replace |

### B1. What the advice would cost you (build on A1)

Once A1 exists, this is nearly free and it directly answers the question in the title. Run the plan twice
on identical seeds — DIY charges vs advised charges — and show the **lifetime £ difference** and the
**change in depletion year**. RightCapital's discipline applies: always quantify the £, never just state
the choice.

**Defaults ⚠️** (NextWealth Fee Benchmarking 2026, to be re-verified against the primary report): ongoing
advice **0.83%** (up from 0.77% in 2025); **total** cost including platform and funds ~**1.80%**; a
restricted major firm ~1.65% vs ~0.85% at a low-cost whole-of-market firm on £400k. One-off retirement
advice £1,500–£4,000. Each user-editable.

**Framing discipline.** This must present as a **cost comparison**, not as "don't hire an adviser" — the
honest output is that advice costing 1% must add >1%/yr of value, which is a question the tool cannot
answer (behavioural value is real and unmodelled). State that limitation in the panel. Under
`compliance.personal_use = false` this needs a banned-phrasing review.

### B2. Protection gap — what life cover would close the survivor cliff — ✅ BUILT 2026-07-31

**Built as specced, plus the engine half the spec assumed away.** Death-in-service cover did not exist
as an input at all, so it was built first (`Person::$deathInServiceCover`, registered-scheme tax rules,
IHT-exempt, capital for the means test) and the panel then reads the payout out of the stressed
forecast rather than recomputing it. The solve is a **deterministic bisection** on a lump sum, not a
Monte Carlo probability restore: it runs synchronously in ~10–50 ms with no queue worker, and the bar
is the household's own baseline depletion year rather than an absolute one (an absolute bar is
unanswerable for a plan that already runs short). The retirement cliff-edge is priced as its own
figure. See DECISIONS 2026-07-31. **Finding:** for the real household the exposure is on the *retired*
partner, not the earner — the adviser reflex points the wrong way.

**The strongest reuse in this plan.** RF already computes the **survivor cliff** (the decision-support
spine has it with five levers), and it already knows the household's death ages, the income lost on first
death, and the spending floor. It therefore already knows the **size of the hole** — but never names the
instrument that fills it, which is one of the few genuinely valuable things a protection adviser does.

**Target shape.** A "if one of you died next year" panel: the lump sum required to restore the survivor's
plan to its pre-death success probability, and — where a partner is still working — an input for
**death-in-service** cover (typically 3–4× salary ⚠️), which most people hold and forget, and which can
close the gap entirely at zero cost. This is a solve, not a new simulation: RF has `LeverThresholdService`
for exactly this shape of "how much of X do we need?" question.

**Scope limit.** Model the *quantum of cover needed*, and note that cover written **in trust** falls outside
the estate for IHT (which the IHT engine can then reflect). Do **not** price policies or recommend products.

### B3. Estate & admin readiness checklist

Zero modelling, high real value, near-zero build cost. An adviser walks a client through: a valid will;
LPAs for property/finance and health/welfare (worth more than the will for a couple facing the care tail
RF already models); **expression-of-wish / nomination forms on every pension** — these govern who gets the
pot and are astonishingly often stale; records of gifts for the 7-year IHT clock (the video's "documenting
gifting" chapter, and RF's IHT engine already needs this data); a record of where the accounts are.

**Target shape.** A checklist surface on the scenario, persisted with the plan, feeding the IHT engine
where the data is quantitative (gift dates and amounts) and purely a prompt where it isn't. The gifting
half is genuinely load-bearing: RF computes IHT and cannot see gifts it was never told about.

### B4. Annual review / plan drift

The adviser's actual *recurring* product is not a model — it is "we sit down each year and see what
changed". RF stores every run, so it can do this better: compare this year's plan against the stored run
from N months ago and report **what moved and why** — actual vs forecast wealth, assumption changes,
legislative changes (fed by the existing `figures:freshness` guardrails), and the resulting shift in
depletion year and success probability.

This is the feature most likely to keep the tool *used* rather than run once and abandoned, and it reuses
`result_snapshots` plus the existing assistant for the narration.

### B5. Capacity for loss (build) vs attitude to risk (don't)

Split the regulated pair deliberately:
- **Capacity for loss is objective and computable** — how far can this household's assets fall before the
  essential spending floor is breached? RF already computes the income floor and can already run a
  start-of-retirement crash (FCA TR24/1 stress test #1). Report it as a number: *"a 30% fall in year one
  moves your depletion year from 2058 to 2049."* This is arguably better than what most advisers produce.
- **Attitude to risk is a psychometric questionnaire, and a non-goal.** It maps a personality score to a
  model portfolio — that is product selection under another name, it is the part of the adviser process
  with the weakest evidence base, and it drags the tool across the regulated line for no modelling gain.
  RF's editable asset allocation already lets the user express risk directly.

### Deliberate non-goals (state them in the PRD)

- **Fund, product and platform selection.** The clearest regulated line, and pure downside.
- **DB transfer advice.** The single most heavily regulated activity in UK retail advice (requires specific
  FCA permission, and the sector has a long redress history). The video's "DB to DC" chapter is a reminder
  that people ask — the right answer is a hard refusal plus a signpost, not a CETV comparator. RF should
  continue to model a DB pension as guaranteed income and say so.
- **Transacting anything.** Already implicit; make it explicit.
- **Behavioural coaching** cannot be fully replaced. The most-cited component of adviser value is stopping
  clients selling in a crash. RF can *help* — B4's review cadence, the stress panel, and a pre-commitment
  statement written while calm — but should not claim to substitute for it.

---

## Decisions resolved (2026-07-28, Rob)

All four open questions answered. Personal detail stays in the gitignored `docs/SCENARIO-V2.local.md`;
only the build consequences are recorded here.

1. **A3 — both partners are 65+ before 6 April 2027.** The cash-ISA cut therefore **does not apply to this
   household**, and neither does the under-65 S&S→cash transfer restriction. A3 **drops from an accuracy
   fix to a generality/public-release item.** What still binds them, and is still worth building: the
   **overall £20,000 subscription cap** (currently unenforced — unlimited sheltering) and the **22% charge
   on cash held inside a S&S ISA** from 6 April 2027. Build the age-conditional cash limit anyway when A3
   is picked up — same `IsaParameters` record, and a public release needs it — just not at accuracy priority.
2. **A2/A4 — the working partner's scheme is `net_pay`.** So relief is given at the **full marginal rate,
   immediately, with no NI saving and no self-assessment timing lag**. This is the *simplest* of the three
   methods: contributions come out of gross pay before the tax pass. A2 should implement `net_pay` first
   and completely; `relief_at_source` (with its following-year higher-rate recovery) can follow as
   generality. **A4 salary sacrifice is not this household's mechanism** and drops down the order — but is
   still worth building, because with it the tool can answer a genuine adviser-grade what-if: *"if your
   employer offered sacrifice instead of net pay, what would it be worth?"* (employee + employer NI, minus
   the £2,000 cap from April 2029).
3. **B1 approved** — build the cost-of-advice comparison, with the stated "cannot value behavioural
   coaching" limitation on the panel.
4. **B2 — death-in-service cover is in force on the working partner.** B2 is **promoted**: this is now a
   quick win rather than a modelling exercise. The cover may already close the survivor cliff, in which
   case the panel's job is to *show* that (and to show what happens to it at retirement, when
   death-in-service **ceases** — a real and commonly missed cliff-edge that RF is well placed to surface).

## Build order

_Revised 2026-07-28 after the answers above._ Accuracy first, then the reuse-heavy parity items.

1. ~~**A1 fee drag**~~ — ✅ **BUILT 2026-07-31** (DECISIONS 2026-07-31). Next by this order: A2.
2. ~~**A2 pension tax relief — `net_pay` path**~~ — ✅ **BUILT 2026-07-31** (DECISIONS 2026-07-31). The
   AA/MPAA cap and the £3,600 non-earner route are still to wire in. Next by this order: B2.
3. ~~**B2 protection gap**~~ — ✅ **BUILT 2026-07-31** (DECISIONS 2026-07-31). Next by this order: B1.
4. **B1 cost of advice** — nearly free once A1 lands.
5. **B5 capacity for loss** — mostly framing over existing stress machinery.
6. **A3 ISA rules** — reduced priority. Overall £20,000 cap + the 22% S&S-cash charge bind this household;
   the age-conditional cash cut is generality/public-release.
7. **A4 salary sacrifice** — generality, not this household's scheme; earns its place as the
   "what if your employer offered it?" comparison.
8. **B3 estate checklist** — cheap, but the gifting half should land with an IHT re-verify.
9. **B4 annual review** — largest build; do it when the tool is otherwise stable.

## Files to touch (first pass)

- Engine: `Dto/Account.php`, `Dto/DcPension.php`, `Dto/AssumptionSet.php`, `Forecast/PathProjector.php`
  (growth step + `applyContributions`), `MonteCarlo/SampledPathDraws.php`, `TaxYear/TaxYearRegistry.php`
  (+ new `IsaParameters`, amended `NiParameters` for 2029/30), `Pension/AnnualAllowanceCalculator.php`.
- App: `Forecast/HouseholdAssembler.php`, `Forecast/ResultPresenter.php`, the builder steps for
  contributions and accounts, the assumptions panel, `DecisionSupport/LeverThresholdService.php` (B2).
- Docs: `docs/spec/ASSUMPTIONS.md` (every new figure, sourced + `verified_on`),
  `docs/spec/METHODOLOGY.md` (fees, relief, ISA caps — and the "what we don't model" list gains the
  explicit non-goals), `docs/PRD.md` (non-goals), `docs/DECISIONS.md` per slice.

## Sources

**Contributions & wrappers:** [GOV.UK — tax on private pension contributions](https://www.gov.uk/tax-on-your-private-pension/pension-tax-relief) ·
[GOV.UK — ISA reform 2027 anti-circumvention factsheet](https://www.gov.uk/government/publications/fiscal-events-2026-factsheets/isa-reform-2027-anti-circumvention-rules-factsheet) ·
[Practical Law — cash ISA limit £12,000 from April 2027](https://uk.practicallaw.thomsonreuters.com/w-048-6635) ·
[Which? — cash ISA allowance slashed](https://www.which.co.uk/news/article/cash-isa-annual-allowance-slashed-what-you-need-to-know-aMLdQ9K6x9eX) ·
[ICAEW — NIC saving on salary sacrifice capped](https://www.icaew.com/insights/tax-news/2025/nov-2025/budget-nic-saving-on-salary-sacrifice-pension-contributions-capped) ·
[Commons Library CBP-10423 — NICs (Employer Pensions Contributions) Bill](https://commonslibrary.parliament.uk/research-briefings/cbp-10423/) ·
[IFS — assessing the salary-sacrifice reform](https://ifs.org.uk/publications/assessing-governments-reform-national-insurance-treatment-salary-sacrifice-pension)

**Costs & advice:** [NextWealth Fee Benchmarking Report 2026](https://nextwealth.co.uk/research/fee-benchmarking-report-2026/) ⚠️ primary re-verify ·
[NextWealth — Under Pressure](https://nextwealth.co.uk/under-pressure/) ·
[Money Marketing — advisers raising fees](https://www.moneymarketing.co.uk/news/fifth-of-advisers-plan-to-increase-fees-in-next-12-months/)

**Regulatory framing:** [FCA TR24/1](https://www.fca.org.uk/publication/thematic-reviews/tr24-1.pdf) ·
[FCA PS25/22 — targeted support](https://www.fca.org.uk/publications/policy-statements/ps25-22-consumer-pensions-investment-decisions-rules-targeted-support) ·
[Consumer Duty PRIN 2A](https://handbook.fca.org.uk/handbook/prin2a)

**Topic checklist provenance:** Damien Talks Money, *Answering All of Your Investing Questions*
(28 Jul 2026) — chapter list only; used to generate candidates, not as a source for any figure.
