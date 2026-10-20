# HANDOVER archive — RetireForecast

> The detailed per-feature build record and older session-log narrative, moved out of
> HANDOVER.md on 2026-07-10 to keep the live handover small enough to load each session.
> Nothing here is load-bearing for picking up the project (the live HANDOVER.md carries
> that); this is the "how we got here" detail. Each item also has a dated DECISIONS.md
> entry and the full record in `git log`. Newest-first within each part.

## Cards 0040 to 0082, moved out of the live handover on 2026-10-20

Folded out by card 0151 to bring the live brief under the 40 KB a fresh session can load. These
are the exception bullets as they stood, newest first. What still stands from them is summarised
in one bullet in the live handover: each `ENGINE_VERSION` bump here owes the stored-scenario
re-run, each new screen is unseen in a browser (card 0001), and every gap or unverified figure
named here carries its own board card.

- **A Section 24 credit no longer outlives the mortgage it relieves.** Card 0082. The relievable
  finance cost was read off the "Mortgage" expense line, or off the amortisation schedule's
  interest, with no check that the loan still existed, while the payment side had always stopped at
  `mortgageRepaid` / `homeSold`: a let household could redeem at maturity, be force-sold, or sell a
  home carrying an amortising loan, pay no interest from that year, and still collect basic-rate
  relief on it for the rest of the plan. The fix is a MOVE, not a second condition. The block now
  runs immediately AFTER the forced-sale block in `PathProjector::projectYear`, where the two flags
  hold this year's answer, and carries exactly the condition the payment carries, which is what
  makes one guard close all three routes at once. Nothing between the old and the new position
  reads the year's tax or net cash, so no other figure moves. `ENGINE_VERSION` is
  `finance-engine/section-24-credit-dies-with-the-mortgage` and the **stored-scenario re-run is
  owed** for any let plan that redeems or sells: its tax was too low, so its wealth, depletion year,
  estate and odds are all too favourable, and the flattery fell on exactly the borrowing routes the
  draw-order and housing rankings turn on. `GoldenMasterTest` did not redden (its fixture lets
  nothing). `scenarios:audit` reports no new problem class. No screen changed.

- **The cheapest draw order can no longer be the one that funds least.** Card 0081. The optimiser
  ranked candidates on lifetime tax alone, which holds only while every order funds the same
  spending: an order that runs short stops drawing, so it stops paying, and leaves a smaller estate
  to be taxed, so the order funding LEAST could win and the steer point at it.
  `WithdrawalStrategyComparison::funding()` reads the run's own report (both all-or-nothing flags,
  the depletion year and BOTH `*YearsMetFraction()` shares, which is what makes it bite on a
  household where every order runs short and the flags tie), and `fundsAtLeastAsMuchAs()` keeps a
  candidate in the running only when no measure is worse than the order in place. The tax total, and
  the rule that the saving is the difference of two engine runs, are untouched.
  `panel()['fundingDiffers']` says on both the screen and the PDF when the orders are not comparable,
  and `panel()['tilesFundAlike']` stops the two-tile sentence calling either tile cheaper when they differ.
  **No `ENGINE_VERSION` bump and no stored re-run is owed** (nothing about a projection moves; the
  panel is computed on the page). Built in a worktree, so the new panel sentence **has not been seen
  in a browser**. Whether the shortfall should be PRINTED beside the winner is left open and is
  Rob's; see DECISIONS 2026-09-08.

- **A pot can now say how much of it is already in drawdown.** Card 0080. Every starting pot was
  seeded wholly uncrystallised, so a reader who had already taken their tax-free cash was given a
  second quarter of the same money. `DcPension::$crystallisedValue` is the reader's own per-pot
  figure (builder input beside "Tax-free cash already taken"), read through `crystallisedValue()`
  and seeded into `PathProjector`'s `crystallised` key; it is never inferred from `pclsTakenToDate`,
  which is an allowance ledger across all of the member's pensions. Null = not asked, which keeps
  today's wholly-uncrystallised behaviour, so **no `ENGINE_VERSION` bump and no stored re-run is
  owed** and `GoldenMasterTest` did not redden; the assumption is disclosed by
  `ResultPresenter::assumedFigures()` with the tax-free cash it still grants. **Whether an
  unanswered pot should instead infer a crystallised share from `pclsTakenToDate` is left open and
  is Rob's** (the card's fourth Task; it would move every stored scenario that has taken tax-free
  cash). See DECISIONS 2026-09-08. Built in a worktree, so the new builder input and the new results
  note **have not been seen in a browser**.

- **A pension inherited from someone who died under 75 is tax-free to draw.** Card 0079. The
  projector folded the deceased's pots into one for the heir without recording how old they were
  when they died, so the fact that decides the whole tax treatment was gone by the time the pot was
  drawn and the heir's full marginal rate was charged either way.
  `state['pots'][heir][]['deceasedAgeAtDeath']` is the fact, stashed by `settleEstates` (which now
  takes the path's `PathDraws`) the way `recordDeathInServiceBenefit` already stashes it for the
  lump-sum route. `PathProjector::drawIsTaxFree()` is the one home of the rule, reading the age-75
  line from `InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE`; three places ask it, so the
  answer cannot turn on the draw order or on which surface is asking: both ad-hoc draw closures in
  `fundShortfall`, through one shared `$takeTaxFreeInherited`, and `pensionTaxIfDrawn`, which was
  netting the same phantom tax off spendable wealth. The draw rides the existing
  `fromPensionTaxFree` channel, so it lands on the ladder's tax-free line, and **the means test
  therefore sees none of it, which is card 0145**: a tax-free inherited income IS income for
  Pension Credit. `ENGINE_VERSION` is `finance-engine/inherited-pension-tax-free-under-75` and the
  **stored-scenario re-run is owed** for any plan whose first death is before 75 and which leaves a
  pot behind. The **Monte Carlo golden master was re-pinned** (essentials success 0.4850 to 0.5050,
  terminal wealth up at p50 and p90); `PIN_REVISION` was already today's date, so its companion
  test could not demand the entry, and DECISIONS 2026-09-08 records it anyway. The rule is
  **STATED, not verified** (no web in this session): see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§38), carded as **0146**. Built in a worktree, so
  the new results note **has not been seen in a browser**.

