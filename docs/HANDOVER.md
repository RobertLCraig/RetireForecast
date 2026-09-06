# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. **This doc holds what is true; [docs/board/](board/) holds what is moving.** Read [docs/build/PLAN.md](build/PLAN.md) for the full approved plan and scope.

**Stage:** active
**Category:** site
**Status:** **Feature-complete for personal use, and now carrying a large reviewed defect backlog.** The engine, the app, the post-v1 enhancement backlog, decision-support (Phases 0 to 6), the local assistant, IHT and the care means-test are all built. A five-discipline expert review on 2026-08-19 found defects across all of them, several of which change which plan the comparison ranks first. What remains is that backlog, Rob's **browser sign-off**, and the **public-release blockers**.
_Last updated: 2026-09-06 (card 0050). The exceptions a fresh session needs, newest first. The "what is built"
inventory and cards 0024, 0025, 0028 to 0032 were folded out to
[docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) to keep this loadable in one session:_

- **A disability award is now two components, and a care placement treats them apart.** Card 0050.
  `IncomeStreamType::DisabilityBenefit` is the CARE (daily living) component and keeps its stored
  value, so an award entered before the split reads wholly as care, the adverse reading on both
  sides; `DisabilityBenefitMobility` is the new sibling, forced tax-free by the same assembler line.
  `Benefits\DisabilityBenefitInCare` owns the 28-day stop. `PathProjector` settles funding status
  once a year in `disabilityCareComponentFractions()`, before any income is assembled, and three
  places read that one answer: the tax-free income banked, the Pension Credit severe-disability and
  carer additions, and the care charge (which now adds the care component to `assessableAnnualIncome`
  and never the mobility one). Funding status comes from the resident's own capital at the year's
  OPEN, through the same `CareMeansTest::assess()` self-funder line, because deriving it from the
  charge is circular. **Every stored plan holding a tax-free disability income AND a modelled care
  spell moves**, in either direction depending on which side of the assessment it lands; a plan with
  no disability income, or whose paths never reach care, is byte-identical, which is why
  `GoldenMasterTest` did not redden. `ENGINE_VERSION` is `finance-engine/disability-award-split-in-care`
  and the **stored-scenario re-run is owed**. **Both rules are STATED, not verified** (no web in this
  session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§25), carded as **0118**. Built in a
  worktree, so the new builder option and the new result note **have not been seen in a browser**.
- **Moving a large sum is now warned about as a deprivation question.** Card 0049.
  `Benefits\Deprivation` is the one home for the copy (the benefits notional-capital rule, the care
  deliberate-deprivation test in Annex E, neither with a time limit, plus the benefits-check
  pointer and the gift-with-reservation-of-benefit trap). `PathProjector` raises
  `WarningCode::CAPITAL_DEPRIVATION` on any year a move clears the £16,000 capital limit uprated to
  that year, reading `housingSupportUpperCapitalLimit` rather than a figure of its own: a pension
  lump sum or withdrawal, capital received, a labelled one-off cost (which is how a gift out is
  entered), or a forced sale. A **year-0 sale is invisible to the projector** (`HousingComparison`
  hands it a household that already holds the proceeds), so `ResultPresenter` raises that one
  itself and it takes precedence; the note is reported once, on the earliest move. The same copy
  rides the lump-sum panel's existing warnings list and the equity-release roll-up note. **No
  `ENGINE_VERSION` bump and no stored re-run is owed:** it is a warning, no figure moves. Built in
  a worktree, so the new note and the two new warning lines **have not been seen in a browser**.
  Two things it did not do: `docs/spec/METHODOLOGY.md` still does not mention deprivation, and the
  care panel carries no warning of its own (the rule is stated inside the message instead).
