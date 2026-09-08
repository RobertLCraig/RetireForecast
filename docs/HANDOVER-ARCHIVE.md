# HANDOVER archive — RetireForecast

> The detailed per-feature build record and older session-log narrative, moved out of
> HANDOVER.md on 2026-07-10 to keep the live handover small enough to load each session.
> Nothing here is load-bearing for picking up the project (the live HANDOVER.md carries
> that); this is the "how we got here" detail. Each item also has a dated DECISIONS.md
> entry and the full record in `git log`. Newest-first within each part.

## Card 0048, moved out of the live handover on 2026-09-08

Folded out to make room for card 0065 while keeping the live brief loadable in one session. It was
the largest block in the exceptions list that no longer changes how a fresh session works: the rule
is built and its four open gaps each carry a board card of their own (0113, 0114, 0115, 0116), which
is where a reader now meets them.

**A pension-age renter is finally awarded Housing Benefit, and property capital is valued net of
the costs of selling it.** The engine paid Guarantee Credit and nothing else, so a sell-and-rent
plan met its whole rent for life in exactly the tail where a plan is judged to run short, while the
buy-outright leg had no equivalent omission. `Benefits\HousingBenefit` owns the pension-age rules
and is built to the same shape as `Benefits\CouncilTax`: the whole eligible rent on Guarantee
Credit, otherwise the rent less `TAPER_BPS` (65%) of every pound of weekly income above the Pension
Credit guarantee, nil above the £16,000 capital limit. The award comes OFF the rent and is never
credited as income; `YearResult::housingBenefit()` reports it and the gross rent still drives the
deposit and referencing warnings. Alongside it, `CapitalAssessment::propertyCapital` values property
capital at market value less `NOTIONAL_SALE_COSTS_BPS` (10%) and then less the secured debt, in that
order, which is the one definition the benefits means test now reads. **Every stored sell-and-rent
plan that reaches a qualifying year was too pessimistic, and a plan with a let home moves through
its Pension Credit award**; a plan that never rents and holds no let property is byte-identical.
`ENGINE_VERSION` was `finance-engine/pension-age-housing-benefit` and the stored-scenario re-run it
owed is still owed. Built in a worktree, so the two new result notes have not been seen in a
browser. **Both statutory figures are STATED, not verified** and both reach a projection: see
[docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§24), carded as **0113**. Three things the card did
NOT close: **0114**, no Local Housing Allowance cap on eligible rent, which makes every award the
optimistic end; **0115**, the care means test still values property without the sale-costs
deduction, so one quantity now has two definitions; and the card's own criterion #2, the
sale-proceeds disregard, which is left open because no state in this engine holds proceeds with an
intention to buy: `HousingAction` carries no date, so the year-0 rebuy is instantaneous and cannot
be otherwise. That gap is card **0116**, which opens on whether a gap between selling and buying is
worth modelling at all, because that call is Rob's.

## Card 0038, moved out of the live handover on 2026-09-08

Folded out to make room for card 0061 while keeping the live brief loadable in one session. It is
settled: its own criteria are met, and its two open questions are board cards 0099 and 0100.

**How long the State Pension triple lock lasts is now a choice, and three engine defaults that
reach every projection are on the screen.** `growState` used to raise the State Pension by
`max($infl, 0.025)`: no source, no setting, no control, nothing on any screen. Because inflation is
modelled near 2%, that floor binds in most years, so the State Pension grew in REAL terms for the
whole plan and the Pension Credit guarantee, uprated by the same running factor, rose with it. The
rule now lives on `StatePension\StatePensionUprating` (an enum owning `TRIPLE_LOCK_FLOOR_BPS`,
whose `increase()` mirrors `PensionEscalationBasis::increase()`); the choice rides
`ForecastSettings` beside the other policy toggles rather than `AssumptionSet` (the card's Task
said the set, its comment thread says why not), and the reader picks the full lock, the lock ending
in a year they name, or prices alone. Alongside it `assumedFigures()` discloses the **portfolio
allocation** (nothing ever passed one, so every projection has run on a cautious 40/60 nobody was
shown) and **every care assumption**; `inputNotes()` and `assumedFigures()` now take the run
settings, and the results page, the PDF and `scenarios:audit` all pass them. **No `ENGINE_VERSION`
bump and no stored re-run owed**: the default reproduces the old rule and every stored figure is
byte-identical. Built in a worktree, so the new builder control and the three new notes **have not
been seen in a browser**. Two things were carded, not settled: **0099**, the default (the full
lock) is the OPTIMISTIC branch where the standing rule is to default adverse, and moving it moves
every stored plan, so it is Rob's; and **0100**, only two of the lock's three limbs are modelled,
because the engine holds no national earnings series and an unattended session cannot fetch one.
See [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§19).