- **The cheapest-draw-order search generates candidates now, so it is a search.** Card 0078. It
  priced the three orders the tool has names for and called the winner the cheapest of the ones it
  tried, which could never be an order nobody had written down. `App\Forecast\DrawCandidate` is one
  order the search prices, a `DrawdownStrategy` plus an optional taxable-income TARGET, and
  `WithdrawalStrategyComparison::candidates()` builds the set: the three named orders plus "keep each
  person's taxable income under £X a year" at the personal allowance and at the top of the
  basic-rate band, both read off the scenario's own tax year, so no figure is invented. Five
  candidates, inside the plan's 4-to-6 ceiling, and each is still one forecast.
  `ForecastSettings::$taxableIncomeTargetPence` carries the target and only `FillBands` reads it:
  with a target the pension pass fills to X and the basic-rate pass does not run. **No
  `ENGINE_VERSION` bump and no stored re-run is owed** (null target everywhere a reader can reach),
  and `GoldenMasterTest` did not redden. `scenarios:audit` reports no new problem class. **Rob has
  not confirmed the candidate set**, which the card names as his call; it is the plan's own
  recommended default and DECISIONS 2026-09-08 records what was chosen and how to reverse it. No
  screen changed, but the panel can now name a winner the reader cannot run: card **0144**.

- **Money drawn out of a pension now reaches the Pension Credit means test.** Card 0077. The award
  for a year was worked out from the income the household already had, and only then did the
  projector take money out of a pot to cover the rest, so a household could draw thousands and keep
  a credit the means test would have taken away pound for pound. The award and the draw are now
  solved TOGETHER, to a fixed point, in `PathProjector::projectYear`: everything from the Support
  for Mortgage Interest block to the end of the drawdown is one pass, re-run against a restored
  state until re-assessing the means test on what the pass drew leaves the award where the pass had
  it. That equality is the settling test, not a tolerance. The step between passes is a SECANT
  (`MAX_PENSION_CREDIT_PASSES` = 8): plain substitution closes only a quarter of the gap each time,
  because three quarters of a draw is taxable, and would need scores of passes. Only the TAXABLE
  part is assessed, the tax-free quarter being capital. **The draw ORDER is settled once, before the
  year draws anything**, and does not move with the claw-back: letting it read the pass's own
  clawed-back award put the iteration on the wrong one of two self-consistent answers. `ENGINE_VERSION`
  is `finance-engine/pension-draw-assessed-for-pension-credit` and the **stored-scenario re-run is
  owed** for any plan holding an award and drawing taxable pension money. The **Monte Carlo golden
  master was re-pinned** (essentials success 0.5150 to 0.4850, terminal wealth down at every
  percentile above p10); `PIN_REVISION` was already today's date, so its companion test could not
  demand the entry, and DECISIONS 2026-09-08 records it anyway. No screen changed. One finding
  carded rather than fixed: **0143**, the claw-back is capped at the year it happens in, so the
  Pension-Credit-aware draw order, which defers the pension until the capital is gone and then draws
  a little every year, now loses more credit across a plan than the order it is meant to beat.

- **Spendable wealth is net of the tax on the pension part now.** Card 0076. "How much you would
  have left" added the pot to the cash at face value, and that figure is what the safety-buffer
  warning is measured against and what the buy, rent and stay-put plans are ranked on, so the
  warning fired late and the plan holding most of its wealth inside a pension won on money it did
  not have. `YearResult::usableWealth()` is the one home of the figure and every surface reads it
  (the ladder column and its buffer check, the burndown line, the Monte Carlo usable series and
  percentiles, `terminalUsableWealth`, so the assistant, Compare, the PDF and the ranking too).
  `PathProjector::pensionTaxIfDrawn()` computes it: the tax-free part comes off FIRST through the
  same `ufplsSplit` / `lsaHeadroom` the draw routes use, so a crystallised or inherited pot gets no
  second quarter and the allowance ledger is the member's own, and the balance is charged at the
  PROJECTED MARGINAL rate rather than at what encashing the whole pot in one year would cost (see
  DECISIONS 2026-09-08 for that call and for its known ceiling, a member inside the personal
  allowance netting nothing). **Total wealth stays gross on purpose**, so the two now differ by
  exactly the tax owed, which `WealthReconciliationTest` asserts. The rate, the taxable amount and
  the tax are disclosed together as an assumed figure. **Nothing about the projection moves**: the
  netting is reported, never spent, so tax, spending, depletion and the odds are byte-identical and
  `GoldenMasterTest` did not redden. `ENGINE_VERSION` is
  `finance-engine/spendable-wealth-net-of-pension-tax` and the **stored-scenario re-run is owed**,
  because the stored spendable figure is what is wrong. Built in a worktree, so the new results note
  **has not been seen in a browser**. One gap carded rather than fixed: **0142**, the usable-wealth
  column headers and tiles still say only "excl. home" and not that the pension tax is off.