- **A pension-age renter is finally awarded Housing Benefit, and property capital is valued net of
  the costs of selling it.** Card 0048. The engine paid Guarantee Credit and nothing else, so a
  sell-and-rent plan met its whole rent for life in exactly the tail where a plan is judged to run
  short, while the buy-outright leg had no equivalent omission. `Benefits\HousingBenefit` owns the
  pension-age rules and is built to the same shape as `Benefits\CouncilTax`: the whole eligible
  rent on Guarantee Credit, otherwise the rent less `TAPER_BPS` (65%) of every pound of weekly
  income above the Pension Credit guarantee, nil above the £16,000 capital limit. The award comes
  OFF the rent and is never credited as income; `YearResult::housingBenefit()` reports it and the
  gross rent still drives the deposit and referencing warnings. Alongside it,
  `CapitalAssessment::propertyCapital` values property capital at market value less
  `NOTIONAL_SALE_COSTS_BPS` (10%) and then less the secured debt, in that order, which is the one
  definition the benefits means test now reads. **Every stored sell-and-rent plan that reaches a
  qualifying year was too pessimistic, and a plan with a let home moves through its Pension Credit
  award**; a plan that never rents and holds no let property is byte-identical. `ENGINE_VERSION` is
  `finance-engine/pension-age-housing-benefit` and the **stored-scenario re-run is owed**. Built in
  a worktree, so the two new result notes **have not been seen in a browser**. **Both statutory
  figures are STATED, not verified** (no web in this session) and both reach a projection: see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§24), carded as **0113**. Three things the card
  did NOT close: **0114**, no Local Housing Allowance cap on eligible rent, which makes every award
  the optimistic end; **0115**, the care means test still values property without the sale-costs
  deduction, so one quantity now has two definitions; and the card's own criterion #2, the
  sale-proceeds disregard, which is left open because no state in this engine holds proceeds with
  an intention to buy: `HousingAction` carries no date, so the year-0 rebuy is instantaneous and
  cannot be otherwise. That gap is now card **0116**, which opens on whether a gap between selling
  and buying is worth modelling at all, because that call is Rob's.
- **Council tax is its own cost line and it now shrinks.** Card 0047. It sat inside
  `Property::runningCosts` beside maintenance and insurance, and was charged at the full couple's
  rate for the whole projection. `Property::$annualCouncilTax` holds it apart, `Property::$disabledBandReduction`
  holds the band it is claimed from, and `Benefits\CouncilTax` owns the three reliefs and the order
  they apply in: the disabled band reduction lowers the liability (charged as the band below,
  `Dto\CouncilTaxBand` owning the statutory ninths), the 25% single-person discount comes off what
  is left from the first death, and pension-age Council Tax Reduction meets the rest on a taper of
  20% of income above the applicable amount, passported in full on Guarantee Credit and nil above
  the £16,000 capital limit. The applicable amount is the Pension Credit one the engine already
  computes, which the card directed. `YearResult::councilTax()` reports what was charged and the
  presenter reads it. Council tax is deliberately NOT scaled by the ownership share: it is charged
  to whoever LIVES there. **No `ENGINE_VERSION` bump and no stored re-run is owed:** a null bill is
  byte-identical, and every stored scenario has one, so nothing moves until a reader splits the bill
  out. The `council_tax_bundled` note is what tells them to. **All four statutory figures are
  STATED, not verified** (no web in this session) and all four reach a projection: see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§23), carded as **0111**. Built in a worktree, so
  the two new builder inputs and the two new notes **have not been seen in a browser**. The gap this
  did NOT close is card **0112**: a sell-and-rent plan is still charged no council tax at all, which
  flatters renting in the one comparison the tool exists to run.
- **Pension Credit is no longer counted as guaranteed, and two disclosures that never fired now
  fire.** Card 0046. `SECURE_SOURCES` had listed `means_tested_benefit` beside the State Pension, so
  the readout answering "are my essentials covered for life" counted money that has to be claimed,
  that around a third of eligible pensioner households never claim, and that moves with income,
  capital, circumstances and a review. It moves to a sibling `CONTINGENT_SOURCES` and is reported in
  full in a panel of its own (results page and PDF), outside every total the floor reports. The
  claim prompt now opens on PROXIMITY as well as on an award: `PensionCreditResult::isNearMiss()`
  owns the rule, `NEAR_MISS_MARGIN_BPS` owns the figure (10% of the guarantee, a judgement with no
  published source, deliberately not a builder control, written up at
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) section 22), and `PathProjector` raises a
  `PENSION_CREDIT_NEAR_MISS` warning the presenter quotes. The capital-cliff warning
  `CapitalAssessment` had always built, and whose only caller read the tariff and discarded the
  object, is collected at last: assessed on the SAME capital the tariff was, surfaced once as an
  input note naming the first year it bites. `assess()` takes `$onGuaranteeCredit` and reports no
  cliff for a household on the credit, which is passported with no upper capital limit. **No
  `ENGINE_VERSION` bump and no stored re-run is owed:** both new flags are warnings and
  `housingSupportEligible` had no reader but the warning. Built in a worktree, so the contingent
  panel, the rewritten prompt and the cliff note **have not been seen in a browser**. The gap this
  did NOT close is card **0110**: the forecast still credits Pension Credit whether or not anybody
  claimed it, which for an unclaimed award overstates income and, since card 0045, quietly pays
  Support for Mortgage Interest too.
