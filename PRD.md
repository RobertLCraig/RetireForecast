# PRD: RetireForecast

> A local-first UK retirement / downsizing forecast tool that makes pension lump-sum tax
> consequences and run-out-of-money risk visible for an older couple.

**Stage:** active
_Last updated: 2026-06-28_

Full implementation plan and scope source of truth: [docs/PLAN.md](docs/PLAN.md). This PRD
is the orientation layer; the plan holds the exhaustive data model, UK rule set, Monte Carlo
design and phasing.

## Purpose
People approaching or in retirement make irreversible six-figure decisions (drawing a
pension pot, selling a home, signing a tenancy) on gut feel, and the tax and benefit traps
are invisible until too late. This tool lets a household drive their own numbers and *see*
the consequences, framed as education/guidance, never regulated advice.

The flagship worked example: an older couple (one still working, one retired) deciding
whether to sell their home and either (a) buy somewhere cheaper outright (no mortgage,
invest the surplus) or (b) sell and rent (invest all proceeds), with the pension
lump-sum question and longevity risk made explicit.

## Goals
1. Make the **pension lump-sum tax shock** visible: 25% tax-free PCLS, marginal income tax
   on the balance, and the Month-1 emergency-tax overpayment plus reclaim (P55/P50Z/P53Z).
2. Forecast **whether the money lasts for life** under uncertainty: Monte Carlo with
   stochastic joint-life mortality and sequence-of-returns risk.
3. Compare **buy-cheaper-outright vs rent** on identical simulated paths.
4. Be **trustworthy**: the engine reproduces known HMRC worked examples to the penny.

## Success criteria
- A working **local** site where Rob enters a real (known) couple himself, runs buy-vs-rent,
  and reads a forecast he trusts.
- The engine reproduces **HMRC worked examples A, B and C** (see docs/PLAN.md) to the penny,
  proven by unit tests, before any UI output is trusted.
- Monte Carlo is **reproducible** under a fixed seed (golden-master test).
- **No output ever phrases a personal recommendation** (enforced by a failing build test).
- **No hardcoded client data in the repo.** Any first-run sample is obviously synthetic.

## Scope
- HMRC-accurate deterministic tax/pension/benefits engine for tax years 2025/26 and 2026/27.
- DC pensions (flexible access), DB / final salary, and State Pension.
- Buy-vs-rent housing comparison with invested proceeds.
- Monte Carlo with correlated real returns, stochastic inflation, joint-life mortality.
- IHT / legacy as a toggle (including unused pension pots entering the estate from Apr 2027).
- Optional accounts: anonymous local use by default; saved scenarios encrypted at rest with
  GDPR export/delete when persistence is added.

## Non-goals
- **Not regulated financial advice.** No personal recommendations; signpost Pension Wise /
  MoneyHelper / FCA-regulated advisers.
- **Scotland income tax and LBTT/LTT are out of v1** (region resolver throws rather than
  faking rUK bands). England/Wales/NI first.
- No hardcoded real client data; no multi-currency (GBP only); no real-time market data.

## Requirements
- **Functional:** scenario builder; deterministic forecast; preview (sync ~1k paths) and full
  (queued ~10k paths) Monte Carlo with live progress and cancel; buy-vs-rent comparison;
  compare-assumptions overlay; headline tax-shock and success-probability outputs as text
  first, then charts.
- **Non-functional:** WCAG 2.1 AA (every chart has an accessible data-table equivalent);
  no silent long-running work; sensitive fields encrypted at rest; engine framework-free and
  unit-tested in isolation; every tax figure sourced and dated.

## Constraints
- Local-first, single-user initially; possible free public release later (do not design
  accounts out, just defer them).
- Laravel 13 + Livewire 4 (Filament pulled 4; the plan said 3) + Filament 5 + Fortify; PHP 8.4; SQLite locally.
- Money = integer pence (hand-rolled; brick/money dropped — see DECISIONS.md).
- UK tax thresholds frozen to April 2031; figures versioned per tax year.

## Open questions
- [x] **Default assumption-set figures** (return/volatility/inflation) — **RESOLVED 2026-06-24**:
      FCA real returns + DMS volatilities are the signed-off default; DMS/EGS and OBR/BoE ship as
      compare overlays. See DECISIONS 2026-06-24 "Assumption figures signed off".
- [x] **gov.uk verification pass** on every figure marked ⚠️ in docs/PLAN.md — **DONE 2026-06-27**:
      every ⚠️ statutory figure re-confirmed against gov.uk and stamped `verified_on: 2026-06-27`;
      no value changed; the April-2027 pensions-in-IHT change is now enacted (Finance Act 2026). See
      DECISIONS 2026-06-27. (The ONS mortality + FCA/DMS assumption *sources* sit at their 2026-06-24
      sign-off — a separate review, not this gov.uk statutory pass.)
- [ ] **Demo couple's anonymised figures** supplied by Rob, entered via the UI (not hardcoded),
      once the scenario builder exists.