- **The draw order is the reader's now, and the default says so.** Card 0075. Every forecast ran on
  `TaxEfficient`, chosen in code, while the results page priced all three orders and named the
  cheapest, so the tool could say a different order saves thousands and offer no way to model it.
  `DrawdownStrategy` is a backed enum owning the stored string, the `label()` and a `description()`
  of what each order does; the choice rides the sparse `assumptionOverrides` choice-key route, so a
  scenario stored earlier reads as the default and is byte-identical. **No `ENGINE_VERSION` bump and
  no stored re-run is owed**, and `GoldenMasterTest` needed no re-pin. The comparison panel's
  baseline tile is now the reader's own order, and its alternative is derived rather than the old
  constant, because the sentence describing the second order moved onto the enum that owns it. See
  DECISIONS 2026-09-08. Built in a worktree, so the new builder control, the reworded panel sentence
  and the new results note **have not been seen in a browser**.

- **An ad-hoc pension draw reports its tax-free quarter as tax-free cash.** Card 0074. Every pound of
  an unplanned fill-the-bands draw was printed on the cashflow ladder's `pension_drawdown` line,
  which the page labels as taxable pension income, so a reader adding up their taxable income off
  the table got a figure about a third too big and could not reconcile it against the tax the same
  row shows. `PathProjector::fundShortfall` now returns `fromPensionTaxFree` beside `fromPension`,
  fed only by `$drawPensionUfpls` out of the `ufplsSplit` it already computes, and the caller files
  that part on `pension_lump_sum` and the balance on `pension_drawdown`. It is a SUBSET, never a
  second sum, so the year still reconciles to the money that left the pots. **No figure moves**:
  tax, wealth, depletion and the odds are unchanged and nothing needed a re-pin. `ENGINE_VERSION` is
  `finance-engine/ad-hoc-draw-reports-its-tax-free-part` and the **stored-scenario re-run is owed**
  for any plan making such a draw, because the stored LADDER is what is wrong and a stored run has
  no other way to say so. Built in a worktree, so the relabelled rows **have not been seen in a
  browser**.

- **The pension annual allowance is a bill now, not a wall, and it binds the year the trigger
  happens.** Card 0073. Two faults with one cause. The allowance was a hard cap: a contribution
  above it never reached the pot, so an overpayment vanished instead of appearing as tax, and the
  employer's share of it was simply never paid. And the year loop paid every contribution before it
  took any withdrawal, so a member who started flexible access in April was credited a full £60,000
  for a year in which they had £10,000. `PathProjector::annualAllowanceCharges()` is the one home of
  both: it runs ONCE, after every contribution route and after every withdrawal, reads which
  allowance applies through `AnnualAllowanceCalculator` (the rule keeps one home), and charges the
  excess at the member's marginal rate through the same `marginalTax` pass every other figure uses.
  `payIntoPot` refuses nothing now; what still binds a contribution at source is pay, surplus and
  the non-earner basic amount, each applied by its own caller. The charge comes out of the member's
  cash, and what their cash cannot meet falls into that year's unmet spend rather than being
  forgiven. `ResultPresenter::assumedFigures()` states the allowance that applied and the charge, on
  the first year it bites. `ENGINE_VERSION` is `finance-engine/annual-allowance-is-a-bill-not-a-wall`
  and the **stored-scenario re-run is owed** for any plan paying into a money-purchase pension;
  `GoldenMasterTest` did not redden (its fixture pays nothing in). Two limits are deliberate and
  flagged in the docblock: carry-forward and the high-income taper are still absent (the card
  excluded both), and the charge is priced on the year's income BEFORE any ad-hoc draw made to fund
  a shortfall, so a member pushed into a higher band by that draw is charged at the band they were
  in without it. Built in a worktree, so the new results note **has not been seen in a browser**.
  The trigger year is NOT split at the draw (no date inside a year): its whole input is measured
  against the MPAA, the cautious side, declared on `applicableAllowance` and told to the reader in
  that year's charge note. Splitting it properly is card 0192.

- **An annuity is priced now, it takes its tax-free cash first, and every economic assumption
  carries its own source.** Card 0065. `Pension\AnnuityRateTable` is the one home of what an annuity
  pays: a base single-life level rate by age, interpolated between seven anchors, times an
  index-linked multiple (0.62) and a survivor's reduction (20% at a full survivor's pension, scaled
  linearly by the fraction). The builder re-quotes whenever the age, the escalation basis or the
  joint-life setting changes (`ScenarioBuilder::repricedAnnuities`, shared by the pension and
  account sub-forms, which are one Blade partial), and the assembler uses the same table where a
  saved row carries no rate; a reader's own quote is only overwritten when they change the shape it
  was quoted for. Alongside it, annuitising a DC pot now CRYSTALLISES the money it takes, through
  the same `ufplsSplit` and lump-sum-allowance ledger every other lump sum uses, so a quarter comes
  out as tax-free cash and only the balance buys the income; the amount entered is now the money
  COMMITTED. `ENGINE_VERSION` is `finance-engine/annuity-takes-its-tax-free-cash-first` and the
  **stored-scenario re-run is owed** for any plan that annuitises a pension pot; `GoldenMasterTest`
  did not redden (its fixture buys no annuity). `CapitalReceipt::$chattelCost` turns a receipt into
  a DISPOSAL and `Tax\ChattelsGain` owns TCGA 1992 s262, seeding the year's existing CGT charge so a
  painting and a share holding share one annual exempt amount; null (every stored receipt) keeps it
  a windfall, so nothing stored moves. And `Dto\FigureSource` gives every economic assumption its own
  citation and verified-on date, swept by `php artisan figures:freshness` beside the statutory
  figures and enforced by `EconomicAssumptionSourcingTest`, which enumerates the `AssumptionSet`
  constructor by reflection so a figure added without a source reddens. The annuity table's three
  figures and both chattels figures are **STATED, not verified** (no web in this session): see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (sections 36 and 37), carded as **0140**. The
  methodology page's contradiction about house-price and salary volatility is fixed. Built in a
  worktree, so the re-quoting rate field, the new capital-receipt cost input and the reworded
  methodology **have not been seen in a browser**.