- **The cheapest borrowing a pensioner can get is finally in the engine.** Card 0045. Support for
  Mortgage Interest appeared nowhere in the code, the config or the board, while the tool's whole
  subject is an unaffordable secured debt in later life and its comparison already prices lifetime
  mortgages at roughly three times the rate. A household on Guarantee Credit that still owns its
  home now has its mortgage interest met at the DWP standard rate on capital up to the pension-age
  cap (both on `Benefits\SupportForMortgageInterest`), plus its service charge and ground rent in
  full, less the utilities part. It is a LOAN, so nothing is credited as income: the amount met
  comes off the year's spending and the SAME figure is added to `state['smiBalance']`, a second
  charge secured on the home that rolls up and is redeemed from the proceeds of a forced sale or
  out of the estate at death. `YearResult::smiBalance()` reports it and `homeEquity()` nets it, so
  the wealth line cannot flatter a household whose home is being spent. The interest met is capped
  at the interest ACTUALLY charged that year, so a rolled-up lifetime mortgage gets nothing (there
  is no liability to meet). **Every stored plan that reaches a Guarantee Credit year while it still
  owns a home spends too much under the old stamp, so its wealth, depletion year and success odds
  are too pessimistic and its estate too high**; a plan that never qualifies, or has sold by then,
  is byte-identical. `ENGINE_VERSION` is `finance-engine/support-for-mortgage-interest` and the
  **stored-scenario re-run is owed**. No new builder input; the new results note has **not been
  seen in a browser**. **Both figures behind the arithmetic are STATED, not verified** (no web in
  this session) and unlike card 0044's they DO reach a projection: see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§21), carded as **0109**. Whether the household
  would in fact take a charge on its home is not modelled; the card scoped the gate to a Guarantee
  Credit year and the note says so.
