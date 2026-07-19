# Research: statement-driven onboarding & document import

_Captured 2026-06-28. Purpose: Rob wants to **upload documents** (bank statements, credit-card
statements, payslips, benefit statements) into the forecast wizard, have the app **extract what it
can and pre-fill the form**, then **ask only the remaining questions** — and to build an
**evidence-based budget from what the household actually spends**, rather than guessing from
"average user" national figures. This doc records how the personal-finance sector solves the hard
parts (transaction reconciliation, categorisation) and what we adopt, so the feature follows a
proven shape and — critically — stays inside this project's data-integrity discipline. It is a
**post-v1, parked** feature; this is the design captured before building._

> This is the AI question from the local-AI / Ollama investigation (2026-06-28 session) reframed
> correctly: the valuable use of a model here is **wrangling and explaining, not predicting**. Most of
> this feature is **deterministic matching**, and the one place a model earns its keep (long-tail
> categorisation) is optional, local, and walled off behind the same guardrails everything else has.

---

## The vision (Rob, 2026-06-28)

1. The wizard gains a **"upload your documents" first step**. The user drops in a batch:
   bank/current-account statements, credit-card statements, payslips, benefit/State-Pension
   statements.
2. The app **extracts** every figure it safely can and **pre-fills** the relevant builder fields
   (spending, income, salary, pension contributions, benefits).
3. The wizard then **only asks the remainder** — the things documents can't tell you (DOB, the
   housing decision, pension *pot* values, assumptions).
4. Imported transactions are **reconciled** (the £1,258 example below) and **categorised into the
   three spending tiers** (essential / discretionary / self-investment), producing an accurate
   picture of current costs that feeds the budget — replacing the "average user" guess.

---

## 1. The core risk first — this is a double-counting problem wearing a new hat

Rob's worked example: a **£1,258 credit to the credit card** and a **£1,258 debit from the current
account** are *the same event* (paying the card off), **not** £2,516 of activity. This is the single
most common inaccuracy in personal-finance software, and it is exactly the bug class **this
project's hard rule exists to kill** — "one definition, one home; never double-count" (DECISIONS
2026-06-25, data-layer integrity; the past-project burn in MEMORY). A credit-card payment counted as
spend would overstate outgoings, overstate the chance of running out, and corrupt the budget.