- **Inflation has a memory now, and it moves against markets.** Card 0064. The Monte Carlo drew
  inflation from an independent normal each year, so a high year told you nothing about the next
  and could not coincide with a bad one for the portfolio. `AssumptionSet::$inflationPersistence`
  (an AR(1) coefficient) and `$inflationAssetCorrelations` (one figure per asset class) are the two
  new homes, both read through clamping accessors. `ReturnModel` appends inflation to the
  correlation matrix as the LAST factor and decomposes the augmented matrix, so a price shock hits
  each asset class by its own amount instead of hanging off equities the way the house and salary
  factors do; index 0 is still equities, which those two read. **The innovation is scaled by
  sqrt(1 - phi^2)**, so the spread of any SINGLE year stays exactly the stated
  `inflationVolatility` and persistence buys cumulative spread and nothing else. A set stating
  neither figure gives a last Cholesky row of `[0, ..., 0, 1]`, so the shock is the same raw normal
  in the same place in the stream: byte-identical. Both figures are on all three shipped sets, so
  `ENGINE_VERSION` is `finance-engine/inflation-with-a-memory` and the **stored-scenario re-run is
  owed**: the central projection is unmoved, every Monte Carlo band and probability is wider. The
  **golden master was re-pinned** (essentials success 0.5300 to 0.5150), `PIN_REVISION` bumped and
  DECISIONS 2026-09-08 records it. Alongside, `HistoricalBacktester::backtest` takes an optional
  horizon and `ScenarioForecaster::longLifeHistoricalBacktest` runs the same historical starts at
  the 90th percentile of the last survivor's age at death, reported beside the representative run;
  it is omitted where that is the same run (a plan already at the 90th, or a household whose
  lifespans the reader stated). Both engine figures (0.70, and -0.30/-0.50/-0.55) are **STATED, not
  verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§35),
  carded as **0139**. Built in a worktree, so the two new assumptions rows and the new long-life
  block **have not been seen in a browser**.

- **A household can now be modelled cutting back after a bad run.** Card 0063. Every path was
  scored against a FIXED real spending target, which overstates the chance of running out (no
  household spends into insolvency) and hides the cheapest mitigation there is.
  `Dto\SpendingGuardrail` is the one home of the rule and of both of its figures: below a trigger
  multiple of the essential spend still to be funded, the DISCRETIONARY spend is cut and the
  essential floor is never touched. Usable wealth is liquid plus pension, the definition the
  forecast already reports as terminal usable wealth; the denominator reads
  `PathProjector::yearsRemaining()`, which takes the same death ages the projection loop ends on.
  The test runs every year off that year's OPENING wealth, before the drawdown, so **recovery
  restores the spend by itself and there is no latch**. **Opt-in, so every stored scenario is
  byte-identical: no `ENGINE_VERSION` bump, no stored re-run owed, `GoldenMasterTest` did not
  redden.** Opt-in is the adverse choice too, this being the one setting that makes a plan look
  better. `YearResult::guardrailReduction()` reports what each year trimmed, and two notes ride
  `ResultPresenter::inputNotes()` (so the page and the PDF cannot drift): how many years it bit,
  the total and the deepest year, and, on `WarningCode::GUARDRAIL_NO_FLEXIBILITY`, the household
  that had nothing left to cut. Both defaults (a funded ratio of 1.00, a 10% cut) are **STATED,
  not verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
  (§34), carded as **0138**, which also carries the unsettled question of whether the published
  rules trigger on a funded ratio at all or on the withdrawal rate. Built in a worktree, so the
  three new builder controls and the two new notes **have not been seen in a browser**.

- **Growth is bought with risk now, and the asset mix is an input.** Card 0062. The mix was
  hardcoded to a cautious 40/60 nothing could move, and the "investment growth" override raised
  every asset class's mean while leaving the volatilities and correlations alone, which sold an
  equity return at a cautious portfolio's spread. `Forecast\AllocationProfile` is the one home of
  the four named mixes (defensive 20/80, cautious 40/60 as the unchanged default, balanced 60/40,
  growth 80/20) and `PortfolioAllocation::forBlendedRealReturn` is the one home of the re-weighting
  that lands a growth edit on its target, so the volatility moves with it;
  `AssumptionSet::withRealReturnShift` is deleted. A target no mix can reach is REFUSED in the
  builder (with the reachable range, read from the set's own asset classes) and CLAMPED for a
  scenario stored earlier, which the assumptions panel names. `PortfolioAllocation::at()` owns the
  glidepath and all three draw sources read it per year; **a glidepath has no default length**, the
  reader states the years. All three choices ride the sparse `assumptionOverrides` choice-key
  route, so a scenario stored earlier reads as the default. `ENGINE_VERSION` is
  `finance-engine/growth-is-bought-with-risk` and the **stored-scenario re-run is owed**: a plan
  with a growth edit keeps its central projection and moves its whole Monte Carlo.
  `GoldenMasterTest` did not redden (no growth edit, no chosen mix) and needs no re-pin. Each asset
  class now carries a source and a verified-on date for its RETURN and for its VOLATILITY apart,
  surfaced on the results page and the PDF, but **STATED, not fetched** (no web in this session):
  see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§33), carded as **0137**, which also carries
  the card's own unfinished Task, re-sourcing the gilt real return against current index-linked
  gilt yields. Built in a worktree, so the three new builder controls, the new portfolio-spread row
  and the sourcing list **have not been seen in a browser**.