## Card 0037, moved out of the live handover on 2026-09-07

Folded out to make room for card 0059 while keeping the live brief loadable in one session. It is
settled: its own criteria are met, and its one open adjacent fault is board card 0098.

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
  and the **stored-scenario re-run is owed**. No screen changed. `DrawdownMarginalTaxTest` is the
  reconciliation guard and it runs under PensionAware, because under FillBands the reported
  `pension_drawdown` includes the tax-free quarter (card 0074) and a test cannot recover the taxable
  split from `incomeBySource`. The adjacent fault is card **0098**: `capitalGainsTax` still bands a
  realised gain against pre-drawdown, non-savings-only income, so the household that sells holdings
  to fund a withdrawal has its gain charged at the lowest rate.

## Card 0036, moved out of the live handover on 2026-09-07

Folded out to make room for card 0054 while keeping the live brief loadable in one session. It is
settled: no open item hangs off it, and its two adjacent faults are board cards 0096 and 0097.

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
  State Pension age and normal retirement age in the base year is byte-identical. `ENGINE_VERSION` was
  `finance-engine/transition-year-proration` and the stored-scenario re-run was owed. No new UI control,
  so nothing new to look at, but every results page moves. Two adjacent faults were carded rather than
  fixed: **0096**, NI thresholds are annual where real NI is assessed per pay period, so a part year is
  charged against a whole year's threshold (noted as a v1 limit in `niForPerson`); and **0097**, the
  Pension Credit qualifying-age gate awards fifty-two weeks in the year State Pension age is reached,
  which this card makes worse in passing because the now-correct part-year State Pension lowers the
  assessable income the award is computed from.

## Card 0035, moved out of the live handover on 2026-09-07

Folded out to make room for card 0051 while keeping the live brief loadable in one session. It is
settled: its rationale is DECISIONS 2026-09-05, its two unsourced figures are board card 0095, and
they are written up in [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) §18.

> **The pension escalation dropdowns are no longer dead, and revaluation is a separate rule from
> escalation.** Card 0035. `PathProjector` ran one household-wide factor pinned to full CPI, so a
> scheme set to no increases, or to a capped basis, rose with prices for thirty years anyway. It now
> carries one factor PER scheme (`state['dbFactors']` / `dbSchemes`, keyed by the pension's position
> in the household list) and `escalateDbPensions()` picks the basis by phase: the revaluation basis
> while the member is deferred, the in-payment basis from normal retirement age. The rule itself
> lives on `PensionEscalationBasis::increase()`, which also owns the two statutory limited-price
> ceilings via `capBasisPoints()`; a new `cpi_capped_2_5` case covers post-2005 accrual, and the caps
> are floored at zero because a scheme does not cut a pension when prices fall. `DbPension` gains
> `fixedEscalationRate` (a builder input, blank = the disclosed `DEFAULT_FIXED_ESCALATION_BPS` of 3%).
> **Every stored plan whose scheme is not on plain CPI in BOTH phases moves**, and the direction
> depends on the choice: a frozen or capped pension was banking income nobody promised it, so its
> wealth, depletion year and success odds are too FAVOURABLE; a scheme on plain CPI throughout is
> byte-identical. `ENGINE_VERSION` was `finance-engine/db-escalation-per-scheme` and the
> **stored-scenario re-run is owed** (built in a worktree, so the two new builder controls **have not
> been seen in a browser**). Two figures ship as judgement with no fetched source, the RPI-over-CPI
> wedge (zero, on the reading that RPI aligns to CPIH from 2030) and the 3% fixed default; both are
> disclosed as assumed figures reading their own constants, written up in
> [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§18), and raised as card 0095.

## Cards 0033 and 0034, moved out of the live handover on 2026-09-06