**The whole industry treats this as deterministic matching, not AI:** identify money moving between
the household's own accounts and **exclude it from spending totals**. "Treating credit-card payments
as transfers ensures they're not counted a second time — otherwise it would look like you spent twice
the actual amount" ([Monarch](https://help.monarch.com/hc/en-us/articles/360048393292-Transfers-and-Credit-Card-Payments)),
and double-counted transfers are a recurring support topic when tools get it wrong
([Quicken](https://community.quicken.com/discussion/7851585/payment-transfers-counting-twice-in-budget)).
Re-import duplicates are prevented with a **stable imported id** so the same row is never added twice
([Actual Budget](https://actualbudget.org/docs/api/reference/)).

**Consequence for us:** transfer-matching and dedup must be **deterministic, tested, and reconciled**
— an LLM is the *wrong* tool for it precisely because it is non-deterministic, non-auditable, and
unreliable at arithmetic (the local-AI investigation findings). The matcher gets its own
reconciliation invariant and a real-file golden fixture, like every other importer.

---

## 2. Deterministic core vs. an optional, walled-off model assist

Split the pipeline so the trust-critical parts never touch a model:

| Stage | What it does | Build as | Model? |
|-------|--------------|----------|--------|
| **Parse** | statement export → normalised rows (date, amount, description, account) | extend `app/Import/` profiles | No — except optionally to *guess the column layout* of an unknown CSV |
| **Dedup** | drop re-imported rows | stable `imported_id` | No |
| **Transfer-match** | pair equal-and-opposite movements across the household's accounts; exclude from spend | rules: opposite sign, equal pence, date window (±~3 days), **user-confirmed** | **No** |
| **Categorise** | description → essential / discretionary / self-investment | merchant-map + string rules **first** | **Long tail only** |
| **Reconcile** | Σ spend + Σ transfers(excluded) + Σ uncategorised == Σ imported; nothing dropped | `ReconciliationLine` panel | No |

**Rules do most of the work.** A rule-based merchant map (`description CONTAINS 'TESCO' → groceries`)
handles **60–80 % of transactions at perfect accuracy**, and local sentence-embeddings give ~90 % of
LLM quality at ~10 % of the cost; the repeated industry finding is that an LLM "is not a magic bullet
for truly dirty data" — bank descriptions are short, abbreviated and inconsistent, so pre-processing
rules are needed regardless ([Expense Sorted — beyond LLMs](https://www.expensesorted.com/blog/advanced-bank-transaction-categorization-methods-2025),
[Expense Sorted — faster than LLMs](https://www.expensesorted.com/blog/advanced-bank-transaction-categorization-beyond-llms)).

**Where a local model earns its place:** the **long tail of unknown merchants**, as a
*human-confirmable suggestion*, never a silent re-bucketing. If any model is used, it must be **local**
— bank/credit-card data is the most sensitive data the app will ever hold and must never leave the
machine (the local-first ethos; cloud LLMs carry the "risk of sending financial data to third
parties" — [DZone](https://dzone.com/articles/local-llm-finance-tracker),
[local personal-finance analysis](https://padulaguruge.medium.com/analyzing-personal-finances-locally-with-ai-using-llms-and-python-panel-for-secure-expense-eb0f3831517c)).
A mis-categorisation here is **low-stakes** — it moves a pound between tiers but does **not** change
the grand total, as long as completeness holds. The transfer-match, which *does* change the total,
stays strictly deterministic + confirmed.

**Order of build:** start rules-only; the LLM assist is a later, optional layer. The whole value of
the feature lands without any model at all.

---

## 3. Which document fills which builder field (the pre-fill map)

Different document types populate **different parts of the builder** — this is the heart of
"pre-fill, then ask the rest". It extends the existing `PayAndExpenditures` importer mapping
(State Pension → state pension, DLA → tax-free income, salary → gross, partner pension → annuity).

| Document | Feeds (builder section) | Extractable |
|----------|-------------------------|-------------|
| **Bank / current-account statement** | expense lines; income (recurring credits); transfers | transactions → categorised spend; recurring salary/benefit/pension **credits** → income; standing orders to own savings/investments → **saved self-investment** (see §5) |
| **Credit-card statement** | expense lines | transactions → categorised spend; the monthly payment debit/credit is a **transfer** (the £1,258 case), not spend |
| **Payslip** | `Person.gross_salary`, NI category, pension contributions, tax code | gross pay, employee + employer pension contributions, NI category, tax code (a sanity-check on assumed bands) |
| **Benefit / State-Pension statement** | `IncomeStream` (taxable vs **tax-free**), state pension | benefit type + amount + frequency, **and whether it is taxable** — e.g. DLA is **tax-free** |

**Completeness rule applies directly here.** The benefit-statement path must classify **taxable vs
tax-free** correctly — silently dropping a tax-free stream is exactly the **DLA income bug** the
project was burned by (DECISIONS 2026-06-25, forecast income completeness). Every extracted income
source must reach the forecast, guarded per-source.

---

## 4. What the wizard must still ask (documents can't tell you)

The pre-fill covers cashflow and current balances; it **cannot** supply the forward-looking and
structural inputs, so the wizard still asks for:

- **People:** DOB, sex (for the life table), employment status, planned retirement age, longevity
  adjustment — none of which appear on a statement.
- **Pension *pots*:** a payslip shows *contributions*, not the **DC pot value** or **DB accrued
  amount** — those need a pension statement (a future profile) or manual entry.
- **Property:** current value, outstanding mortgage, running costs — a statement shows the mortgage
  *payment*, not the *balance* or the home's value.
- **The decision itself:** sell / buy-cheaper / rent, and the assumed sale/purchase/rent figures.
- **Modelling choices:** assumption set, region, IHT toggle.
- **The retirement-vs-now adjustment (important):** imported actuals are **today's** costs. The
  retirement budget differs — the mortgage ends, commuting stops, and spend follows the "smile"
  (see [RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md) §2d). So the wizard should
  present actuals as the **input baseline** and let the user mark which lines **continue into
  retirement**; the forecast adjusts forward. **Actuals are the input; PLSA stays the benchmark we
  compare against — not the input.** Keeping those two separate is a design rule, not a nicety.

---

## 5. Presenting the extracted spend in the three tiers

After dedup + transfer-exclusion, the categorised transactions are shown on a **review screen** the
user confirms before it becomes the budget:

- **Grouped by category**, each with a **suggested tier** (essential / discretionary /
  self-investment) and a **running subtotal per tier**, editable. Tiers map to the existing model:
  *essential* = needs/floor, *discretionary* = Rob's "desires"/wants, *self-investment* = learning /
  savings / personal investment (DECISIONS 2026-06-25, 3-tier line items).
- **Annual run-rate, not a single month.** Frequencies are normalised to annual (the importer
  already does monthly ×12). **Use several months** of statements to get a stable run-rate —
  annualising one month overweights one-offs (a £2k holiday in March ≠ £24k/yr). Show how many
  months informed each figure.
- **Recurring vs one-off** detection: recurring monthly (utilities, subscriptions, rent) → likely
  essential floor; irregular large items → discretionary. This also hints which lines continue into
  retirement (§4).
- **Confidence surfaced:** rules-matched rows are high-confidence and auto-assigned; long-tail /
  model-suggested rows are **flagged for review** (no silent assignment).
- **The self-investment / transfer insight:** a standing order into the household's **own ISA or
  investment account** is *both* an internal transfer (so **not spend**) *and* a **saved
  self-investment** contribution that builds net worth. Handle it as a **contribution, counted once**
  — "one home per pound" (gotcha O). It must not appear as spend *and* as a saved line *and* as an
  account top-up.
- **Reconciliation + completeness, shown:** the panel asserts
  `Σ categorised spend + Σ transfers(excluded) + Σ saved contributions + Σ uncategorised == Σ imported`
  — **nothing silently dropped** — using the existing `ReconciliationLine` value object, red +
  `role=alert` on any divergence. This is "no silent failure" applied to counting.
- **Output:** the confirmed result writes `builder_state.expenseLines`
  (`{id, label, amount(annual), category, savedAsAsset}`), the existing single source of truth — so
  the imported budget assembles and forecasts identically to a hand-typed one.

---

## 6. Formats & scope

- **File formats:** CSV is the realistic baseline (per-bank, messy columns — same problem
  `app/Import/` already solves). OFX/QFX and QIF are cleaner, structured alternatives worth
  supporting. **PDF statements** need text/table extraction (and OCR for scans) — materially harder,
  lower-fidelity, and a likely place to *offer* a model for layout extraction; flag as its own
  sub-phase. Bank-statement-extraction tooling is a known 2026 problem space, not a solved one.
- **Open Banking is out of scope for v1.** Direct bank API aggregation is regulated (AISP
  authorisation) and online — it breaks the local-first, file-based model. File import is the correct
  local path; revisit aggregation only if there's ever a hosted release.
- **Architecture:** this is an extension of `app/Import/` (a **statement profile family** producing
  `ImportResult::expenseLines` + `reconciliation`), **not** a new subsystem, and **app-layer only**
  — the engine stays framework- and dependency-free. Any model integration is app-layer and local.

---

## What we adopt (and the phasing)

Each phase delivers value alone; the model is the *last*, optional layer.

1. **Deterministic ingest (the whole core).** Parse + dedup + the **transfer-matcher with a
   confirmation UI** + reconciliation, fully tested with a **real-file golden fixture that includes a
   known transfer pair**. Delivers the £1,258 fix and an accurate current-spend picture with **zero
   AI**.
2. **Rules categorisation** into the three tiers (merchant-map + string rules), review screen,
   write to `expenseLines`.
3. **Optional local-model long-tail assist** for unknown merchants — walled off, local-only,
   human-confirmable, never altering a total.
4. **Income-side extraction** (payslips, benefit/State-Pension statements) → pre-fill income, with
   the taxable/tax-free completeness guard.
5. **Document-upload onboarding** wires §1–4 into the wizard's first step ("upload, then we ask the
   rest"). PDF/OCR extraction is its own sub-phase.

---

## Gotchas — what could bite

| # | Bite | Mitigation |
|---|------|------------|
| DI-1 | **Internal transfer counted as spend** (the £1,258 case) → outgoings & run-out risk overstated | deterministic equal-and-opposite + date-window match, **user-confirmed**, **excluded** from spend; reconciliation invariant + golden fixture with a known transfer pair |
| DI-2 | **Re-import duplicates** the same transactions | stable `imported_id`; never add twice |
| DI-3 | **Annualising too few months** → a one-off becomes a phantom recurring cost | require/encourage several months; show the months behind each run-rate; flag one-offs |
| DI-4 | **Saved-to-own-account double-count** (transfer *and* spend *and* account top-up) | one home per pound (gotcha O) — a saved transfer **is** the contribution, counted once |
| DI-5 | **Tax-free income silently dropped** (benefit statements, the DLA bug) | classify taxable vs tax-free; per-source completeness test |
| DI-6 | **Model mis-categorises / hallucinates** | model only for the long tail, **suggestion not decision**, human-confirmed; rules handle the bulk; mis-tier never changes the grand total |
| DI-7 | **Sensitive data leaves the machine** | local-only model; no cloud categorisation of statement data |
| DI-8 | **Imported actuals treated as the retirement budget** | actuals = input baseline; mark which lines continue; forecast adjusts; PLSA stays the benchmark, not the input |
| DI-9 | **PDF/scanned statements mis-read** | treat PDF/OCR as a flagged, lower-confidence sub-phase; surface every extracted figure for review |

---

## Sources

- Transfer handling / avoiding double-count: [Monarch — Transfers & Credit Card Payments](https://help.monarch.com/hc/en-us/articles/360048393292-Transfers-and-Credit-Card-Payments), [Quicken community — payment transfers counting twice](https://community.quicken.com/discussion/7851585/payment-transfers-counting-twice-in-budget)
- Dedup via stable import id: [Actual Budget — API reference](https://actualbudget.org/docs/api/reference/)
- Rules vs LLM categorisation (60–80 % rules; embeddings 90 %/10 %; "not a magic bullet"): [Expense Sorted — beyond LLMs](https://www.expensesorted.com/blog/advanced-bank-transaction-categorization-methods-2025), [Expense Sorted — faster than LLMs](https://www.expensesorted.com/blog/advanced-bank-transaction-categorization-beyond-llms)
- Local-LLM finance, privacy: [DZone — build a private LLM finance analyzer](https://dzone.com/articles/local-llm-finance-tracker), [analysing personal finances locally with LLMs](https://padulaguruge.medium.com/analyzing-personal-finances-locally-with-ai-using-llms-and-python-panel-for-secure-expense-eb0f3831517c)
- Statement extraction is an unsolved-ish space: [2026 guide to AI bank-statement extraction](https://unstract.com/blog/guide-to-automating-bank-statement-extraction-and-processing/)
- LLM arithmetic/hallucination (why the matcher is rules, not a model): [Deficiency of LLMs in Finance — Hallucination (arXiv)](https://arxiv.org/pdf/2311.15548), [FAITH: tabular hallucinations in finance (arXiv)](https://arxiv.org/pdf/2508.05201)