- **The plan no longer runs to a coin-flip lifespan.** Card 0061. Every deterministic surface, so
  the whole comparison table, the affordability limits and the per-month analysis, ran to each
  person's OWN median age at death; for a couple the question is the LAST survivor, whose age at
  death is materially later. `Mortality\PlanningHorizon` names the three settings (50th, 75th, 90th)
  and owns their odds wording, `CohortLifeTable::survivalCurve()` is the one home of the survival
  arithmetic that `percentileDeathAge()` and `medianDeathAge()` both read, and
  `RepresentativeDeathAge::forHousehold()` finds the first year the probability that anybody is
  still alive falls below the threshold, treating lives as independent (the assumption the Monte
  Carlo sampler already makes). **Only the last survivor is carried out to it**, and **a lifespan
  the reader stated is never extended** (see DECISIONS 2026-09-08 for both calls). The setting
  rides `ForecastSettings` through the sparse `assumptionOverrides` choice-key route, so a scenario
  stored earlier carries no key and reads as the default. `ResultPresenter::planningHorizonBasis()`
  is the one home of the sentence that states the odds, read by the results page, Compare, the
  Affordability page and the PDF; deterministic copy says "to the end of the plan", never "for
  life". So no surface can describe a path the projection did not run. `ENGINE_VERSION` is
  `finance-engine/last-survivor-planning-horizon` and the **stored-scenario re-run is owed**: every
  stored plan funds more years now, so its wealth and estate are too high and its depletion year
  too late. `MonteCarlo\GoldenMasterTest` did not redden (the simulation samples lifespans and is
  untouched) and needs no re-pin; `PathProjectorTest::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS` was
  re-pinned because that couple funds more years, not because the UFPLS rule moved. Built in a
  worktree, so the new builder control and the four relabelled copy blocks **have not been seen in
  a browser**.
- **Secured income can now be bought with money that is not pension money.** Card 0060. An annuity
  could only be funded from a DC pot, so a household whose savings sat in cash, an ISA or a general
  investment account could not buy one at all, and the third option in a two-plan decision (partly
  annuitise for a much younger spouse) could not be put on the table. `Account::$annuityPurchase`
  mirrors `DcPension::$annuityPurchase` exactly, so **the account IS the named source**: no owner
  field to keep in step and one DTO for both kinds of annuity. Funding it from a GIA realises a gain
  that seeds the same year's CGT charge through the `$seedGains` path a year-0 disposal already
  uses. `Pension\PurchasedLifeAnnuity` is the one home of the tax split (the exempt capital element
  is the price over expected remaining life at the age the income starts, FIXED in money for life,
  so an escalating annuity's exempt proportion falls); it comes off the **income-tax pass and
  nothing else**, because the money is still received and still assessed by both means tests.
  `AnnuityPurchase` also gained a deferred `incomeFromAge` (nothing is paid if the annuitant dies
  inside the deferral) and an `enhanced` flag at `ENHANCED_UPLIFT_BPS` (10%, the cautious end,
  disclosed). The builder's annuity sub-form is now ONE partial shared by a DC pot and an account.
  **No `ENGINE_VERSION` bump and no stored re-run is owed:** no stored account has an annuity, so
  every figure is byte-identical and `GoldenMasterTest` did not redden. Both engine-side figures are
  **STATED, not verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
  (§32), carded as **0136**. Built in a worktree, so the new account sub-form and the two new
  disclosures **have not been seen in a browser**. The card's fourth Task, a one-click
  partly-annuitised what-if preset, is **not built**: no acceptance criterion covers it and how much
  to annuitise at what age is the adviser's question, not a default. A reader can build the same
  comparison by hand today.
- **A park home claims no residence band, and a nursing fee is net of what the NHS pays.** Card
  0059. `Property::$isChattelDwelling` is the fact (a park home, mobile home or houseboat: a chattel
  on a rented pitch, not an interest in land) and three methods read it, so nothing restates it:
  `qualifiesForRnrb()` refuses the residence nil-rate band, `saleCommissionRate()` returns
  `MAX_SITE_COMMISSION_BPS` (10%, the statutory maximum), and `netOfSaleCommission()` is the one
  home of the deduction, which `PathProjector::netHomeValue()` applies in the estate at BOTH deaths
  and in the care means test. The wealth line keeps the gross value on purpose: a household still
  living there has not paid the commission. The field is NULLABLE, and null DERIVES the answer from
  the home being modelled as losing value, which is how a park home has always been entered here;
  that derivation is adverse and the `chattel_dwelling` note says we made it. The **downsizing
  addition survives**, which is the one route back where a real house was sold to buy the park home.
  Alongside it `CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE` (£254.06) comes off
  `nursingAnnual()`, so **every plan that models care and draws a nursing spell was charged too much
  and is too pessimistic**; and the FIRST death now values the deceased's own
  `Property::$beneficialShares` instead of exactly half, equal shares being the disclosed default.
  Continuing Healthcare is **disclosed, not modelled**: the care note says every care figure is the
  position if it is NOT awarded. `ENGINE_VERSION` is
  `finance-engine/park-home-and-nhs-nursing-contribution` and the **stored-scenario re-run is
  owed**. The **Monte Carlo golden master was re-pinned** (terminal wealth up at p10, p25 and p90,
  both success probabilities unmoved) and DECISIONS 2026-09-07 records it; `PIN_REVISION` was
  already today's date, so its companion test could not demand that entry. All three rules are
  **STATED, not verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
  (§31), carded as **0135**. Built in a worktree, so the two new builder inputs, the chattel note
  and the two new care disclosures **have not been seen in a browser**.
- **The estate is no longer one number stated to the pound.** Card 0058. The estate panel took its
  figure from the DETERMINISTIC forecast (one path, median lifespan, no care) and sat it beside a
  probability with ten thousand paths behind it, labelled "everything you're modelled to leave".
  `ResultPresenter::estateRange()` is the one home of the simulated band and reads the
  `terminalWealthPercentiles` series the headline cards already read, so the two cannot disagree;
  the tile keeps its deterministic figure (the tax arithmetic below it is done on that figure) and
  now says in its own sub-label that it is a single path. `estateCaveats()` rides `ihtPanel()`, so
  the page and the PDF cannot show the estate without probate cost and delay, the beneficiary's
  income tax and the care position; **no probate cost is invented**, they are named and declared
  unmodelled. The Compare page labels its two surfaces "Single path" and "Simulated". Where a
  rolled-up loan has passed the property value the existing `lifetime_mortgage_rollup` note now says
  the lender takes the property and names what is left outside it. **No `ENGINE_VERSION` bump and no
  stored re-run is owed:** it is presentation, no figure moves, and `GoldenMasterTest` did not
  redden. Built in a worktree, so the estate band, the two Compare labels, the caveat list and the
  PDF's two new blocks **have not been seen in a browser**.
- **An inherited pension is taxed twice, and the spouse exemption on a pot follows the nomination.**
  Card 0057. The tool showed the Inheritance Tax on an unused pot and never the beneficiary's own
  income tax on drawing it, which makes preserving a pot look about twice as attractive as it is on
  the exact comparison the IHT toggle exists to run. `InheritanceTaxCalculator` now charges the
  second tax on the pot NET of the Inheritance Tax it bears, apportioned rateably over the
  CHARGEABLE estate, gated on `BENEFICIARY_TAXED_FROM_AGE`, at an editable rate defaulting to
  `DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS` (40%, adverse, disclosed). It rides `IhtResult` and
  `IhtOutcome` beside the tax and is never added into it: the two fall on different people in
  different years. Alongside it `Dto\PensionBeneficiary` on `DcPension` records the expression of
  wish, and `computeFirstDeath` holds the pension OUT of the will and intestacy split entirely
  (a death benefit does not pass that way), exempting it only where nominated to the survivor.
  **An unanswered nomination reads as NOT the spouse**, the adverse answer, so **every stored plan
  that models Inheritance Tax and holds a DC pot now pays MORE at the first death** and transfers a
  smaller band to the second, until its nominations are ticked; DECISIONS 2026-09-07 records that
  call and Rob can reverse it in one line. `ENGINE_VERSION` is
  `finance-engine/inherited-pension-taxed-twice` and the **stored-scenario re-run is owed**.
  `GoldenMasterTest` did not redden (its fixture models no IHT). Every rule is **STATED, not
  verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§30),
  carded as **0134**. Built in a worktree, so the new builder rate select, the per-pension
  nomination select, the results panel and the PDF lines **have not been seen in a browser**. One
  gap carded rather than fixed: **0133**, `settleEstates` still hands a pot nominated to a child to
  the surviving partner, so it is taxed as leaving the household while its money stays in it.