Folded out to make room for card 0045 while keeping the live brief loadable in one session. Both are
settled: their rationale is DECISIONS 2026-09-05, their residue is board card 0094, and the figures
they introduced are in [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) §17.

> **A sold service charge no longer takes the water and the electricity with it, and home insurance
> is essential wherever it was filed.** Card 0033. A `while_owning_home` spend line can now say how
> much of it buys utilities (`ExpenseProfile::$propertyCostsUtilities`, builder key
> `expenseLines.*.utilities`, entered by the reader and never assumed); both routes out of the home,
> `withoutPropertyCosts()` and the projector's forced sale, remove the bucket LESS that part, so the
> replacement stays in the essential floor as ordinary spend with no marker and no escalator. And
> `HouseholdAssembler::tierOf()` is the single rule that a DISCRETIONARY line naming insurance plus
> the home counts as essential; the forecast, the builder's live totals and
> `ResultPresenter::expenseBreakdown()` all read it, so no screen can disagree with the projection.
> A third fix is disclosure only: the bought home's running costs, when SCALED from the current
> home's rather than assumed at 1% of value, now carry a `computed_figure` note stating the rule and
> reading `HousingComparison::newHomeRunningCosts()` (public and static for that). **Every stored
> plan carrying an insurance line filed as discretionary had too low an essential floor, so its
> "essentials always met" probability and capacity-for-loss reading are too favourable**; the
> utilities figure is new input, so no stored scenario carries one and no sell plan moves until
> somebody enters it. `ENGINE_VERSION` is `finance-engine/expenses-across-the-sell-boundary` and the
> **stored-scenario re-run is owed** (built in a worktree, so **the new step-4 input has not been
> seen in a browser**). The card's remaining task, whether upkeep should be a percentage of value at
> all, is card 0094: it needs a published maintenance series and an unattended session has no web.
> The 1% is now written up in [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§17) instead of living
> only in a docblock.

> **A purchase now spends the money arriving that year before it borrows.** Card 0034. The year-0
> funding waterfall in `HousingComparison::fundingFor` read the household's accounts and nothing
> else, so a `CapitalReceipt` dated the purchase year was invisible to it and the plan took a
> lifetime mortgage beside money it already had, paying interest on it for the rest of the
> projection. The order is now receipt, then savings, then mortgage, then unfunded gap. Receipt
> ahead of savings because spending it realises no gain, where a GIA draw to the same value pays
> CGT nobody owes. The spent part is CONSUMED (`HousingComparison::spendReceipts`): fully spent is
> dropped, partly spent keeps its remainder, another year's is untouched, so the projector credits
> only what reached the bank. `HousingPurchase` carries `fundedFromReceipts` and its constructor
> identity grows that term; `buyOutcome()` now takes the base year as a REQUIRED argument.
> **Every stored buy plan carrying a receipt in its base year borrows too much, so its spend,
> wealth, depletion year and success odds are too PESSIMISTIC**; stay-put, rent and any buy plan
> with no base-year receipt are byte-identical. `ENGINE_VERSION` is
> `finance-engine/year-zero-receipt-funding` and the **stored-scenario re-run is owed** (built in a
> worktree, so the new receipt line on the sale waterfall **has not been seen in a browser**).

## Card 0032, moved out of the live handover on 2026-09-06

The leasehold-selling-costs bullet, folded out with card 0031's for the same reason. Its rationale is
DECISIONS 2026-09-05 and the residue is board cards 0092 and 0093.