- **A disability benefit can now start at an age, the carer flag is finally on a screen, and
  claiming Attendance Allowance is a one-click what-if.** Card 0044.
  `Person::$receivesDisabilityBenefit` was on or off for life, so the largest favourable event a
  long survivor period can carry could not be entered; `caresForPartner` had been wired into the
  Pension Credit carer addition since July 2026 with no way to set it. `Person` now carries
  `disabilityBenefitFromAge`, and `receivesDisabilityBenefitAt($age)` is the single place the flag
  and its start age are read together (the projector uses it for the severe-disability count AND the
  carer test, so a partner's later claim delays the carer addition too). **No `ENGINE_VERSION` bump
  and no stored re-run are owed:** null means the whole projection, so every stored scenario is
  byte-identical. New: a `claim_attendance_allowance` quick what-if that sets the flag AND adds the
  benefit's own tax-free income stream (both halves, because either alone models half the event); a
  `disability_benefit_passports` result note naming what the forecast does not model (Support for
  Mortgage Interest, Council Tax Reduction, the Warm Home Discount, the TV licence, Cold Weather
  Payments, NHS costs); and a warning beside the retirement-age lever that earnings above the
  Carer's Allowance limit block the carer addition. **Three benefit figures are STATED, not
  verified** (this session had no web): the 2026/27 Attendance Allowance pair is derived by the same
  uprating rule as the file's Pension Credit additions, and the earnings limit applies the 16-hours
  at National Living Wage rule. See [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§20), carded as
  **0106**. Two faults carded rather than fixed: **0107**, `Person` is rebuilt by hand in three
  places with no reflection guard (the `ProtectionGap` one would have dropped the new field, and is
  fixed here); and **0108**, the projector still awards the carer addition to a carer earning far
  above the limit, which this card made reachable. Built in a worktree, so the two new builder
  inputs, the new what-if button, the note and the lever warning **have not been seen in a browser**.
- **A queued run can no longer be killed at 60 seconds or run twice at once, and the forecaster
  remembers what it derived.** Card 0042 (partial: its third criterion is left open and is Rob's,
  see the card). `RunScenarioSimulation` and `RunLeverThreshold` declare an hour's `$timeout`, one
  attempt and `WithoutOverlapping` on their record id; `config/queue.php` `retry_after` defaults to
  **3900** rather than Laravel's 90, which is what stopped the queue offering a still-running job to
  a second worker (`.env` names no `DB_QUEUE_RETRY_AFTER`, so only the config default reaches this
  machine). None of it was visible here because **Windows has no `pcntl`** and a worker cannot
  enforce a timeout at all. `ScenarioForecaster` now memoises per request (`scoped` in
  `AppServiceProvider`), keyed on the scenario's stored **ciphertext** plus its parent chain and its
  assumption set WHOLE, because an edit inside a shared `AssumptionSet` moves a scenario whose own
  row never changes. No figure moves and no `ENGINE_VERSION` bump. **Its third criterion is blocked
  on Rob**: the affordability screen has no in-place re-render (its one action redirects, and
  Livewire skips the render on a redirect), so every render is a fresh request with an empty memo,
  and closing it needs the cross-request cache the card itself excludes. Measured, so the call has a
  number: one affordability row costs about 64 ms cold and about 51 ms warm, because the
  sustainable-spend bisection is not memoised, so a second render of twenty plans costs about 1.3 s.
  Raised alongside: card **0104**, `BuildScenarioExport` still carries no overlap lock, and card
  **0105**, `SustainableSpend` and `AdviceCostComparison` rebuild by hand what the memo already
  holds.
- **A forced sale now redeems the mortgage it actually owes, and four backstops that invented an
  answer now throw.** Card 0041. `PathProjector` handed `HousingProceeds::compute()` the mortgage
  balance as ORIGINALLY ENTERED, which is right only for an interest-only loan: a lifetime mortgage
  has rolled up by the sale year (the plan freed equity it no longer had) and a repayment mortgage
  has amortised down (it freed less than it keeps). The balance is now the one in force that year,
  derived from `state['mortgageOutstanding']` by scaling back up through the ownership share rather
  than tracked as a second state key, so the balance keeps one definition. **Every stored plan with
  a forced sale on a rolled-up or amortising loan moves**; interest-only and no-forced-sale plans
  are byte-identical. `ENGINE_VERSION` is `finance-engine/forced-sale-redeems-the-years-balance` and
  the **stored-scenario re-run is owed**. No screen changed. Alongside it: `ExpenseProfile` gets the
  private `copy()` its three hand-listed withers lacked (`withoutPropertyCosts()` had already lost
  `propertyCostsRealGrowth`, inert only because a zero bucket cannot escalate);
  `BuilderStateDelta::setPath()` no longer turns a positional list into an id-keyed map when a
  what-if adds the FIRST row to a list its base leaves empty; and five silent failures are loud
  (`report($e)` in all three queued runners, plus throws in the year-200 projection backstop,
  `SampledPathDraws::at()` past the end of a series, `disposeGiaSlice()` on an empty holding, and
  `Scenario::effectiveBuilderState()` on a `parent_scenario_id` cycle, which used to exhaust memory
  and kill the worker).
- **Liquid wealth no longer lands on whoever was typed first, and the care answer no longer moves
  with typing order in the year care starts.** Card 0040. Three places handed money to `persons[0]`
  or `firstLiving()`: the year-0 sale proceeds in `HousingComparison::withHousing()`, the forced-sale
  proceeds in `PathProjector`, and every year's banked surplus. The care means test is deliberately
  INDIVIDUAL, so the second-declared person reached care with an empty balance sheet. A jointly held
  home now splits EQUALLY between the members (the DTO has no per-person share, and equal is the rule
  `careAssessableCapital()` already used), and a surplus is banked in proportion to the net income
  each living member produced, evenly where nothing produced it. The one home of all three is the new
  `Money\PenceSplit`, whose leftover pennies go to the lowest person id rather than the first
  declared, so nothing turns on order. **Every stored plan with TWO people moves wherever the split
  changes a per-person allowance or assessment** (care, Pension Credit, the CGT annual exempt amount,
  the savings and dividend allowances); a one-person household is byte-identical. `ENGINE_VERSION` is
  `finance-engine/liquid-wealth-split-between-owners` and the **stored-scenario re-run is owed**
  (built in a worktree). No screen changed. `GoldenMasterTest` did NOT redden and needs no re-pin:
  its frozen household stays put, never sells, and is in drawdown from year 0, so it banks no
  surplus. **The card's third criterion is NOT met and is left open**: the funding waterfall still
  SPENDS the first-declared person's accounts first, so a care spell paid for by drawing down still
  moves with typing order after its first year. That is card **0101**. The adjacent gap, that a
  couple owning 70/30 cannot say so, is card **0102**. See DECISIONS 2026-09-05.
- **How long the State Pension triple lock lasts is now a choice, and three engine defaults that
  reach every projection are on the screen.** Card 0038. `growState` used to raise the State Pension
  by `max($infl, 0.025)`: no source, no setting, no control, nothing on any screen. Because
  inflation is modelled near 2%, that floor binds in most years, so the State Pension grew in REAL
  terms for the whole plan and the Pension Credit guarantee, uprated by the same running factor,
  rose with it. The rule now lives on `StatePension\StatePensionUprating` (an enum owning
  `TRIPLE_LOCK_FLOOR_BPS`, whose `increase()` mirrors `PensionEscalationBasis::increase()`); the
  choice rides `ForecastSettings` beside the other policy toggles rather than `AssumptionSet` (the
  card's Task said the set, its comment thread says why not), and the reader picks the full lock,
  the lock ending in a year they name, or prices alone. Alongside it `assumedFigures()` discloses
  the **portfolio allocation** (nothing ever passed one, so every projection has run on a cautious
  40/60 nobody was shown) and **every care assumption**; `inputNotes()` and `assumedFigures()` now
  take the run settings, and the results page, the PDF and `scenarios:audit` all pass them.
  **No `ENGINE_VERSION` bump and no stored re-run owed**: the default reproduces the old rule and
  every stored figure is byte-identical. Built in a worktree, so the new builder control and the
  three new notes **have not been seen in a browser**. Two things were carded, not settled: **0099**,
  the default (the full lock) is the OPTIMISTIC branch where the standing rule is to default
  adverse, and moving it moves every stored plan, so it is Rob's; and **0100**, only two of the
  lock's three limbs are modelled, because the engine holds no national earnings series and an
  unattended session cannot fetch one. See [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§19).
- **A pension withdrawal is now priced against the whole of the person's income, and a second draw
  in the same year starts where the first one finished.** Card 0037. `marginalTax` and
  `grossUpPension` took an int of non-savings income; they now take a `TaxableIncome`, fed by the
  per-person cash interest and GIA dividends `projectYear` already computes for its own tax pass.
  Savings and dividends stack ABOVE non-savings income, so a withdrawal pushes them across band
  boundaries and halves the Personal Savings Allowance, and none of that cost was reaching the bill.
  The card's second criterion, a to-the-penny reconciliation of the year's total tax against a full
  recomputation from final taxable income, forced a second fix in the same two functions:
  `fundShortfall` took `$taxablePerPerson` by value, so every pension pass after the first restarted
  from the PRE-drawdown figure (PensionAware draws twice, FillBands three times, plus the CGT
  top-up), pricing and capping later draws in a band the member had already left. A new
  `$drawnTaxable` running total carries it forward, held apart from `$taxablePerPerson` so the CGT
  band split and the means test keep reading the pre-drawdown income they were assessed on. The
  band-filling CAPS stay on non-savings income deliberately: which band the pension fills is the
  strategy's question, not a tax one. **Every stored plan that both holds unwrapped savings or
  shares and draws a pension to meet its spending paid too LITTLE tax, so its wealth, depletion year
  and success odds are too FAVOURABLE**; a plan whose taxable accounts are all ISAs and which never
  draws is byte-identical. `ENGINE_VERSION` is `finance-engine/drawdown-marginal-tax-on-full-income`
  and the **stored-scenario re-run is owed** (built in a worktree). No screen changed, so there is
  nothing new to look at. `DrawdownMarginalTaxTest` is the reconciliation guard and it runs under
  PensionAware, because under FillBands the reported `pension_drawdown` includes the tax-free quarter
  (card 0074) and a test cannot recover the taxable split from `incomeBySource`. The adjacent fault
  is card **0098**: `capitalGainsTax` still bands a realised gain against pre-drawdown,
  non-savings-only income, so the household that sells holdings to fund a withdrawal has its gain
  charged at the lowest rate.
- **The retirement year is now split on both sides, and National Insurance no longer stops early.**
  Card 0036. Salary was already prorated by `workFraction`, but the income replacing it was not: a
  State Pension paid a full year from the claim year, a DB pension a full year from normal retirement
  age, and `niForPerson` switched NI off for the whole calendar year State Pension age fell in.
  `initialState` now keeps `spaMonth` beside `spaYear` (it was computing the date and discarding the
  month), and `startFraction($month)` sits beside `workFraction` as its exact complement: month n
  divides the year at the end of that month, salary takes n/12 and what replaces it takes (12 - n)/12.
  NI is charged on `min(workFraction, spaMonth/12)` of the salary, with the calculator's own State
  Pension age switch off, because the slice handed to it already excludes everything after that date.
  `dbIncome` now takes the `Person`, not the id, since it needs the birth month. **Every stored plan
  with a retirement inside its horizon banked too much income and too little NI in that year, so its
  wealth, depletion year and success odds are too FAVOURABLE**; a plan whose members are all past
  State Pension age and normal retirement age in the base year is byte-identical. `ENGINE_VERSION` is
  `finance-engine/transition-year-proration` and the **stored-scenario re-run is owed** (built in a
  worktree). No new UI control, so nothing new to look at, but every results page moves. Two adjacent
  faults were carded rather than fixed: **0096**, NI thresholds are annual where real NI is assessed
  per pay period, so a part year is charged against a whole year's threshold (noted as a v1 limit in
  `niForPerson`); and **0097**, the Pension Credit qualifying-age gate awards fifty-two weeks in the
  year State Pension age is reached, which this card makes worse in passing because the now-correct
  part-year State Pension lowers the assessable income the award is computed from.
- **The pension escalation dropdowns are no longer dead, and revaluation is a separate rule from
  escalation.** Card 0035. `PathProjector` ran one household-wide factor pinned to full CPI, so a
  scheme set to no increases, or to a capped basis, rose with prices for thirty years anyway. It now
  carries one factor PER scheme (`state['dbFactors']` / `dbSchemes`, keyed by the pension's position
  in the household list) and `escalateDbPensions()` picks the basis by phase: the revaluation basis
  while the member is deferred, the in-payment basis from normal retirement age. The rule itself
  lives on `PensionEscalationBasis::increase()`, which also owns the two statutory limited-price
  ceilings via `capBasisPoints()`; a new `cpi_capped_2_5` case covers post-2005 accrual, and the caps
  are floored at zero because a scheme does not cut a pension when prices fall. `DbPension` gains
  `fixedEscalationRate` (a builder input, blank = the disclosed `DEFAULT_FIXED_ESCALATION_BPS` of 3%).
  **Every stored plan whose scheme is not on plain CPI in BOTH phases moves**, and the direction
  depends on the choice: a frozen or capped pension was banking income nobody promised it, so its
  wealth, depletion year and success odds are too FAVOURABLE; a scheme on plain CPI throughout is
  byte-identical. `ENGINE_VERSION` is `finance-engine/db-escalation-per-scheme` and the
  **stored-scenario re-run is owed** (built in a worktree, so the two new builder controls **have not
  been seen in a browser**). Two figures ship as judgement with no fetched source, the RPI-over-CPI
  wedge (zero, on the reading that RPI aligns to CPIH from 2030) and the 3% fixed default; both are
  disclosed as assumed figures reading their own constants, written up in
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§18), and raised as card 0095.
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
  sweep is closed bar A4 salary sacrifice, B3 the estate checklist, B4 the annual review and B5
  capacity for loss, all of which are card 0011.