- **A lifetime mortgage is now redeemed when the last borrower goes into care.** Card 0056.
  `Property::$mortgageRollUpRate`'s docblock had always said the balance is repaid on death, sale
  **or care**; the projector only ever settled it at the end of the path, so the tool showed that
  household living in the home to the end of the plan with an estate.
  `PathProjector::equityReleaseRedeemedByCare()` is the one home of the trigger (a roll-up rate, a
  balance still owed, and every LIVING member in care this year) and it runs the SAME sale block a
  forced sale at maturity runs, so proceeds, selling costs, CGT, the SMI and deferred care charges
  on the same bricks and the recorded residence disposal all keep one definition. It sits ABOVE the
  care fee in the year order, which is what makes the resident assessed on the new position: no
  home, proceeds in hand. **Every stored plan with a roll-up balance whose paths reach care moves**;
  a plan with no roll-up rate, or that never reaches care, is byte-identical, which is why
  `GoldenMasterTest` did not redden and needs no re-pin. `ENGINE_VERSION` is
  `finance-engine/lifetime-mortgage-redeemed-on-entry-to-care` and the **stored-scenario re-run is
  owed**. The rule is **STATED, not verified** (no web in this session): see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§29), carded as **0132**. Built in a worktree, so
  the rewritten equity-release note **has not been seen in a browser**.
- **A care bill the plan cannot pay is now a debt on the home, not a plan failure, and the property
  disregard follows the statute.** Card 0055. Two faults with one cause: the model treated an
  assessable home as money it could neither shelter nor spend. The disregard applied only while a
  partner was alive, so a household with a resident relative aged 60 or over, an incapacitated
  relative or a child under 18 was assessed on a home no authority could charge against;
  `Dto\Property::$occupiedByQualifyingRelative` is the reader's own statement that one of them lives
  there and `PathProjector::careHomeAssessable()` is the one home of the question, defaulting FALSE
  so nothing stored moves until it is ticked. And `fundShortfall()` never draws on the home, so a
  self-funding lone homeowner ran an unfundable charge every care year and the plan was penalised
  for keeping a property that in life would simply have carried the debt: the unfundable part of the
  CARE charge alone (never the groceries) now becomes `state['deferredCareBalance']`, capped at the
  equity the security bears, rolling up at the rate on `Care\DeferredPaymentAgreement`, redeemed at
  a forced sale and deducted at both deaths. Built to the same shape as the SMI charge beside it.
  **Every plan whose paths reach an unfundable care year moves**: it survives where it used to fail,
  with a smaller estate. `ENGINE_VERSION` is `finance-engine/deferred-care-payment-on-the-home` and
  the **stored-scenario re-run is owed**. The **Monte Carlo golden master was re-pinned** (essentials
  success 0.5000 to 0.5300, terminal wealth down at every percentile), `PIN_REVISION` bumped, and
  DECISIONS 2026-09-07 records it. The 4.65% interest rate and the four-category disregard list are
  **STATED, not verified** (no web in this session) and both reach a projection: see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§28), carded as **0129**. Built in a worktree, so
  the new builder checkbox and the new results note **have not been seen in a browser**. One gap
  found and carded rather than fixed: **0130**, the care assessment values home equity without
  deducting the SMI charge, although every other reader of equity nets it.
