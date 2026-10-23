# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. **This doc holds what is true; [docs/board/](board/) holds what is moving.** Read [docs/build/PLAN.md](build/PLAN.md) for the full approved plan and scope.

**Stage:** active
**Category:** site
**Status:** **Feature-complete for personal use, and now carrying a large reviewed defect backlog.** The engine, the app, the post-v1 enhancement backlog, decision-support (Phases 0 to 6), the local assistant, IHT and the care means-test are all built. A five-discipline expert review on 2026-08-19 found defects across all of them, several of which change which plan the comparison ranks first. What remains is that backlog, Rob's **browser sign-off**, and the **public-release blockers**.
_Last updated: 2026-10-23 (cards 0212 to 0225, the 2026-10-21 full review). The exceptions a fresh session needs, newest first. The "what is built"
inventory and the older card bullets were folded out to [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md).
`HandoverHygieneTest` fails this file above 40 KB. The rule for which bullets to fold next is
Rob's call on card 0151:_

- **The results page leads with the answer, in plain words.** Cards 0216 to 0224, all in
  ai-review and **none seen in a browser**. A run from an older engine now shows an amber "out of
  date: re-run it" box (card 0219), so every stored result shows it until the re-run below.
  A Welsh home is refused with a reason (card 0215). `/scenarios/{id}/money` shows income by
  source and what it lets you spend (card 0225).

- **An income stream with an end age stops on the birthday after it, not on 1 January.** Card 0172.
  `ENGINE_VERSION` is `finance-engine/income-stream-ends-on-the-birthday`; the **re-run is owed**
  for plans with an income stream end age.

- **A CPI- or RPI-linked DB pension is frozen, not cut, in deflation.** Card 0170. The **re-run is
  owed** for Monte Carlo plans with such a pension.

- **Tax thresholds index from 2031-32, not 2032-33.** Card 0214. `ENGINE_VERSION` is
  `finance-engine/thresholds-index-from-2031-32`; the **re-run is owed** for every plan past 2030.

- **Each year uses the income rates enacted for it.** Card 0213. Savings and rent pay 22% / 42% /
  47% from 2027-28, and a 2025-26 plan takes the 2026 dividend rates. `ENGINE_VERSION` is
  `finance-engine/savings-and-property-rates-rise-in-2027`; the **re-run is owed** for plans with
  taxable interest above the allowances or taxable rent.

- **The Monte Carlo now grows money at the stated rate.** Card 0212. Before, the typical all-shares
  pot grew 1.7% a year, not 4.4%, so every success chance was too low. `ENGINE_VERSION` is
  `finance-engine/monte-carlo-draws-compound-to-the-stated-rate`; **every stored Monte Carlo result
  owes a re-run**.

- **A "Let out & rent elsewhere" plan pays the tenancy deposit in year 0.** Card 0165.
  `ENGINE_VERSION` is `finance-engine/let-out-and-rent-pays-a-tenancy-deposit`; the **re-run is
  owed** for let-out-and-rent plans. The notice on that plan is **not seen in a browser**.

- **A tax-free inherited pension draw is income for Pension Credit.** Card 0145. `ENGINE_VERSION` is
  `finance-engine/inherited-draw-assessed-for-pension-credit`; the **re-run is owed** for plans on
  Pension Credit that draw an inherited or a taxed pot. The rule is not yet cited at source.

- **A pot nominated to somebody else leaves the household at the member's death.** Card 0133.
  `ENGINE_VERSION` is `finance-engine/pot-nominated-away-leaves-the-household`; the **re-run is
  owed** for couple plans with such a pot. An unanswered nomination is still taxed as leaving but
  kept (card 0206).

- **A home sold before the plan earns the downsizing addition.** Card 0126. Blank by default, so no
  re-run is owed. The two new step-1 inputs are **not seen in a browser**. An early sale is measured
  against the wrong band (card 0205).

- **Pension Credit assesses earnings net of tax, NI and half a net-pay contribution.** Card 0120,
  partial. `ENGINE_VERSION` was `finance-engine/pension-credit-assesses-earnings-net`; the **re-run is
  owed** for plans with earnings in a Pension Credit year. The earnings disregard still waits on
  sourced figures. Reasons in DECISIONS 2026-10-15.

- **Another adult living at home blocks the severe-disability addition.** Card 0121, partial. Default
  none, so no re-run is owed. The registered-blind route is still open on the card; the excused-adult
  list is card 0204. The new step-2 field is **not seen in a browser**.

- **Pension Credit can be marked not claimed.** Card 0110. Default claimed, so no re-run is owed.
  The new step-2 box and the results line are **not seen in a browser**. ASSUMPTIONS §39.