- **In progress:** nothing mid-edit.
- **Known bugs / broken:** a reviewed defect backlog in [docs/board/todo/](board/todo/); do not restate it here, read the lane. It came from a five-discipline expert review on 2026-08-19, whose full report, with the private figures the cards omit, is the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`. **Several of those defects change which plan the comparison ranks first**, so the ranked chart and card 0022 should not be read off until the head of the queue is cleared.
Documented v1 scope limits remain flagged in code and listed in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" (for example Scotland income tax throws rather than guessing; emergency tax models the over-deduction magnitude, not PAYE-table pennies).
- **Data hygiene is currently breached** (card 0043): private detail about the couple is in eleven tracked files, including this doc's own Blockers section historically. Cards written from 2026-08-19 carry no private figures and point at the gitignored captures instead. Keep it that way.
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
- **Share with family** (private, as-is): Tailscale Serve, not a public deploy (DECISIONS 2026-07-12 and 07-16). Set `APP_EXTERNAL_URL` in `.env` to this machine's `https://<name>.ts.net`, run `php artisan serve --port=8000` plus a queue worker, then `tailscale serve --bg 8000` (tailnet-only, auto HTTPS). The URL pin is host-conditional, so family traffic under the `*.ts.net` host gets pinned https URLs while local browsing at `retireforecast.test` keeps its own; both work at once with no env toggling. The `tailscale serve` config survives reboot; `artisan serve` and `queue:work` do not. Stop sharing with `tailscale serve --https=443 off`. Family log in with Rob's credentials (scenarios are per-user).

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
