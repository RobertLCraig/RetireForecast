# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. **This doc holds what is true; [docs/board/](board/) holds what is moving.** Read [docs/build/PLAN.md](build/PLAN.md) for the full approved plan and scope.

**Stage:** active
**Category:** site
**Status:** **Feature-complete for personal use, and now carrying a large reviewed defect backlog.** The engine, the app, the post-v1 enhancement backlog, decision-support (Phases 0 to 6), the local assistant, IHT and the care means-test are all built. A five-discipline expert review on 2026-08-19 found defects across all of them, several of which change which plan the comparison ranks first. What remains is that backlog, Rob's **browser sign-off**, and the **public-release blockers**.
_Last updated: 2026-09-08 (card 0075). The exceptions a fresh session needs, newest first. The "what is built"
inventory and cards 0024, 0025, 0028 to 0038, 0044 and 0045 were folded out to
[docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) to keep this loadable in one session:_

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
  is the one home of the sentence that states the odds, read by the results page, Compare and the
  PDF, so no surface can describe a path the projection did not run. `ENGINE_VERSION` is
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
  the rewritten equity-release note **has not been seen in a browser**. One gap carded rather than
  fixed: **0131**, cards 0054 and 0055 bumped `ENGINE_VERSION` without adding their paragraph to its
  log, so the log's newest entry names a stamp two behind the constant.
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
  much**, by up to £70,000 a band, so its estate is understated. `ENGINE_VERSION` is
  `finance-engine/rnrb-downsizing-addition` and the **stored-scenario re-run is owed**. The rule is
  **STATED, not verified** (no web in this session): see [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
  (§26), carded as **0125**. Built in a worktree, so the new results copy and the PDF line **have
  not been seen in a browser**. Two gaps carded rather than fixed: **0124**,
  `HousingComparison::rentSettings()` rebuilds `ForecastSettings` by hand and drops six fields, so
  the rent leg models no Inheritance Tax at all whatever the reader chose (this card's test had to
  supply its own settings to see the criterion); and **0126**, a home sold BEFORE the base year has
  no builder field, so a household that already downsized gets no addition and is not told why.
- **A shortfall now says what kind of bill it is, and a failing plan is pointed at free debt help.**
  Card 0052. `ResultPresenter::priorityDebtGuidance()` owns the framing and rides the ladder array
  as `priorityDebt`, so the results page and the PDF carry the same words beside the same
  unmet-spend table. It reads the FIRST year the plan cannot fund its spending; secured is decided
  by whether that year still owes a mortgage (`YearResult::mortgageBalance()`), so a renter is never
  told a home is at stake. Both cases name mortgage and council tax as priority debts; a secured one
  adds the lender's forbearance duty and the court's power to suspend possession.
  `sources-and-contacts.blade.php` grew a fourth `showBenefitsDebt` column (Citizens Advice,
  National Debtline, StepChange, Turn2us, Shelter), on wherever a plan runs short or holds a
  mortgage, mirrored in the PDF. **No `ENGINE_VERSION` bump and no stored re-run is owed:** it is
  copy, no figure moves. Every phone number, URL and legal citation in it is **STATED, not verified**
  (no web in this session). Built in a worktree, so the panel and the column **have not been seen in
  a browser**.
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