- **The triple lock has its earnings limb, on a sourced national earnings figure.** Card 0100.
  `ENGINE_VERSION` is `finance-engine/triple-lock-earnings-limb`; the **re-run is owed**, and the
  `assumption_sets` rows must be **re-seeded** first or the figure reaches no forecast. The new
  assumptions row, builder field and note are **not seen in a browser**. Sources in ASSUMPTIONS §19.

- **An RPI-linked DB pension escalates at CPI + 0.39%, sourced to the OBR.** Card 0095. The 3% fixed
  default stands, sourced to the PPF. `ENGINE_VERSION` is `finance-engine/rpi-wedge-39bp-obr-2026`;
  the **re-run is owed** for plans with an RPI basis. The new disclosure is **not seen in a
  browser**. Sources in ASSUMPTIONS §18.

- **A bought home with no upkeep entered pays the greater of 1% of its price and £3,000 a year.** Card
  0094. `ENGINE_VERSION` is `finance-engine/bought-home-upkeep-has-a-flat-floor`; the **re-run is
  owed** for buy plans under £300,000 on that default. The new disclosure is **not seen in a
  browser**. Sources in ASSUMPTIONS §17.

- **A row reports the mortgage owed at the end of its year.** Card 0103. `ENGINE_VERSION` is
  `finance-engine/mortgage-reported-at-the-end-of-its-year`; the **re-run is owed** for every
  mortgaged plan. The SMI and deferred care balances still lag (card 0200).

- **The referencing flag is sourced, and rent in advance is no longer offered as a way round.** Card
  0091. 30x / 36x stand (HomeLet, Goodlord); the 6-12 months became the 1-month statutory cap. No
  re-run is owed. The new copy is **not seen in a browser**. Sources in ASSUMPTIONS §15.

- **A let home with blank letting rates loses 34% of its rent, sourced.** Card 0087. Management is 18%
  (was 12%), maintenance 8% (was 5%), void still 8%. `ENGINE_VERSION` is
  `finance-engine/letting-costs-sourced-at-34-percent`; the **re-run is owed** for let plans with a
  blank rate. The step-3 placeholders now read the constants and are **not seen in a browser**.
  Sources in ASSUMPTIONS §14.

- **A blank service-charge growth rate is CPI + 1.8%, cited to Hamptons.** Card 0085. It was CPI + 3%.
  `ENGINE_VERSION` was `finance-engine/property-cost-growth-sourced-at-cpi-plus-1-8`; the **re-run is
  owed** for stay-put plans with a service charge and a blank rate. The step-4 help text is **not seen
  in a browser**. Source in ASSUMPTIONS §12.

- **Each member enters their own share of the home, and the sale and care follow it.** Card 0102.
  No re-run is owed. The two share inputs are **not seen in a browser**. Reasons in DECISIONS 2026-10-11.

- **A gain is banded on the whole income the year's drawing leaves.** Card 0098. `ENGINE_VERSION` is
  `finance-engine/cgt-banded-on-the-whole-post-drawdown-income`; the **re-run is owed** for plans
  that realise a gain beside savings, dividends or a pension draw. Reasons in DECISIONS 2026-10-10.

- **Pension Credit is paid only from the qualifying date.** Card 0097. `ENGINE_VERSION` was
  `finance-engine/pension-credit-from-the-qualifying-date`; the **re-run is owed** for Pension
  Credit plans with a member reaching State Pension age in the horizon. Reasons in DECISIONS 2026-10-10.

- **A pension draw re-assesses Housing Benefit and Council Tax Reduction at a nil credit.** Card
  0077 #4. `ENGINE_VERSION` is `finance-engine/pension-draw-reassesses-housing-support`; the
  **re-run is owed** for pension-age renters or CTR claimants who draw taxable pension money.

- **The survivor-annuity sweep re-prices the annuity, and `figures:freshness` sweeps asset
  classes.** Card 0065 #1 and #4. `ENGINE_VERSION` is `finance-engine/survivor-annuity-sweep-reprices`;
  only stored thresholds for that lever are owed a re-run. Reasons in DECISIONS 2026-10-09.

- **The inflation correlation on the panel is the one the Monte Carlo runs.** Card 0064 #2.
  `ENGINE_VERSION` was `finance-engine/inflation-correlation-is-the-one-stated`; the **re-run is
  owed** for every Monte Carlo result on a shipped set. Reasons in DECISIONS 2026-10-09.

- **The spending guardrail counts the whole essential floor.** Card 0063 #1. Its bite test now
  runs inside the Pension Credit pass, after rent, the mortgage payment, running costs, council tax
  and care join the floor. `ENGINE_VERSION` is `finance-engine/guardrail-counts-the-whole-essential-floor`;
  the **re-run is owed** for guardrail plans with any of those costs.

- **A purchased life annuity buys only with its own account's money.** Card 0060 #1. The named
  account's balance is ring-fenced beside the pooled wrapper and grown with it; spending takes the
  owner's other money in that wrapper first. `ENGINE_VERSION` is
  `finance-engine/annuity-capped-at-its-named-account`; the **re-run is owed** for plans with an
  account annuity beside another account of the same wrapper.