> **Selling a home is now priced as a leasehold sale, and a taxable disposal pays for its tax
> return.** Card 0032: `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` is **400** (4% all in, was 2%,
> an agent's fee and little else), and a disposal that actually owes CGT is charged
> `CGT_RETURN_FEE_PENCE` (£750) for the 60-day return, itemised on the sale waterfall, appended AFTER
> the gain so it neither reduces the tax nor becomes circular. `ScenarioBuilder::defaultSellingCosts()`
> ships the itemised version: agent 1.5%, leasehold conveyancing £2,000, management pack £500, licence
> to assign plus notices £700, removals £1,200, EPC £80. The assumptions panel now READS the rate
> constant instead of restating "2%", and it shows on every variant. **Every stored sell plan keeps
> money it would never see, so its wealth, depletion year and success odds are too favourable; a
> stay-put plan is byte-identical.** `ENGINE_VERSION` is `finance-engine/leasehold-selling-costs` and
> the **stored-scenario re-run is owed** (built in a worktree, so **the results page and the builder
> step have not been seen in a browser**). There is no tenure field to gate the leasehold lines on, so
> they ship charged with a note telling a freeholder to clear them; that residual fault is card 0093,
> behind 0026. The money figures are the 2026-08-19 property reviewer's judgement plus this build's
> reading of ordinary practice, not a published series: the fifth sourcing gap in
> [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§16), raised as card 0092.

## Card 0031, moved out of the live handover on 2026-09-06

The tenancy-referencing bullet, folded out to keep the live handover loadable in one session. Its
rationale is DECISIONS 2026-09-05 and the sourcing gap it left is board card 0091.

> **A rent plan is now tested against the landlord, not only against the money.** Card 0031: a new
> `Housing\Tenancy` owns four figures, and `PathProjector` raises
> `WarningCode::RENT_REFERENCING_FAILED` on any year whose gross income falls below 30 times the
> monthly rent (a standard tenant reference, which is an INCOME test and ignores capital entirely).
> The message states the two ways round it, a guarantor at 36 times and 6 to 12 months' rent in
> advance, with the money each costs. `HousingComparison::rentVariant` also charges the tenancy
> DEPOSIT (the Tenant Fees Act cap: 5 weeks' rent, 6 at £50,000+) as a year-0 one-off; the first
> month's rent is deliberately NOT charged again, because the year's rent line already carries twelve
> payments, and the disclosure names the day-one cash instead. Both notices reach screen, PDF and
> audit through `ResultPresenter::ladder()` (`rentReferencing` / `tenancyUpFront`), because
> `inputNotes()` is handed the STAY-PUT forecast on the screen, which is a separate defect raised as
> card 0089. **Every rent variant spends one deposit more in year 0**, so its stored wealth and
> terminal figures are very slightly too favourable; no other variant moves and the flag changes no
> number. `ENGINE_VERSION` is `finance-engine/tenancy-deposit` and the **stored-scenario re-run is
> owed** (built in a worktree, so **the results page has not been seen in a browser**). The 30x, 36x
> and 6-to-12-months are the 2026-08-19 property reviewer's judgement, not a published series; that
> is the fourth sourcing gap in [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§15) and is raised as
> card 0091. The deposit cap is statute and is sourced. The adjacent gap, that a mid-projection
> forced sale starts a tenancy and is charged no deposit, is raised as card 0090.

## The "what is built" inventory, moved out of the live handover on 2026-09-05

One 1,591-character line under "Current state" listed every feature the tool has. A fresh session
does not work differently for having read it: the tree, [docs/build/PLAN.md](build/PLAN.md) and this
archive all hold it, and an inventory of what works is the invariant rather than the exception the
live handover is for. Kept here verbatim.

> **Done:** the tool is feature-complete for personal use. An HMRC-accurate deterministic engine
> (income tax and NI, the pension lump-sum suite including Month-1 emergency tax and reclaim, State
> Pension, SDLT/CGT/PRR, means-tested benefits, IHT, care) sits behind a Monte Carlo with stochastic
> joint-life mortality and stochastic house-price, salary and care-cost paths. Around it: encrypted
> DTO persistence, Fortify auth, GDPR, Filament, queued runs with progress and cancel, a Livewire UI
> with charts, spreadsheet import, a complete PDF export with server-drawn charts (an export of more
> than eight forecasts is queued and built one at a time, delivered as a zip), 2FA and a CSP.
> Decision support covers lever thresholds, a combination comparison, the survivor cliff, capacity
> for loss (how far wealth can fall before the essential floor breaks), a 2-D trade-off map and a
> local-model assistant. Housing covers stay-put, buy-cheaper, rent, park homes (a bought home that
> depreciates), let-to-let, equity release and real amortising repayment mortgages pinned to a lender
> illustration. The adviser-parity sweep is now closed bar A4 salary sacrifice, B3 the estate
> checklist, B4 the annual review and B5 capacity for loss (card 0011): investment charges, net-pay
> contribution relief, the protection gap (employer death-in-service cover and the life cover that
> would restore a survivor's plan), the cost-of-advice comparison and the ISA subscription cap
> shipped on 2026-07-31, and the annual-allowance / MPAA contribution cap, the £3,600 non-earner
> relief route and bed-and-ISA on 2026-08-22.

## Card 0030, moved out of the live handover on 2026-09-05

Folded out on the same test. Its rates and their sourcing gap live in `docs/spec/ASSUMPTIONS.md`
(§14) and card 0087, its adjacent fault is card 0088, its stamp is in the
`ScenarioForecaster::ENGINE_VERSION` docblock, and its owed re-run is covered by the standing
"re-run every stored scenario" line in the live handover.

- **A let property no longer earns its rent gross.** Card 0030: three rates on `Property`
  (`lettingManagementRate()` / `lettingVoidRate()` / `lettingMaintenanceRate()`, defaults 12% / 8% /
  5%, a quarter of gross rent) come off every `IncomeStreamType::Rental` stream when the home is
  `isLet`, and the let home's service charge is deducted from rental profit instead of being taxed as
  though it were not paid. The Section 24 credit is read off that profit, not gross rent. All three
  rates are builder inputs (step 3, shown once the home is flagged as let, alongside a new `isLet`
  checkbox that had no control before) and are disclosed as `assumed_figure` notes reading their own
  constants; a new `letting_caveats` note states the freeholder-consent, EPC C and council-tax gaps
  that were previously only in a docblock. Every let-to-let plan banks less rent and is taxed on less
  profit, so its stored wealth, depletion year and success odds are too favourable; a home the
  household lives in is byte-identical. `ENGINE_VERSION` was `finance-engine/letting-costs`.

## Cards 0029 and 0025, moved out of the live handover on 2026-09-05

Folded out with the inventory above, on the same test: neither changes what the next session does.
Card 0029's figure and its sourcing gap live in `docs/spec/ASSUMPTIONS.md` (§13) and card 0086, its
stamp is in the `ScenarioForecaster::ENGINE_VERSION` docblock, and its owed re-run is covered by the
standing "re-run every stored scenario" line in the live handover. Card 0025 is closed: 0023
confirmed it against the stored set and needed no code.

- **An overridden home is no longer a certainty, and one home is now modelled over double the index
  volatility.** Card 0029: `PathDraws::propertyGrowthReal($yearIndex, $meanReal)` replaces
  `houseGrowthReal()`. A property growth override used to REPLACE the sampled house path, so a park
  home or a hand-priced flat carried no house risk at all; it now sets the MEAN the year's shock is
  re-centred on. The shock is also widened by `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`
  (2.0, so 18% real on the default set) because the sampled figure is an INDEX one, disclosed as an
  `assumed_figure` note and editable as the `propertyVolatility` assumption. `ReturnModel` is
  untouched, so the RNG stream is byte-identical and only what the home does with each draw changed.
  The deterministic projection is unchanged; every Monte Carlo band, success probability and
  capacity-for-loss reading on a plan that keeps or buys a home is now WIDER. `ENGINE_VERSION` was
  `finance-engine/single-property-house-risk`, and the re-run includes the park-home scenarios,
  whose range card 0029 asked for.

- **A full-spend measure is now recurring-spend only, and every stored figure moved with it.**
  Card 0025: a one-off capital lump the plan cannot fund (an unfunded purchase, a mortgage redeemed
  from capital) is charged the year's shortfall FIRST and judged on its own, so it no longer fails
  the all-or-nothing full-spend test on every path. Any Monte Carlo full-spend probability stored
  before this is not comparable with one stored after. `ForecastResult::fullSpendYearsMetFraction()`
  and `SimulationResult::$successProbability` `FullSpendMostYears` (95%+ of years) are the honest
  companions; the latter is `null` on an older stored run and must show as a dash, never 0%. Card
  0023 confirmed it against the stored set and needed no code: #51's completion gap is £1.37, it is
  the only stored scenario carrying an unfunded lump at all, and its full-spend probability now sits
  within a point of its essentials one.

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

## Folded out of HANDOVER.md on 2026-09-08 (card 0064)

Cards 0044 and 0045, verbatim from the brief's exceptions list.

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