- **Marital status must now be chosen, a will is never assumed, and the first death applies the
  intestacy rules.** Card 0054. Marital status defaulted to married in the form, in the DTO and in
  the assembler, and was disclosed nowhere; a will was assumed too, so the first death always got a
  full spouse exemption. `Household::$relationshipStatus` is nullable with no default, read through
  `relationshipStatus()`, required of a couple in the builder, and disclosed by `assumedFigures()`
  where a stored scenario carries no answer. `Person::$hasWill` defaults FALSE, which is the adverse
  answer and the honest one, and `InheritanceTaxCalculator::computeFirstDeath()` is the one home of
  what the survivor takes: the whole estate with a will, the statutory legacy plus half the residue
  under intestacy, either capped at the nil-rate band where the survivor is not a UK long-term
  resident (`Person::$ukLongTermResident`, null = not asked, disclosed). `compute()` takes
  `$nilRateBandUsedAtFirstDeath`, so only the UNUSED band transfers; without that the same band
  would be handed out twice and intestacy would be near enough a no-op. `Household::$marriageDate`
  is captured, stored sparsely, and read by nothing (card **0128**). **Every stored plan that models
  Inheritance Tax pays MORE until its will boxes are ticked**, so `ENGINE_VERSION` is
  `finance-engine/intestacy-on-the-first-death` and the **stored-scenario re-run is owed**. Every
  figure and rule in it is **STATED, not verified** (no web in this session): see
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§27), carded as **0127**. Built in a worktree, so
  the four new inputs and the two new notes **have not been seen in a browser**.