- **A park home that is sold pays the site owner's commission.** Card 0059 #2. Year-0 and forced
  sales now take it, itemised. `ENGINE_VERSION` is `finance-engine/park-home-sale-pays-commission`;
  the **re-run is owed** for plans that sell a park home.

- **A pension can be nominated to a cohabiting partner.** Card 0057 #3. Such a pot is no longer
  charged the beneficiary's income tax at the first death. The **re-run is owed** for cohabiting
  plans that model Inheritance Tax. The relabelled nomination select is **not seen in a browser**.

- **No rent is charged once a sold home's household is all in care.** Card 0056 #4.
  `ENGINE_VERSION` is `finance-engine/no-rent-while-everyone-is-in-care`; the **re-run is owed**
  for plans with a rent figure, a sale and a care spell. The builder's roll-up help text now names
  the care trigger (#3) and is **not seen in a browser**.

- **A buy variant keeps the "qualifying relative lives here" care disregard.** Card 0055 #1.
  `ENGINE_VERSION` is `finance-engine/buy-variant-keeps-relative-disregard`; the **re-run is
  owed** for plans with that box ticked and a care spell.

- **Intestacy asks "are there children?" on its own.** Card 0054 #3. `ForecastSettings::$hasDescendants`
  (default yes) replaces the old read of "leave the home to descendants". `ENGINE_VERSION` is
  `finance-engine/intestacy-asks-about-descendants`; the **re-run is owed** for intestate married
  plans that unticked the home box. The new checkbox is **not seen in a browser**.

- **A mobility-only income row buys no Pension Credit addition, and a mixed-age couple is not told
  to claim.** Card 0051 #1 and #4. With "which part of the award" blank,
  `HouseholdAssembler::awardRateFromStreams` reads the income rows; a part the reader chose still
  wins. `ENGINE_VERSION` is `finance-engine/mobility-row-buys-no-pension-credit-addition`; the
  **re-run is owed** for plans with only a mobility row and a blank part. The new "instead" list
  in the Pension Credit panel is **not seen in a browser**.

- **A partner in care leaves the other a single council tax occupant.** Card 0047 #2.
  `ENGINE_VERSION` is `finance-engine/council-tax-single-occupant-beside-care`; the **re-run is
  owed** only for two-member plans with a split council tax bill and a care spell. The PLSA benchmark now
  counts a split bill, and the demo's running costs dropped to £2,800 so its £2,200 bill is not
  paid twice. A home left empty for care pays none (card 0180, re-run owed). The new upkeep-note sentence and builder
  hint are **not seen in a browser**.

- **SMI is met only on a home the household lives in, and care nets its charge.** Card 0045 #1
  and #4. The **re-run is owed** for let plans on Guarantee Credit and for SMI plans that reach
  care. The SMI rate and cap are still unsourced (card 0109).

- **A survivor's DB pension escalates on its in-payment basis from the death.** Card 0035 #4.
  `PathProjector::escalateDbPensions` starts payment at the earlier of normal retirement age and
  the year after the member's death. `ENGINE_VERSION` is
  `finance-engine/survivor-db-escalates-in-payment-from-death`; the **re-run is owed** for plans
  whose two DB bases differ and whose member dies deferred.

- **A let home's service charge and running costs are paid once.** Card 0030 #2. The part
  `PathProjector::lettingCostsPerOwner` takes off the rent now leaves the spend; a tax-free rental
  stream carries no letting costs. `ENGINE_VERSION` is `finance-engine/let-home-bills-are-paid-once`;
  the **re-run is owed** for let plans. Reasons in DECISIONS 2026-10-05.

- **A sampled home never falls to or below nothing.** Card 0029 #4. `PathProjector::grownHomeValue`
  keeps a home that had value at a penny or more. `ENGINE_VERSION` was
  `finance-engine/home-value-never-below-nothing`; only Monte Carlo runs with a tail path move.
  The card's park-home re-run (task 4) is still owed on the live database.

- **The spend levers keep the reader's property-cost growth rate.** Card 0028 #4. `ENGINE_VERSION`
  was `finance-engine/spend-levers-keep-property-cost-growth`; thresholds and sustainable-spend
  answers are owed a re-run for plans with a stated rate. The results note now shows the rate in
  force (a stated 0% too), only on a stay-put plan. Not seen in a browser. Card 0162 routed both
  levers through `ExpenseProfile::withFlatSpend()`, so they keep the spending guardrail too.
  `ENGINE_VERSION` is `finance-engine/spend-levers-keep-the-spending-guardrail`; the **re-run is
  owed** for thresholds and sustainable-spend answers on guardrail plans.

- **"Fully funded" now includes the one-off lumps.** Card 0025. Readers that say a plan is fully
  funded ask `ForecastResult::fullyFunded()`, not `$fullSpendAlwaysMet`; an unaffordable mortgage
  redemption fails full spend again. `ENGINE_VERSION` was `finance-engine/unredeemed-mortgage-fails-full-spend`;
  the **re-run is owed** for keep-the-home plans that cannot redeem. The new `/afford` verdict is
  not seen in a browser.

- **Only the mortgage payment is held flat.** Card 0024. A premium, fee or discretionary line that
  stops with the mortgage now rides CPI in its own tier (`HouseholdAssembler::isMortgagePayment`).
  `ENGINE_VERSION` is `finance-engine/only-the-mortgage-payment-is-flat`; the **re-run is owed** for
  plans with such a line. The new results note and builder hint are not seen in a browser.

- **The rent arm keeps the reader's settings, and bed-and-ISA has a builder checkbox.** Card 0016.
  `ENGINE_VERSION` is `finance-engine/rent-arm-keeps-the-readers-settings`; the **re-run is owed**
  for rent plans with any setting away from the default. Not seen in a browser.

- **The "export all" zip is a one-day download, and its sweep needs the scheduler.** Card 0014.
  `ScenarioExport::servable()` serves only the latest successful build under 24 hours old; a new
  or failed build deletes the old zip, and a build with no progress for 15 minutes can be retried.
  `exports:prune` deletes zips older than a day and is scheduled hourly in `routes/console.php`,
  so it runs only where `php artisan schedule:work` (or a cron `schedule:run`) runs. Not seen in a
  browser.

- **A let home's running costs come off the rent, and they carry its repairs.** Card 0088.
  `PathProjector::runningCostsNominal()` is the one home of the figure the spend charges and
  `lettingCostsPerOwner` deducts, but only where `annualCouncilTax` is held apart.
  `Property::repairsInRunningCosts()` zeroes the repairs rate where running costs exist. A let plan
  is charged the "Council tax on the rented home" bill where one is entered, else the let home's
  stands in (card 0155, `ENGINE_VERSION` `finance-engine/let-and-rent-pays-the-rented-homes-council-tax`;
  the **re-run is owed** only for let plans with that box filled).
  Reasons in DECISIONS 2026-10-04. `ENGINE_VERSION` is
  `finance-engine/let-home-running-costs-are-a-letting-expense`; the **re-run is owed** for let
  plans with running costs. Built in a worktree, so the note and the builder hint are **not seen in
  a browser**.

- **A forced sale is charged the tenancy deposit in the sale year.** Card 0090. `ENGINE_VERSION` is
  `finance-engine/forced-sale-tenancy-deposit`; the **stored re-run is owed** for any plan with a
  maturity forced sale and a rent figure. A care-forced sale is charged no deposit, and no rent
  while in care (card 0056 #4, proven by card 0154).

- **A card worktree can start with `packages/finance-engine` emptied — 289 tracked files deleted in
  the working tree, index and `master` untouched.** Card 0149. **Read `git status` before committing
  in a worktree**, or those deletions merge back and the engine is gone. Recovery loses nothing:
  `git checkout -- packages/finance-engine`, then `composer update retireforecast/finance-engine`.
  It has recurred on EVERY subsequent pick-up of this worktree, each time after a recovery had
  already run, so expect it rather than treating it as a one-off: recovering does not settle it for
  the session, and you check `git status` again before you commit, not only when you start.

- **There is no `vendor/bin/pest.bat` here, though an unattended session is told to run one** (card
  0150). PHPUnit is the runner and `php artisan test` is the whole answer, not a fallback. It runs to
  completion since card 0147 put `memory_limit` 1G in `phpunit.xml` (2026-09-21); before that it
  died at the 128M Herd default and every card read as red.

- **`vendor/bin/pint --test` exits zero on a clean tree, so it can gate a release.** Card 0083. One
  engine file was behind the ruleset (`--dirty` only formats a file when it is next edited); the two
  app files the card named had already caught up. Cosmetic only, no figure moves.

- **Cards 0040 to 0082 are folded out to [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md)** (card
  0151). What still stands from them: every `ENGINE_VERSION` bump there owes the stored-scenario
  re-run (see What's next), every new screen there is unseen in a browser (card 0001), and every
  gap or STATED-not-verified figure there carries its own card. Three calls left to Rob are
  recorded in DECISIONS 2026-09-08: the cheapest-order candidate set (0078), whether an unanswered
  pot infers a crystallised share (0080), and whether a shortfall is printed beside the winner
  (0081). `docs/spec/METHODOLOGY.md` still does not mention deprivation (0049).
- **`scenarios:audit` cannot be used as a gate until every stored scenario is re-run.** It exits 1
  on 120 lines, all of them "run N carries no integrity stamp (it predates the column)", with no
  other problem class anywhere. Applying the pending `add_hashes_to_simulation_runs_table` migration
  does **not** clear them — the audit's integrity check asks whether `integrity_hash === null`, and
  every already-completed run stays null. Card 0025's `ENGINE_VERSION` bump means those runs are stale on figures too, so the
  re-run settles both at once.
- **The assistant can now propose a what-if, behind a flag that is off.** Card 0020 built Phase 1 of
  [PLAN-assistant-scenario-editing.md](build/PLAN-assistant-scenario-editing.md): a "Change plan" tab
  that turns what the reader says into a reviewable diff, written only on confirm and only as an
  ordinary delta-child. It is invisible until `ASSISTANT_CAN_EDIT_SCENARIOS=true`. This narrows the
  "the model never builds" doctrine; DECISIONS 2026-08-29 records the guardrails. Not yet exercised
  against a real `qwen3:14b`, and not yet seen in a browser.
- **An override cannot create a map the base does not have.** `BuilderStateDelta::merge` drops
  `assumptionOverrides.inflation` when the base overrides no assumption at all, so a what-if that is
  the first in its family to touch an assumption does not get it. It is not silent (the path reports
  as an orphan, which the results, compare, PDF and `scenarios:audit` surfaces all show), but the
  what-if does not model what was asked. Found during card 0020, which works around it by offering
  only assumptions the base already carries; the underlying fix is uncarded.
- **Card 0019 settled the multi-property plan and wrote no code.**
  [PLAN-multi-property.md](build/PLAN-multi-property.md) is out of DRAFT with its five scope questions
  answered, and card 0019 now carries the Phase-1 acceptance behind `needs: 0029, 0030`. One question
  is Rob's and sits on that card: whether a second property exists to model at all. DECISIONS 2026-08-29.
- **Card 0018's migration has not been applied to the app database.** Run `php artisan migrate` after
  this branch merges, or a completed run carries no integrity stamp and no cache key.
- **Cards 0011 to 0016** are summarised in [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md). What still
  stands from them: the stress-test data licence and the human WCAG 2.2 pass are open, and the
  comparison should not be read off until the ranking-movers at the head of the queue are fixed.

## Goal & success criteria
Full plan: [docs/build/PLAN.md](build/PLAN.md); PRD: [PRD.md](PRD.md). Summary:
- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), plus the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, the Month-1 emergency-tax overpayment and reclaim); (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he enters a real couple, runs buy-vs-rent, and reads a trustworthy forecast. **No hardcoded client data in the repo.** Possible free public release later.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (A, B, C in docs/build/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
Single source of truth: the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to and from these). Full field lists: [DATA-MODEL.md](DATA-MODEL.md) + docs/build/PLAN.md. Conventions:
- **Money = integer pence**, never a float (held by `Money`, GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages derive from DOB + a reference date, never stored.**
- **All reported wealth is NET of everything secured on the home** (2026-07-08, widened 2026-09-06 by card 0045): `YearResult::totalWealth` = liquid + pension + home equity, where equity is the property less the mortgage AND less any Support for Mortgage Interest charge, NNEG-floored; every surface (Compare / results / PDF / CSV / Monte Carlo / assistant) inherits from that one definition.
- **Storage inversion (Phase B):** a base scenario stores raw builder **form-state** (`builder_state`, one `encrypted:array`) as the single source of truth; the engine `Household` + `HousingAction` DTOs are **derived** (`Scenario::toHousehold()` / `toHousingAction()` via `HouseholdAssembler`, no reverse-mapper). A what-if **child** holds no `builder_state`, only `parent_scenario_id` + a sparse encrypted `overrides` delta (value overrides, added rows stored whole, removed rows a `REMOVED` sentinel); `effectiveBuilderState()` = base overlaid with overrides.
- **One rebuild site per DTO.** `Household` is only ever copied through its private `copy()` behind `withPersons()` / `withPensions()` / `withExpenseProfile()` / `withCapitalReceipts()`, because seven sweep levers used to rebuild it positionally and a field added to the DTO but forgotten in a lever was silently dropped from every swept forecast. Guarded by `HouseholdWitherTest`, which enumerates the DTO's own properties by reflection. `ExpenseProfile` has the same private `copy()` and the same reflection guard (`ExpenseProfileWitherTest`, card 0041); `Property`, `Account` and `DcPension` are guarded by `AssetWitherTest`.

## Architecture / stack
- **Laravel 13.17** app at the repo root, on local **Postgres 18** (moved off SQLite 2026-07-09, see Decisions). **Fortify** auth + **Filament 5** admin (which pulled **Livewire 4**). Front end is hand-rolled Livewire 4 full-page components (`app/Livewire/`) + **ApexCharts** (progressive enhancement: every figure is also text, an accessible `<table>` and CSV).
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked). Zero Laravel deps, no I/O, no clock. This is the product; the app is a shell. Must never `use App\...` or `Illuminate\...` (guarded by `EngineIsolationTest`).
- Money is hand-rolled integer pence. PHPUnit 12. `phpspreadsheet` is an app-layer dependency (`.xlsx` import only). A CSP and hardening headers ship on the `web` group (`config/security.php`); Filament `/admin` is out of scope. `script-src` is **nonce-based** (minted per request, handed to the Vite helper, which Livewire reads back); `'unsafe-eval'` is the one remaining relaxation, because Livewire 4's bundled Alpine evaluates through the Function constructor.

## Key files / structure
A map, not an inventory. Browse the tree for the rest; per-file rationale lives in each file's docblock.
- `packages/finance-engine/src/` — `Money/`, `TaxYear/`, `Tax/`, `Pension/`, `StatePension/`, `Property/`, `Benefits/`, `Iht/`, `Care/`, `Protection/`, `Dto/`, `Assumptions/`, `Benchmark/`, `Mortality/`, `Forecast/` (`PathProjector` + `DeterministicForecaster` + `YearResult`), `MonteCarlo/`, `Housing/`, `Sweep/` (decision-support spine and levers).
- `app/` — `Forecast/`, `DecisionSupport/`, `Assistant/`, `Import/`, `Livewire/`, `Compliance/`, `Models/`, `Http/`, `Jobs/`, `Filament/`, `Export/`.
- The seams a fresh session must not re-derive:
  - **`PathProjector`** is the hot loop and the cross-cutting contention point. Read its year-order before editing it.
  - **`ResultPresenter`** is the one place a figure becomes a screen string; the PDF calls the same methods, so print cannot drift from screen.
  - **`LadderContext`** resolves which housing strategy is on display. `deterministic()` ignores the stored variant, which has caused the same bug three times: read a sell plan through the variant path, never the raw household.
  - **`ScenarioForecaster::assumptions()`** is the only place assumption overrides are applied.
  - The **results page is tabbed** (`?tab=`, resolved server-side in `ScenarioResults::mount()`, defaulting to the verdict). `ScenarioResults::TABS` owns the tab set and the view's `$sections` array owns section → tab → nav label; a section outside the active tab is rendered and hidden, never skipped, so its table and CSV twin stay in the page. Add a section and its `$sections` entry together.
  - **`config/advice.php`** holds the advice fee deliberately outside `AssumptionSet`: the forecast never charges it, it only prices a comparison.
- House style is Pint: `vendor/bin/pint --dirty`.

## Decisions locked
Full log and rationale: [DECISIONS.md](DECISIONS.md). The load-bearing "do not relitigate" anchors:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI; any first-run sample must be obviously fictional.
- **App DB is Postgres 18** (moved off SQLite 2026-07-09 to fix the queued-Monte-Carlo reproducibility bug: SQLite could not handle the `database` queue driver's concurrent access). **Tests still run on in-memory SQLite** (phpunit.xml).
- **Regulatory posture: education/guidance-only** is the **public** stance, **currently relaxed for personal use.** `config('compliance.personal_use')` (default true) is the flagged "regulatory line", turning the walled-off advice `interpret` capability on for everyone. The suite runs with it **true**; `BannedPhrasingTest` is posture-aware (skips in advice mode, fully enforces when false). **Set `COMPLIANCE_PERSONAL_USE=false` before any public release**; `php artisan compliance:advice-audit` lists advice spots.
- **Engine is framework-free** in a path package; **money = integer pence**; savings and dividends in one combined income-tax pass; **tax figures versioned per tax year with source and verified-on** (frozen to April 2031).
- **All wealth reported NET of the mortgage and of any SMI charge** (2026-07-08, widened 2026-09-06).
- **UI = hand-rolled Livewire 4** (Filament admin-only); form input maps to engine DTOs via the unit-tested `HouseholdAssembler`.
- **No invisible figures.** Any engine-side default reaching a projection is disclosed with its value and why it applies, reading the constant that owns it rather than restating it. `php artisan scenarios:audit` sweeps every stored scenario for correctness and correct disclosure, and exits non-zero so it can gate a release.
- **The Monte Carlo has a golden master, and re-pinning it is a decision** (2026-09-05, card 0039). `MonteCarlo\GoldenMasterTest` pins one frozen run to the penny, so any change to draw ordering, projector arithmetic or a default assumption reddens it. Expect it red beside your next `ENGINE_VERSION` bump: re-pin the values, bump the test's `PIN_REVISION`, and add the DECISIONS.md entry its companion test then demands. Never widen or delete it to get green.

## Current state
- **Done:** the tool is feature-complete for personal use. The inventory of what that covers was
  folded out to [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) on 2026-09-05; scope lives in
  [docs/build/PLAN.md](build/PLAN.md). The one exception still worth carrying: the adviser-parity
  sweep is closed bar A4 salary sacrifice, B3 the estate checklist and B4 the annual review. B5
  capacity for loss is built and under review on card 0011.
- **In progress:** nothing mid-edit.
- **Known bugs / broken:** a reviewed defect backlog in [docs/board/todo/](board/todo/); do not restate it here, read the lane. It came from a five-discipline expert review on 2026-08-19, whose full report, with the private figures the cards omit, is the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`. **Several of those defects change which plan the comparison ranks first**, so the ranked chart and card 0022 should not be read off until the head of the queue is cleared.
Documented v1 scope limits remain flagged in code and listed in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" (for example Scotland income tax throws rather than guessing; emergency tax models the over-deduction magnitude, not PAYE-table pennies).
- **Private data in tracked files fails the suite** (card 0043, `NoPrivateDataTest`, a hashed denylist). Cards 0022 and 0023 are exempt in its `PENDING_SCRUB` until card 0174 scrubs them. Private figures go in the gitignored captures, and tracked docs point at them by card number.
- **Live carry-over:** the real couple's data is captured privately in the gitignored `docs/SCENARIO-V2.local.md`, which is the durable source to rebuild from after a DB wipe. **Read it before touching any V2 figure.** What each broker has actually offered, with dates and sources, is in the gitignored `docs/HOUSING-OFFERS.local.md`; the stored mortgage scenarios are priced off it.

## What's next (in order)
**The queue is [docs/board/todo/](board/todo/), one card per file.** Do not restate it here. At the head:

Re-run every stored scenario and `scenarios:audit` before reading the ranked comparison off, and
before card 0022 is answered. The queue resumes at the head of [docs/board/todo/](board/todo/).

## Blockers / open questions
**The full set is [docs/board/human-review/](board/human-review/), each card carrying its own ask or
its own options and a recommendation.** Thirteen cards are waiting on Rob. Do not restate them here.
What a fresh session needs to know:

- **0022 keep the flat or sell, is now explicitly on hold.** The 2026-08-19 review added direction: three reviewers, separately, said the two leading options are inside noise of each other while several unpriced items are each larger than the gap. Four things must land first, all carded. Do not push for an answer.
- **Six new cards are real-world actions, not modelling questions** (0066 to 0071): powers of attorney before any deed is signed, the State Pension figure that decides the whole benefits picture, a lease valuation, a formal property valuation plus the lender's position, the managing agent's accounts, and a quote for the borrowing the winning plan assumes. Several engine cards are blocked on their answers.
- **0001 browser sign-off on the built cluster** still gates acceptance: everything built since 2026-06-29 is proven by tests and numeric audit but has never been looked at in a browser.
- The rest (0004, 0005, 0006, 0008, 0021) are unchanged and carry their own recommendations.

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path). **Run php / artisan / composer / npm via PowerShell** (PHP 8.4 is Laravel Herd, not on the Git Bash PATH). Bash is fine for git, grep and file ops. See CLAUDE.md.
```powershell
Set-Location "C:\Dev\RetireForecast"
php artisan test                     # full suite, must be all green (red = stop and fix)
php artisan test --testsuite=Engine  # engine only
php artisan scenarios:audit          # every stored scenario: figures AND their disclosure
vendor/bin/pint --dirty              # house style on changed files
npm run build                        # build assets (public/build is gitignored)
```
- **App DB is Postgres 18** (`.env` `DB_CONNECTION=pgsql`, db `retireforecast`, `127.0.0.1:5432`; the old sqlite line is commented for revert). Fresh machine: create the db, then `php artisan migrate --seed`. Tests run on in-memory SQLite regardless.
- **Herd serves the app at `https://retireforecast.test`** (no `php artisan serve` needed). Run `npm run build` after asset changes. Using HMR (`npm run dev`) needs `SECURITY_HEADERS_ENABLED=false`, because the CSP omits the Vite dev origin.
- **Queue worker** (needed for full runs, thresholds, the trade-off map and assistant answers). Start it **fresh from the project root** with JIT (about twice as fast, byte-identical):
  ```powershell
  php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work
  ```
  **A worker started before the 2026-07-09 Postgres move polls the old SQLite jobs table and never processes Postgres jobs, so kill and restart it.** The synchronous preview (1 path) needs no worker.
- **Admin `/admin`** gated on `is_admin` (`php artisan user:make-admin {email}`). **Assistant** is inert unless `ASSISTANT_ENABLED=true` (needs Ollama with `qwen3:14b` plus the worker; build the doc index once with `php artisan assistant:index-docs`, re-run after editing a curated methodology doc). Register at `/register` and accept the `/welcome` disclaimer; 2FA at `/account/security`. Demo preset: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`.
- **Machine config (not in repo):** a per-site Herd nginx conf raises this site's gateway timeout to 300s (`~/.config/herd/config/valet/Nginx/retireforecast.test.conf`).
- **The family's live copy is NOT this PC** (DECISIONS 2026-10-21). It runs at `https://retire.craig.ooo` on ubuntu-web: code `/opt/retireforecast`, database `retireforecast` on the `db` LXC, worker `retireforecast-queue.service`, scheduler `/etc/cron.d/retireforecast`. Its data has split from this PC's, so a scenario edited here never reaches the family. Deploy: copy the tree with assets already built (ubuntu-web has no node), then `composer install --no-dev`, `php artisan migrate --force`, `php artisan optimize`, `sudo systemctl restart retireforecast-queue`. The dev copy is `~/dev/RetireForecast` on ubuntu-dev, database `retireforecast_dev`. Server setup: SecondBrain `Home Network.md`, "Public names".
- **This PC's database has migration `2026_08_29_100000_add_hashes_to_simulation_runs_table` pending.** Run `php artisan migrate` before trusting `scenarios:audit` here.

## Suggested skills / next tools
- **`/handover resume`** — the pick-up path. Reads this doc, then the board, then starts the head card.
- **`/handover save`** — wrapping up. Moves cards first, edits this doc second.
- **`/checkpoint`** — update the doc set and commit without a full handover pass.
- **`php artisan scenarios:audit`** — run before and after any engine change, and before looking at a screen. Eight checks over every stored scenario, non-zero exit so it can gate a release.
- **`php artisan compliance:advice-audit`** — the standing inventory of advice-mode spots, needed before any public release. `--strict` exits non-zero, which is the pre-release gate form.
- **`npm run a11y`** (public pages, also in CI), **`npm run a11y:auth`** (the signed-in pages, including `/account/security` in all three of its two-factor states, which it enrols into and back out of) and **`npm run a11y:focus`** (WCAG 2.2 2.4.11: nothing focusable hidden under the assistant overlay). The last two need the app served and a demo scenario. See [docs/spec/A11Y.md](spec/A11Y.md), whose table records where each unautomatable 2.2 criterion stands.
- **ProgressBoard** at `C:\Dev\ProgressBoard` — renders this board (and every other project's) ordered by what is waiting on Rob, and moves cards by `git mv` plus a commit. `php artisan serve --port=8737`, or Herd at `progressboard.test`.
- **`/code-review`** — for a working diff. `/security-review` before any public release.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| [docs/board/](board/) | **What is moving.** One card per task; the folder it sits in is its state. |
| [docs/build/PLAN.md](build/PLAN.md) | The full approved plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| [DATA-MODEL.md](DATA-MODEL.md) | Canonical data shape; materialised-vs-planned; "Known divergences" (the full v1-limit list). |
| [DECISIONS.md](DECISIONS.md) | Append-only decision log with rationale. |
| [PRD.md](PRD.md) | Goal, success criteria, scope, non-goals, open questions. |
| [CLAUDE.md](../CLAUDE.md) | Root orient tripwire, build/test conventions, doc-hygiene rules. |
| [docs/spec/METHODOLOGY.md](spec/METHODOLOGY.md) | User-facing methodology and "what we don't model" (also the `/methodology` page and the assistant corpus). |
| [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) | Every economic assumption with its source and verified-on date. |
| [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) | The per-feature build record, out of the load path. |
| [docs/build/SESSION-LOG-ARCHIVE.md](build/SESSION-LOG-ARCHIVE.md) | The dated prose session log, archived 2026-08-01. |
| docs/SCENARIO-V2.local.md | **GITIGNORED / PRIVATE:** the real couple's data and core scenario, to re-model after a DB wipe. **Read before touching any V2 figure.** |
| docs/HOUSING-OFFERS.local.md | **GITIGNORED / PRIVATE:** every mortgage / equity-release offer actually made, with lender, rate, date and the email it came from. The source the mortgage scenarios are priced off. |
| docs/BENEFITS-CHECK-V2.local.md | **GITIGNORED / PRIVATE:** full benefits check for the couple. |
| docs/REVIEW-PANEL-2026-08-19.local.md | **GITIGNORED / PRIVATE:** the five-discipline expert review in full, with the figures the tracked cards omit. The source for cards 0024 to 0071. |
| docs/build/PLAN-*.md, docs/research/RESEARCH-*.md | Per-feature specs, build records and research. |

## Branch status
On `master`. GitHub remote `origin` is github.com/RobertLCraig/RetireForecast. **Pushing to `master` is gated and needs Rob's explicit go-ahead.** Otherwise commit directly to `master` (personal local-first project, no PR flow). **Re-check `git status` and `git log` before any commit or push: this tree is sometimes shared by two concurrent sessions.** The pre-rebuild prototype is tagged `prototype-v1` (a8f1f68).

## Session log
Not kept here. The narrative is the commit history and the rationale is the decision log:

```bash
git log --format='%ad %s%n%b'      # what happened
```

Older prose sessions are archived at [docs/build/SESSION-LOG-ARCHIVE.md](build/SESSION-LOG-ARCHIVE.md), out of the load path.