- **Selling the home no longer deletes the residence nil-rate band.** Card 0053. The band was
  capped at the home owned AT DEATH, so a sell-and-rent plan died owning nothing and got a band of
  nil while a sell-and-buy-cheaper plan was capped at the cheaper home: every downsizing option was
  penalised by tax the statute is written to prevent, on the one comparison this tool exists to
  run. A sale is now a recorded fact (`Dto\ResidenceDisposal`, the household's own interest in the
  home less what was secured on it, plus the year), set by `HousingComparison` for both year-0 sell
  variants and by `PathProjector` at a forced sale, which keeps it on `state['residenceDisposal']`.
  `InheritanceTaxCalculator::downsizingAddition()` is the one home of the rule: the lost band is
  `min(disposal, max band) - min(home at death, max band)`, capped at the non-home assets passing
  to descendants, and the TAPERED allowance is then the ceiling on the home and the addition
  together. That last order is what leaves everything else alone: with no disposal the expression
  collapses to what the calculator already had, so a stay-put plan is byte-identical and
  `GoldenMasterTest` did not redden. **Every stored sell plan that models Inheritance Tax paid too
  much**, by up to £70,000 a band, so its estate is understated. The reported addition is only
  the part of the band it supplies after the taper (#4), so an estate over £2m never shows it
  beside a smaller band. `ENGINE_VERSION` is `finance-engine/downsizing-addition-reported-after-taper`
  and the **stored-scenario re-run is owed**. The rule is
  **STATED, not verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
  (§26), carded as **0125**. Built in a worktree, so the new results copy and the PDF line **have
  not been seen in a browser**. Two gaps carded rather than fixed: **0124**,
  `HousingComparison::rentSettings()` rebuilds `ForecastSettings` by hand and drops six fields, so
  the rent leg models no Inheritance Tax at all whatever the reader chose (this card's test had to
  supply its own settings to see the criterion); and **0126**, a home sold BEFORE the base year has
  no builder field, so a household that already downsized gets no addition and is not told why.
- **A shortfall now says what kind of bill it is, and a failing plan is pointed at free debt help.**
  Card 0052, folded out to [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) on 2026-09-08. What still
  stands: its panel and its fourth contacts column have not been seen in a browser, and every phone
  number, URL and legal citation in it is stated rather than verified.
- **The two Pension Credit additions are decided by entitlement, and a mixed-age couple is told
  why it gets nothing.** Card 0051. `Dto\DisabilityAwardRate` holds WHICH part of an award a person
  has, and `Person::qualifiesForSevereDisabilityAdditionAt()` is the one predicate both the
  severe-disability count and the carer test read, so a mobility-only or lowest-rate-care award buys
  neither where the bare flag bought both. The carer addition is now a COUNT
  (`PensionCreditCalculator::applicableAmountWeekly(… int $carers)`), so a couple who each care for
  the other get two; the projector used to stop at the first carer. `WarningCode::MIXED_AGE_COUPLE`
  is raised in every year one partner is under State Pension age, and
  `ResultPresenter::pensionCreditGuidance()` opens its panel on that as a third route beside an
  award and a near miss. **Only a mutual-carer household moves**, upward; the rate defaults to the
  qualifying care rate because that is what the flag's own docblock has always said it meant, so
  nothing else is anything but byte-identical (DECISIONS 2026-09-07 gives the reasoning, which
  deliberately declines the adverse default). `ENGINE_VERSION` is
  `finance-engine/pension-credit-additions-per-entitlement` and the **stored-scenario re-run is
  owed**. Built in a worktree, so the new builder select and the mixed-age panel copy **have not
  been seen in a browser**. Four of the same finding's items are **carded, not built**, all because
  each needs a statutory figure an unattended session cannot fetch: **0119** notional income on an
  undrawn pot (listed as a Known divergence in DATA-MODEL.md instead, which the card allowed),
  **0120** earnings assessed gross with no disregard, **0121** the SDP non-dependant test and the
  registered-blind route, **0123** a let property assessed as capital and as income at once. The
  sourcing defects are **0122**.
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
- **A pension-age renter is awarded Housing Benefit, and property capital is valued net of the costs
  of selling it.** Card 0048, folded out to
  [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) on 2026-09-08. What still stands: its
  `ENGINE_VERSION` bump owes a stored-scenario re-run, its two result notes have not been seen in a
  browser, and its four open gaps are board cards 0113, 0114, 0115 and 0116.
- **Council tax is its own cost line and it now shrinks.** Card 0047, folded out to
  [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) on 2026-09-08. What still stands: its two builder
  inputs and two result notes have not been seen in a browser, its four statutory figures are stated
  rather than verified (card 0111), and the gap it did not close is card 0112, a sell-and-rent plan
  charged no council tax at all.
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

## Card 0052, moved out of the live handover on 2026-09-08

Folded out to make room for card 0077 while keeping the live brief loadable in one session. It is
copy, no figure moves, and no stored run is owed for it.

**A shortfall now says what kind of bill it is, and a failing plan is pointed at free debt help.**
`ResultPresenter::priorityDebtGuidance()` owns the framing and rides the ladder array as
`priorityDebt`, so the results page and the PDF carry the same words beside the same unmet-spend
table. It reads the FIRST year the plan cannot fund its spending; secured is decided by whether that
year still owes a mortgage (`YearResult::mortgageBalance()`), so a renter is never told a home is at
stake. Both cases name mortgage and council tax as priority debts; a secured one adds the lender's
forbearance duty and the court's power to suspend possession. `sources-and-contacts.blade.php` grew
a fourth `showBenefitsDebt` column (Citizens Advice, National Debtline, StepChange, Turn2us,
Shelter), on wherever a plan runs short or holds a mortgage, mirrored in the PDF.

## Card 0047, moved out of the live handover on 2026-09-08

Folded out to make room for card 0076 while keeping the live brief loadable in one session. The
rule is built and nothing about it changes how a fresh session works; the gap it did not close
carries a board card of its own (0112), which is where a reader now meets it.

**Council tax is its own cost line and it now shrinks.** It sat inside `Property::runningCosts`
beside maintenance and insurance, and was charged at the full couple's rate for the whole
projection. `Property::$annualCouncilTax` holds it apart, `Property::$disabledBandReduction` holds
the band it is claimed from, and `Benefits\CouncilTax` owns the three reliefs and the order they
apply in: the disabled band reduction lowers the liability (charged as the band below,
`Dto\CouncilTaxBand` owning the statutory ninths), the 25% single-person discount comes off what is
left from the first death, and pension-age Council Tax Reduction meets the rest on a taper of 20% of
income above the applicable amount, passported in full on Guarantee Credit and nil above the £16,000
capital limit. The applicable amount is the Pension Credit one the engine already computes, which
the card directed. `YearResult::councilTax()` reports what was charged and the presenter reads it.
Council tax is deliberately NOT scaled by the ownership share: it is charged to whoever LIVES there.
No `ENGINE_VERSION` bump and no stored re-run was owed: a null bill is byte-identical, and every
stored scenario has one, so nothing moves until a reader splits the bill out. The
`council_tax_bundled` note is what tells them to. All four statutory figures are STATED, not
verified (no web in that session) and all four reach a projection: see
[docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§23), carded as **0111**. Built in a worktree, so
the two new builder inputs and the two new notes have not been seen in a browser. The gap this did
NOT close is card **0112**: a sell-and-rent plan is still charged no council tax at all, which
flatters renting in the one comparison the tool exists to run.

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
  illustration — the real lender ESIS of 2026-07-29 reproduces **within 21p at any row over 16 years**, both
  monthly instalments exact. Null terms = byte-identical, no migration. Closes the DATA-MODEL divergence.
- **Done 2026-07-29 — the V2 Stay-put base moved onto the real lender quote:** the base's hypothetical
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
  and **no lever rescues the real-quote mortgage** (YCC working 5 more years moves the shortfall 2036 → 2042; an
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
  every other plan (incl. the real-quote stay-put base and both £80k art-sale variants) fails at zero
  discretionary spend** — the stay-put mortgage leaves no holiday budget at all.
- **Done 2026-07-30 — the park-home option: a bought home that costs what it costs and LOSES value
  (DECISIONS 2026-07-30, [docs/build/PLAN-park-home.md](build/PLAN-park-home.md)):** two optional
  `HousingAction` fields (`buyRunningCosts`, `buyGrowthOverride` — the latter accepting **negative**
  rates), a `home_depreciates` honesty note, and four new scenarios (£150k dearer-end / £128k cheaper-end,
  each ± the £80k art sale). **Running costs raised £3,000 → £5,000/yr on 2026-07-30** once the home's
  own upkeep was researched (the pitch fee buys site maintenance only — DECISIONS 2026-07-30), which
  narrows the advantage: £128k cheaper-end + art sale **£994/mo** free spending vs sell-and-buy-cheaper's
  £865/mo, but **£693/mo without the art sale**, so sell-and-buy wins on both spending and estate unless
  the art is sold. The £150k dearer-end home cannot complete at all — no mortgage is available on a park home and the
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
