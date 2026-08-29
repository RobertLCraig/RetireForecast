# Decisions: RetireForecast

Append-only log of decisions and their rationale, newest first. Do not rewrite history;
supersede an old entry with a new one that links back to it.

## 2026-08-29: A one-off capital lump is judged apart from recurring spend, and is funded last
**Context:** card 0025 (expert panel 2026-08-19, engineer F3, reached independently by the property
reviewer). `ForecastResult::$fullSpendAlwaysMet` is all-or-nothing across a whole path, and an
unfunded home-purchase gap is charged as a year-0 one-off. That gap is a constant, identical on
every sampled path, so it produced unmet spend on 100% of them: the full-spend probability read
exactly 0.000 for plans whose ordinary spending was met in every single year, while essentials
read a clean 100%. Scenario 51 was the visible case, at 76.4% essentials against 0.0% full spend.

**Decision:** three things, together.

- **Recurring spend is funded before a one-off capital lump.** The year's shortfall is charged
  against its one-off lumps first (a documented one-off cost, and a mortgage redeemed from
  capital), in reverse declaration order. This is the same funding order `essentialsMet` already
  assumed; the alternative would have a purchase gap starve the food bill, which is not what a
  household does.
- **`YearResult::fullSpendMet()` judges the recurring budget only.** The lump is not forgiven: it
  stays inside `$unmetSpend`, so the net-position fan, the year-0 charge and `scenarios:audit`
  check 6 are untouched, and the year carries a `WarningCode::UNFUNDED_ONE_OFF_COST` naming the
  cost and the amount.
- **The all-or-nothing flags gain derived companions.** `ForecastResult::fullSpendYearsMetFraction()`
  and `essentialsYearsMetFraction()` are methods over `$years`, never stored fields, so they cannot
  drift from the flags. `SimulationResult::$successProbabilityFullSpendMostYears` reports the share
  of paths meeting the target in at least `FULL_SPEND_MOST_YEARS_THRESHOLD` (95%) of their years;
  it is `null` on a run stored before it existed and must show as a dash, never 0%.

**Why:** the model was behaving correctly and reporting it in a way nobody could read. A single
unfunded pound failing a fifty-year plan is not a conservative reading, it is an uninformative one,
and it was moving the ranked comparison. Naming the lump tells the reader the actionable thing
(which purchase has no money behind it) that a depressed probability never could. `ENGINE_VERSION`
bumped to `finance-engine/one-off-spend-split`: any full-spend probability stored earlier for a plan
with a funding gap is not comparable with one stored after. Card 0023 is confirmed by this rather
than fixed: scenario 51's residual gap is £1.37, not £46,412.
**Status:** active

## 2026-08-29: The assistant may assemble a what-if; the "never builds" rule is narrowed, not dropped
**Context:** card 0020 built Phase 1 of
[PLAN-assistant-scenario-editing.md](build/PLAN-assistant-scenario-editing.md), whose scope Rob
approved on 2026-07-04. That plan deliberately widens the doctrine set on 2026-07-03 (RESEARCH-local-assistant
§0/§3/A4: *"the model never builds anything, never edits code, never offers to build"*), so the
widening is recorded here rather than shipped quietly.

**Decision:** the assistant may turn **the reader's own stated figures** into a **proposed what-if**,
shown as a diff and written only on a confirm click, as an ordinary delta-child. Everything the old
rule protected still holds, and each part is now a named, tested guardrail:

- **C1 input grounding** (`ScenarioEditGrounding`): a figure not present in the reader's own words is
  refused. The model supplies no number, rounds none, and works none out. This is the input-side twin
  of G1, and it matters more: an invented input is forecast on, then comes back wearing the engine's
  authority.
- **C2 closed vocabulary** (`ScenarioEditVocabulary`): the model picks from a menu the app builds out
  of the base's own form-state. It cannot name a path the menu does not carry, so its agency is
  bounded structurally rather than by prompt.
- **C3 mandatory confirm**: proposing writes nothing at all. The confirm card is
  `WhatIfChanges::compute`, the same base-value → new-value diff a saved what-if is described by, so
  the reader checks the figures on the surface they already read what-ifs on.
- **C4 child only**: the base plan is never edited. Phase 3 base editing is **not built** and has no
  config key.
- Off by default behind its own switch, `config('assistant.can_edit_scenarios')`, so the explain-only
  assistant stays exactly that until it is deliberately turned on.

**Why:** the old rule protected three things: the model is never the source of a number, never
computes an outcome, and every write is reversible and human-reviewed. Creating a what-if breaks none
of them: it produces **inputs only**, all of them the reader's, and the existing engine still does the
forecasting. What stays ruled out is unchanged: the model never writes code, never predicts, never
sources a figure. A field the chat cannot reach is fine (chat is a subset of the builder); a field it
reaches wrongly is not, which is why the menu is pinned to `BuilderStateFixture::full` in test.
**Status:** active

## 2026-08-29: Multi-property leaves DRAFT — extend `Property`, keep the main home separate, Phase 1 only
**Context:** card 0019. [PLAN-multi-property.md](build/PLAN-multi-property.md) had sat as a DRAFT
proposal since 2026-06-30 (Lane D) with five open questions, so no code could honestly be written
against it. Four of the five are design questions the repository settles; the board's own rule
(`docs/board/README.md`, "Is this actually a person's to decide?") says an agent answers those and
records what it applied.

**Decisions:**
1. **Extend `Dto\Property` with the let-only fields rather than add an `InvestmentProperty` DTO**
   (`?Money $grossAnnualRent`, `?int $plannedDisposalYear`, `AcquisitionType $acquisition`), all
   nullable so every stored scenario stays byte-identical. *Rationale:* `Property` already carries
   value, ownership share, three mortgage shapes, running costs, growth override, `everLet`, `isLet`,
   `cgtHistory` and a redemption event — everything a let property needs. A second DTO would hold a
   **second copy of the mortgage exclusivity invariant** (`repaymentTerms` XOR `mortgageRollUpRate`),
   and two copies of an invariant is one that drifts. The draft's objection, that a residence would
   carry nonsense rent fields, is answered by making **`isLet` the discriminator** rather than
   `isPrimaryResidence`: a let-to-let main home genuinely does have rent.
2. **`primaryResidence` keeps its own slot; additional properties are a separate list.**
   *Rationale:* 43 references across 11 files, 16 inside `PathProjector`, whose property state is
   scalar throughout (`property`, `mortgageOutstanding`, `repaymentSchedule`, `propertyGrowthReal`,
   `ownershipShare`, `mortgageRollUpRate`). Unifying rewrites the hot loop for no behaviour gain and
   turns the residence's five special behaviours (PRR, RNRB, essential spend, means-test exemption,
   the thing buy-vs-rent sells) into per-row flags every consumer must re-test.
3. **An unsold additional property counts in total wealth and never in usable wealth.**
   *Rationale:* two independent reasons agreeing. It is already how the main home is treated
   (`SimulationResult::$usableWealthPercentiles` is "the spendable part (excl. the home)"), so no new
   rule and nothing new to explain; and of the plausible readings it is the **more adverse**, which is
   the standing rule for a modelling default. A "sell it if cash runs low" flag is Phase 2 and must be
   an explicit user choice.
4. **The standalone `rental` `IncomeStream` is kept, not retired; the overlap is made visible.**
   `Property::grossAnnualRent` null → the standalone stream is the source (today's behaviour, no
   migration); set → the property is. *Rationale:* retiring it would break a live scenario —
   `PathProjector::rentalIncomeNominal()` sums `IncomeStreamType::Rental` streams, and that sum is the
   base of the Section 24 finance-cost reducer for let-to-let (card 0021's scenario 43). A household
   may legitimately hold both a modelled let and a bare rent figure, so the engine must not throw;
   instead an input-sanity note and a new `scenarios:audit` check report the overlap with both figures.
   This is the one-definition-one-home rule applied as "a mismatch is a visible failure", which is what
   the project already does for imports.
5. **Scope stops at Phase 1, and it is blocked on cards 0029 and 0030.** *Rationale:* 0030 is building
   the letting-cost model (management, void, maintenance, service charge as a deductible letting
   expense) and 0029 is changing what a per-property growth override means in the Monte Carlo. Building
   multi-property first writes both a second time and then reconciles two copies — the failure the
   one-definition rule exists to stop. Cards 0027 and 0032 similarly own sale friction and leasehold
   selling costs, which a disposal here reuses.

**Also recorded:** the draft was stale in four ways that each make the feature smaller — Section 24 is
already modelled (2026-07-09), `Property` already amortises (2026-07-29), `isLet` already exists
(2026-07-02), and the letting-cost machinery is being built by the review backlog. The plan carries the
table.

**Not settled, and it is not an agent's to settle:** whether a second property exists to model at all.
The draft's motivating case is a property inherited and then let out; nothing tracked records one, and
what is recorded is a household owning one flat they live in, on a buy-to-let mortgage (2026-06-30).
That question is on card 0019 for Rob and decides whether the card is built or discarded.

**Status:** plan settled, nothing built. Card 0019 is the backlog entry; the old promise to fold this
into `PLAN.md` is dropped, because the board replaced that backlog and a second copy would drift.

## 2026-08-29: A forecast run is stamped for tampering and cached on its inputs
**Context:** card 0018, the CI and data-hygiene remainder. `threshold_results` already carried an
`inputs_hash` cache key; `simulation_runs`, the far more expensive computation, carried neither that
nor any integrity stamp. Two nullable columns on `simulation_runs` close both.

**Decisions:**
1. **A completed run is stamped with an app-key HMAC (`integrity_hash`) over its provenance and its
   stored figures.** *Rationale:* every screen, PDF and CSV in the tool reads a stored result and
   presents it as what the engine produced. Nothing checked that claim, so a figure edited in the
   database (by hand, by a bad migration, by anything) would be believed and printed. The stamp
   makes an altered result *evident*: `SimulationRun::isIntact()` re-derives it, and
   `php artisan scenarios:audit` now reports any completed run that no longer matches (check 8), so
   the same command that already gates a release on correct figures gates it on unaltered ones.
   *Why an HMAC and not a plain hash:* a plain sha256 can be recomputed by whoever did the editing,
   which makes it a checksum against accident, not against tampering. Keying it with `APP_KEY` means
   forging the stamp needs the application secret, not just database access.
2. **The stamp deliberately excludes the mutable lifecycle columns** (status, progress, timestamps,
   error). *Rationale:* those move for legitimate reasons: a cancel, a progress tick, a re-read. A
   stamp that fired on them would report tampering it had not found, and a guard that cries wolf is
   switched off. It covers what the run *claims* (scenario, mode, paths, seed, engine and tax-year
   stamps, inputs hash, frozen assumptions) and what it *produced* (every variant's result payload).
3. **A run is not recomputed when nothing about it changed:** `inputs_hash` is the cache key, and
   `SimulationRunner::preview()` / `dispatch()` hand back the matching run instead of running the
   Monte Carlo again. *Rationale:* "Re-run all" on Compare queued a fresh 10,000-path run per plan on
   every click, and a second click on a plan's own results page queued a duplicate beside the one the
   worker already had. Saving an edited scenario still deletes its runs (that stays the primary
   invalidation), so the hash is the belt-and-braces, and it catches the two things deletion cannot
   see: an `ENGINE_VERSION` bump, and an admin editing the assumption-set row a scenario points at.
   Which is why the hash covers the **frozen assumptions**, not merely the builder form-state.
4. **The seed is hashed as given, not as resolved.** *Rationale:* the app never passes a seed (one is
   drawn at random and recorded for reproducibility), so hashing the drawn value would make every
   request unique and the cache dead on arrival. An unseeded request therefore matches an earlier
   unseeded run; an explicitly seeded one asks for that seed and gets a cache entry of its own.
5. **Both columns are nullable, and a null is reported rather than trusted.** A run stored before this
   card has no hash: it is never served as a cache hit, and the audit calls it unverifiable rather
   than altered. Distinguishing "never stamped" from "changed since" is the honest reading.

## 2026-08-22 — Adviser parity: the ISA allowance is used, not just enforced; contributions are capped; a non-earner gets relief
**Context:** card 0016, the remainder of docs/build/PLAN-adviser-parity.md. A1 fee drag, A2 net-pay
relief, B1 cost of advice and B2 the protection gap shipped on 2026-07-31, along with the enforcement
half of A3. Three rules were left recorded in DATA-MODEL "Known divergences" as open, and this closes
them.

**Decisions:**
1. **The engine now USES the ISA allowance ("bed and ISA"), on by default.** This is the one that
   needed a call rather than a rule, because it is an **action** the household takes, not a limit the
   law imposes: the plan note that specced A3 flagged it as "a decision about whether the tool should
   assume the household takes it". It is modelled, and it is on. *Rationale:* leaving it out is not
   neutral. A sale's proceeds land in a GIA, so a plan that sells the home and invests was being
   charged dividend tax and CGT that the same household, in life, would simply not pay by moving
   £20,000 each into an ISA every year. The tool exists to rank selling against staying, and the
   error fell on one side of that comparison. Modelling nothing is itself a claim, and it was the
   wrong one. *Guards on it:* the allowance is shared with money paid in, so it cannot be spent twice;
   the transfer is a real disposal, sized to keep its gain inside what is left of the CGT annual
   exempt amount, so it never conjures a tax bill; and it is disclosed on the results page as an
   assumed figure that names the pounds the projection actually moved, so a reader can see and reject
   it. `ForecastSettings::$useIsaAllowance` turns it off for a household that would not do it.
   *Deliberate ordering:* it runs after the year's spending disposals, so in a year the exempt amount
   is already spent nothing moves. Spending has first claim on the allowance, which is the cautious
   way round.
2. **Relievable contributions are capped by the annual allowance as well as the MPAA.** Only the MPAA
   capped them before, and only after flexible access, so until a member touched a pension the
   projector paid in any amount asked for. One cap, in the one place a pot is credited
   (`payIntoPot`), counting the employer's contribution too, because the statutory limit is measured
   on total pension input. What the cap blocks depends on whose money it was, and this entry used to
   claim it was never given up: **corrected 2026-08-23 (card 0007).** A NET-PAY contribution stays in
   pay and is taxed there, and a SURPLUS-funded one stays in savings — neither is lost. An EMPLOYER
   contribution the cap blocks is **not paid anywhere else**: it never passes through the household's
   cashflow, so there is nowhere to put it and the plan simply loses it. That is the adverse side of
   modelling the limit as a hard cap rather than as a charge on the excess (card **0073**), and it is
   what `PathProjector::contributionHeadroom` and the reader-facing sentence in
   `PathProjector::mpaaWarnings` say. Pinned by
   `PathProjectorTest::test_an_employer_contribution_the_mpaa_blocks_is_not_paid_anywhere_else`.
   *Not built, deliberately:* the high-income taper, because it needs adjusted and threshold
   income which the year's own contributions move, and `AnnualAllowanceCalculator` already prices it
   separately; and carry-forward, whose absence is the cautious side of the rule.
3. **The £3,600 non-earner route is modelled as its own relief method, not by opening relief at
   source.** `PensionReliefMethod::NonEarner`: the household pays £2,880 out of surplus and the pot
   receives £3,600, capped at the statutory basic amount and stopping at 75. *Rationale for a separate
   case:* every member under 75 has the basic amount whatever they earn, so it needs no earnings test
   and cannot under-relieve anyone. General relief at source cannot say the same, since it would give
   a higher-rate taxpayer 20% where they are due 40%, which is why it still throws rather than
   accepting the input and quietly short-changing it.

**Consequences:** every stored scenario holding a GIA now shelters money it did not before, so figures
have moved; `scenarios:audit` is clean and the whole suite is green. The results page gains one input
note. No builder control turns bed-and-ISA off yet, so a household that would not do it needs the
scenario key set by hand.

## 2026-08-22 — Two flagged v1 refinements closed: Pension Credit in the care charge, and CGT deemed-occupation absences
**Context:** card 0015, a list of six flagged v1 simplifications to pick off by value. None is a correctness
gap; each was a deliberate limit recorded in code and in docs/spec/METHODOLOGY.md "What we don't model".
Two of the six needed no figure this repository does not already hold, so they were built; the other four
are held open for the reasons at the end.

**Decisions:**
1. **A resident's Pension Credit is now assessable income for the care charge.** The care financial
   assessment takes income into account unless it is expressly disregarded, and Guarantee Credit is not
   disregarded. Being tax-free it never reached `$taxablePerPerson`, so the means test read a resident on
   the credit as having only their private income: the household banked the award as income and was never
   charged it back, while in life it is handed to the home. `PathProjector`'s care leg now adds the
   household award **split per living member** to each resident's assessable income. *Rationale for the
   split:* England assesses each resident individually, and a couple with one partner in permanent care is
   treated as two single people for the credit, so half a couple's award is the resident's own money.
   **Still open:** the couple award is not re-computed as two single awards (two singles get more than a
   couple), so a couple's resident share is if anything understated; and the severe-disability addition is
   not withdrawn on a placement. Guard: `CareMeansTestedChargeTest` asserts the care year's spend step
   equals the resident's own income **plus the year's reported credit**, less the PEA read from the
   tax-year registry that owns it.
2. **Allowed absences ("deemed occupation") are now entered as such and relieved.** The CGT wizard's
   occupation timeline gained three kinds of period beside "main home" and "let": away for any reason, away
   for a job elsewhere in the UK, and working abroad. `CgtHistory` carries the three month counts raw and
   `CgtPrivateResidenceCalculator` applies the statutory caps from `CgtParameters` (3 years any reason,
   4 years UK work, no cap abroad; TCGA 1992 s223(3), gov.uk HS283). *Rationale for that split:* the caps
   are statute and belong with the tax figures, but whether an absence qualifies is a question about the
   shape of the timeline, which a month-count calculator cannot see, so `HouseholdAssembler` owns the
   qualifying test (occupied before the absence, and returned to after it, the return excused for the two
   work absences, exactly as the statute reads). An absence that fails its test earns nothing, the same
   treatment as a let period. `CgtResult::deemedOccupationMonths` reports what survived the caps and the
   wizard shows it as "Away, still counted", so a capped allowance cannot look like an uncapped one.
   The previous workaround, where the wizard told the reader to mark a qualifying absence as "main home",
   is gone, and with it the risk of a hand-applied cap. All three counts default to 0, byte-identical to
   before. **Still open:** the rule that no other residence may be eligible for relief during the absence
   is not enforced (the engine models one home), and job-related accommodation is not distinguished.
3. **Four of the six are NOT built, and are held rather than guessed.** Age-conditioning of the care onset
   rate, a sex split of care *duration*, and the LA-versus-self-funder fee gap each need a modelling figure
   this repository does not hold (an age gradient, a female:male duration ratio, and the LA rate as a share
   of the self-funder fee). *Rationale:* the no-magic-numbers rule. A figure with no source and no
   verified-on date is indistinguishable from an invented one, and each of these moves a headline result.
   The annuitisation retirement-month override is held for a different reason: the engine buys the annuity
   at the start of the purchase year and pays a full year of income, so the pot already forgoes a whole
   year of growth on the money, and prorating only the income would make the model doubly adverse rather
   than more accurate. The faithful fix needs one convention for a mid-year event on an annual grid, which
   is the same call card 0036 must make about the retirement year.

**Status:** active (supersedes the "Pension Credit is not counted into the contribution" flag of
[[2026-07-08 — Care years are means-tested in the projection (supersedes the gross-fee flag of 2026-07-01)]],
and the "deemed-occupation absences are entered by hand" caveat of the 2026-06-30 partial-PRR build)

## 2026-08-22 — A big "export all to PDF" is built on the worker, one forecast at a time, and arrives as a zip
**Context:** card 0014. "Export all to PDF" rendered every ready forecast into ONE dompdf document inside
the web request, and dompdf holds the whole document in memory until it is written. Measured on this
machine, one request against a batched build:

| forecasts | one request | batched |
|-----------|--------------------|-----------------|
| 10 | 25.6s / 444 MB | 15.3s / 200 MB |
| 20 | 78.9s / 774 MB | 30.6s / 180 MB |
| 30 | 171.8s / 1,114 MB | 45.7s / 200 MB |

A request's memory grows about linearly with the count and its time grows faster than linearly, so the
cliff sits around 35 to 40 forecasts, where it meets both the 1512M PHP memory limit and the per-site
300s gateway timeout at once.

**Decisions:**
1. **Past eight forecasts the export is queued and built one forecast at a time**
   (`App\Export\ScenarioExport`, `App\Jobs\BuildScenarioExport`). *Rationale:* peak memory then costs one
   report whatever the count (flat at 10, 20 and 30) and time becomes linear, so the cost stops growing
   with a number the user controls. It is also three times faster at 30, because dompdf's cost per page
   rises with document size. The threshold sits well inside the cliff rather than at it: the count is the
   user's to grow and it must not be a surprise when it breaks. At or below eight the direct download is
   kept, because it has plenty of headroom and needs no worker.
2. **The batched export is a zip of one complete PDF per forecast, not one merged PDF.** *Rationale:* a
   single file can only come from a single render, which is the thing that does not scale. Merging
   pre-rendered files would need a new PDF library (nothing installed can do it), where a zip needs only
   PHP's own `ZipArchive`, and one file per forecast is separately openable and shareable. Reversible: if
   one file is wanted later, that is a dependency decision, not a redesign.
3. **The reports are assembled by `App\Export\ScenarioReport`, shared with the direct download.**
   *Rationale:* a queued export must print exactly what a direct one prints; one assembly, one home.
4. **The dashboard shows the build running, ready, or failed with its reason.** *Rationale:* the
   no-silent-failure rule. A minutes-long render that reports nothing is indistinguishable from a broken one.
5. **A built archive is deleted when the account is erased.** *Rationale:* it is a file outside the
   database, so no foreign key cascades to it, and "erased means gone" has to stay true.

**Status:** active

## 2026-08-22 — A nominal-pounds view reads the engine's pre-deflation year, never a re-inflated one
**Context:** card 0013, deferred from slice #3 of
[PLAN-output-inflation-and-charts.md](build/PLAN-output-inflation-and-charts.md). Every reported figure is
real today's money, which is correct and comparable but counter-intuitive: a couple pictures "£X in 2045",
not "£X in today's money in 2045". The projector already works in nominal pounds internally and divides by
the price level on the way out.

**Decisions:**
1. **Each `YearResult` carries `$nominal`, the same year built from the same nominal integers before that
   division.** *Rationale:* the cheap alternative was to multiply the reported real figure back up in the
   presenter, and that recovers a number the engine never held. Deflation rounds to the penny, so
   re-inflating a rounded figure drifts from the projector's own arithmetic, and every reconciliation the
   charts are held to (legs summing to the total, sources summing to gross) would then be checking a figure
   the engine cannot vouch for. One extra assembly per projected year costs about 4% of that year's work,
   measured, so there is no flag to gate it and the deterministic and Monte Carlo paths stay identical.
2. **The toggle covers the three time-series charts, not the whole page.** *Rationale:* that is the scope
   slice #3 specified, and a page half in one basis and half in the other, without the reader being told
   which is which, is worse than a page consistently in real terms. The prose beside the charts names the
   basis in force, and warns on the nominal view that later figures look bigger because prices rise.
3. **A forecast whose years carry no twin cannot offer the view at all.** `timeSeriesCharts()` returns
   `nominalAvailable: false` and the real figures, and the toggle is not rendered. *Rationale:* the no-
   invisible-figures rule cuts both ways. Showing real money under a nominal label is the same defect as
   showing a figure the user cannot see.

**Status:** active

## 2026-08-19 — A "fill the bands" pension draw is a UFPLS, and flexible access caps what can go back in
**Context:** card 0007, the last two slices of [PLAN-withdrawal-sequencing.md](build/PLAN-withdrawal-sequencing.md).
`fundShortfall`'s draw closure took pension money gross and taxed 100% of it, applying the 25% tax-free
element only through an explicit withdrawal instruction. The spec called that a conservative baseline; the
2026-08-19 adviser review called it a modelling error, because it mispriced pension wealth against every
other asset and so biased every housing comparison towards realising property equity.

**Decisions:**
1. **An ad-hoc FillBands pension draw is modelled as a UFPLS from uncrystallised funds** (25% tax-free up to
   the Lump Sum Allowance, 75% taxable), not as fully-taxable drawdown. *Rationale:* it is what a retiree
   drawing ad-hoc from an uncrystallised pot actually does, and the alternative is not conservative, it is
   wrong by roughly five points of tax on every pension pound. On the pinned test household lifetime tax
   falls from £103,538.10 to £81,749.09. The draw itself is a second closure rather than a rewrite of the
   first, so `TaxEfficient` / `PensionAware` and the HMRC worked examples keep their figures.
2. **One home for the split, one ledger for the allowance, and one home for the headroom.**
   `PathProjector::ufplsSplit` is the only place the 25% rule lives, shared with `plannedWithdrawals`, and
   both routes spend the same `$state['lsaUsed']`. A member whose allowance is already gone gets the old
   fully-taxable draw **to the penny**, which is the pinned regression guard. Sharing the split was not
   enough on its own: HOW MUCH allowance a given pot's draw may spend is a second question, each route
   answered it for itself, and that is how the inherited-pot exclusion (decision 7) came to be written into
   the ad-hoc closure and missed in the planned one. `PathProjector::lsaHeadroom` is now its one home and
   both routes ask it.
3. **Flexible access caps later money-purchase contributions at the MPAA.** Otherwise a plan could draw a pot
   down in the free bands and recycle the cash straight back in, which the law does not allow. Modelled as a
   hard cap on what may be paid in rather than as an annual-allowance charge on the excess. In the TRIGGER
   YEAR itself, whether it bites depends on which of the three contribution routes the money took, which is
   an artefact of `projectYear`'s order rather than a rule: the employer and net-pay routes are paid before
   the withdrawals that set the trigger and so escape it, while `applyContributions` (the surplus-funded
   route) runs after them and is capped in the trigger year. In life the cap applies to every contribution
   paid after the trigger DATE, whichever route it took. Both are carded (0073) rather than merely noted,
   both are flagged on `contributionHeadroom`, and both halves of the timing are pinned by a test so
   re-timing them reddens the suite instead of moving quietly. **Where blocked money goes depends on whose
   it was, and one of the three answers is "nowhere".** A net-pay contribution stays in pay and is taxed
   there; a surplus-funded one stays in the surplus and is saved as cash; but the EMPLOYER's never passes
   through the household's cashflow, so it is simply not paid and the plan is that much poorer. That is the
   adverse side of the hard cap (in life it would be paid and charged), and it is what the docblock and the
   reader-facing warning now say, having both previously promised the money landed somewhere. Pinned by
   `PathProjectorTest::test_an_employer_contribution_the_mpaa_blocks_is_not_paid_anywhere_else`.
4. **The trigger belongs to the draw, not to the draw ORDER — so `$drawPension` had to change after all.**
   The plan's #5 fenced `$drawPension` off to keep `TaxEfficient` / `PensionAware` byte-identical, and slice
   #5 honoured that. It was wrong: taxable drawdown out of an uncrystallised pot is flexible access whichever
   order asked for it, so only `FillBands` carried the cap and the optimiser compared its three candidates on
   unequal terms — the fence broke the very criterion it sat inside. `$drawPension` now sets the trigger too.
   *Consequence, recorded rather than hidden:* a member still being contributed to is restricted under the
   default order as well, so a scenario with both a working member and an ad-hoc pension draw has moved.
   The HMRC worked examples are unaffected (they contribute nothing after the draw).
5. **An INHERITED pot does not trigger the heir's MPAA.** Beneficiary drawdown is not a member trigger event:
   the heir has not flexibly accessed a pension of their own. Without the distinction a still-working survivor
   who drew £10,000 of an inherited pot lost £50,000 of their own annual allowance for the rest of the plan,
   silently and at any age, because an inherited pot carries access age 0. One flag on the pot, checked in the
   one place the trigger is now set (`PathProjector::triggerFlexibleAccess`).
6. **The optimiser extends the existing comparison rather than sitting beside it.** A sibling class would have
   re-run the two forecasts the results panel already needs; `WithdrawalStrategyComparison` now runs its whole
   bounded `CANDIDATES` set once and reports the cheapest. Every saving stays the difference of two of the
   engine's own runs, never a re-derivation. The panel's two tiles are `CURRENT` and `ALTERNATIVE`, both named
   through one `label()`, and a test refuses to let them become the same order. *Hand-off:* whoever changes the
   displayed default (card 0075) must also pick the new `ALTERNATIVE` **and reword the panel's closing note**,
   which describes what fill-the-bands does in prose. No test can see that prose go stale — the name guard only
   catches a hard-coded label — so it is written on `WithdrawalStrategyComparison::ALTERNATIVE` and here.
7. **An inherited pot carries no tax-free quarter either.** Decision 1 gave every FillBands pension draw the
   25% UFPLS split, and the closure applied it to whatever pot it touched — including the one the estate pass
   hands the survivor. Beneficiary drawdown is the deceased's fund under its own regime, not a pension of the
   heir's, so there is no tax-free cash in it and the heir's own Lump Sum Allowance (and, through
   `deathBenefit['lsaUsed']`, their death-benefit allowance) must not pay for it. On the pinned household the
   heir was avoiding £22,817.68 of lifetime tax on money that was never theirs, silently, and on the ordinary
   path now that the optimiser runs FillBands for every scenario. Zero headroom makes the split all-taxable,
   which is exactly the old draw, so the fix is the same one flag decision 5 added. *Not settled here:* where
   the member died under 75, beneficiary drawdown is in life tax-free income rather than fully taxable. The
   model charges full income tax on it, as it always has under `$drawPension`; that is the cautious side, it
   is unchanged by this card, and it is board card **0079** rather than a note here, because
   `PathProjector::settleEstates` stores no age at death so the two cases cannot currently be told apart.
8. **The candidate set stops at the three named orders, and that limit is carded rather than hidden.** The
   plan's #6 also describes a "manage taxable income to £X" candidate. It is not built: #6 says to confirm the
   candidate set with Rob first, and decision 1 of 2026-07-01 ruled out a general planner in v1. So the search
   reports the cheapest of the orders the tool can actually run — which is what the panel says — and board card
   **0078** owns widening it, carrying the two calls only Rob can make.
9. **`ENGINE_VERSION` → `finance-engine/pcls-crystallisation`** (via `finance-engine/ufpls-fill-bands`).
   Decisions 1, 4 and 11 each move stored figures (a fill-the-bands run pays less tax; a default-order run
   with a working member contributes less; a plan with a lump sum pays more), so a result stored before this
   card and one stored after are not comparable and must not carry the same stamp.
10. **The MPAA is disclosed to the reader in the year it starts, as an assumed figure.** Decision 3 has the
    model apply a statutory cap nobody entered, which from the trigger on shrinks what the contributions they
    DID enter buy. The only place it was ever stated was the lump-sum tax-shock panel, and that panel needs a
    PLANNED withdrawal instruction to say anything at all, so on an ordinary plan (a draw taken to meet a
    shortfall) the cap bound and no screen mentioned it. That is exactly the no-invisible-figures rule.
    `PathProjector::mpaaWarnings` emits one warning in the trigger year, carrying the allowance read from the
    statutory constant, and `ResultPresenter::assumedFigures()` surfaces the engine's own sentence plus the
    year. Not emitted where the member pays nothing into a money-purchase pension, because a cap on what may
    be paid in changes nothing for them and the note list is only useful while everything on it bites.
11. **Tax-free cash CRYSTALLISES the rest of the pot, so no pound gets a second quarter.** Decision 1 gave
    every FillBands draw the 25% split and the closure asked only how much allowance was left, never whether
    the money had already had its quarter. Taking £100,000 of tax-free cash crystallises £400,000: £100,000 is
    paid out and the other £300,000 is designated to drawdown. The projector reduced the pot by the cash taken
    and nothing else, so a later fill-the-bands draw split that residue 25/75 all over again — on the pinned
    household, £14,436.41 of lifetime tax avoided on money that had already been relieved, and on the ordinary
    path now the optimiser runs FillBands for every scenario. Pots now carry a `crystallised` balance, one
    `PathProjector::drawFromPot` keeps it right at all six draw sites, it grows with the pot so the SHARE
    holds, and `ufplsSplit` / `maxUfplsGross` take it. **Crystallised money is drawn first:** it is the
    cautious order (pro-rata and uncrystallised-first both hand out tax-free cash sooner) and it matches what
    a member with a drawdown fund beside an uncrystallised pot would be charged. *Not settled here:* a
    STARTING pot is treated as wholly uncrystallised, because `DcPension::$pclsTakenToDate` is an allowance
    ledger across all of the member's pensions rather than a record of what this pot crystallised, so the
    split cannot be inferred from it without inventing one. Board card **0080**.
12. **A pot is crystallised BEFORE the cash is paid out of it, so a repeated lump sum cannot undo the
    first one.** Decision 11 designated the residue after debiting the cash, and `drawFromPot` takes
    crystallised money first, so a SECOND lump sum took its cash out of the residue the first one had left
    behind. £50,000 of drawdown money turned uncrystallised again per extra row, and the next
    fill-the-bands draw took a quarter of it: on the pinned household, £2,500.00 of lifetime tax. The whole
    `cash / 25%` slice is now designated first and the cash comes out of it, which is also the true-to-life
    order. Pinned by `PathProjectorTest::test_two_lump_sums_crystallise_as_much_as_one_of_twice_the_size`,
    whose tell needs no magic number: one £100,000 lump sum and two £50,000 ones in the same year must pay
    identical tax. `ENGINE_VERSION` → `finance-engine/repeated-pcls-crystallisation`; this moves figures
    under **every** draw order, so it is the second recorded breach of the plan's `TaxEfficient` /
    `PensionAware` fence (the first is item 4).
13. **The lump-sum tax-shock panel obeys the same crystallisation rule as the forecast.** The 25% rule has
    two implementations on purpose, `TaxFreeCashCalculator::split` in Money for the panel and
    `PathProjector::ufplsSplit` in integer pence for the year loop. Only the projector learned about
    crystallised money, so a UFPLS planned after a lump sum on the same pot was wholly taxable in the
    forecast and a quarter tax-free on the panel: one withdrawal, two answers, out of one engine. The
    calculator takes the crystallised slice too, `LumpSumTaxShock` works it out from the earlier lump-sum
    rows on that pension, and `TaxFreeCashCrystallisationParityTest` holds the two implementations to the
    same answer across a grid so the next change to either is caught here. *Accepted limit:* like the rest
    of that panel it reads the entered pot value and ignores growth between now and the withdrawal age;
    the full forecast is what models the balance year by year.
14. **The tax the optimiser ranks on includes the tax paid at DEATH.** `WithdrawalStrategyComparison`
    summed `YearResult::$totalTax` and threw away `ForecastResult::$iht`, which the same run computes
    and the same results page prints beside it. Both tiles then called their figure "tax paid across
    the plan", and the steer turned the gap into "the order to lean towards for tax". On the rich test
    household the discarded death tax is £424,550.09 against £99,233.52 of yearly tax — four times the
    number the winner was being picked by. It misleads two ways: an order paying less income tax ends
    with more wealth, so the estate hands roughly 40% of the "saving" straight back; and for a death
    before pensions come into the estate a pension is outside it while an ISA is inside, so the draw
    order decides which pot survives to be taxed and that swing can invert the ranking outright. Both
    figures are real (today's money) and come from the same run, so they simply add. *What makes least
    tax the right thing to rank on at all* is that the spend target does not move with the draw order,
    so tax not paid is money left in the plan — minimising total tax is exactly maximising what is
    left. **The panel now states what is counted**, in both templates and either way round, because
    "tax paid across the plan" cannot be read without knowing whether it stops at the last living year.
    *Not settled here:* an order that RUNS OUT breaks the equal-spend premise — it stops drawing, so it
    stops paying, and it could be named cheapest while funding the least. Board card **0081**.

**Status:** active

## 2026-08-12 — Reporting Monte Carlo results: spendable money, not total wealth
**Context:** a graph-led report over the full 10,000-path sweep of every scenario. The engine already
keeps two wealth series apart (`fanChart` / `terminalWealthPercentiles` include the home;
`usableFanChart` / `usableWealthPercentiles` exclude it) and says why in `SimulationResult`'s own
docblock. Drawing the first one made a failing plan look like a succeeding one.

**Decisions:**
1. **Any "will the money last" chart plots the USABLE series.** *Rationale:* stay-put ends with £0
   spendable and a £384k flat. On total wealth its line *rises* with house prices while the cash is
   running out, which is the exact misreading the two-series split exists to prevent. The stay-put
   fan on the usable series collapses to zero around 2035 and flatlines, which is the truth.
2. **A terminal-wealth chart must show the split, not the total alone.** Each bar is the estate with
   the spendable part drawn solid and the rest pale. A single £384k bar is defensible arithmetic and
   a misleading picture; the same rule as the repayment-mortgage instalment under "no invisible
   figures". A number the reader cannot decompose is one they will read wrongly.
3. **Small multiples share one y-axis.** Four per-chart axes on a page built for skimming read as one
   scale and flatter the worst plan (stay-put topped out at £46k beside park home's £324k).
4. **The fan's final year is NOT the terminal percentile** and the two must never be presented as one
   figure: the fan's last year contains only the longest-lived paths, so #9 reads £554,619 there
   against a £383,912 terminal median. Both are correct and they answer different questions.

**Also:** every scenario in a comparison sweep runs on **one common seed**, so differences between
plans are differences in the plan rather than in the draws.

## 2026-08-12 — The mortgage scenarios are priced off the latest broker email, and dead products are kept
**Context:** the 11 August "Revised amounts" email superseded the 5-6 August indications that
scenarios 55/56/58 were built on, and withdrew the RIO outright.

**Decisions:**
1. **Repriced in place rather than added alongside**: interest-only £208,000 @ 5.98% became £199,000
   @ ~6% (55, 56, 58) and the lifetime roll-up 8.99% became 9.16% (42). *Rationale:* the stored set is
   meant to be what is *available*, and the superseded figures are preserved in
   `SCENARIO-V2.local.md` plus a gitignored pre-repricing backup, so nothing is lost by not keeping
   a stale scenario live.
2. **The interest line is derived `loan × rate`, never typed in.** Two numbers that must agree should
   not be entered twice.
3. **A withdrawn product is renamed, not deleted** (57 → `WITHDRAWN 2026-08-11 …`). *Rationale:* the
   modelling stays available as a comparator, deletion is irreversible, and the name stops it reading
   as an option.
4. **A scenario whose headline does not move is verified, not assumed.** 42's terminal wealth is
   identical at 8.99 and 9.16%; projecting it at 8.99/9.16/14/25% shows the debt *does* move
   (2036: £279,401 / £283,789 / £386,618 / £386,618) while terminal wealth stays £60,822.11, because
   the roll-up passes the property value and the No-Negative-Equity floor caps equity at zero. The
   rate reaches the projection; it just cannot change that outcome.

## 2026-07-31 — The ISA subscription cap enforced, and a "known divergence" that had it backwards
**Context:** adviser-parity A3, the last OPEN correctness gap in DATA-MODEL. `applyContributions`
routed any amount into the ISA bucket with no allowance check.

**Decisions:**
1. **`IsaParameters` in the tax-year registry** (overall allowance £20,000 per person per year, plus
   the dated April-2027 cash-ISA cut to £12,000 for under-65s), sourced with `verified_on`, following
   the established per-tax-year pattern rather than a constant — the 2027 change lands inside the
   engine's horizon.
2. **The excess SPILLS to the person's GIA; it is never dropped.** *Rationale:* the household really
   would still save the money, just somewhere taxable. Discarding it would be the completeness failure
   this codebase has been bitten by before (a real input that stops counting), and it would make the
   household look **poorer** when the truth is that it is **more taxed** — a different and wrong error.
3. **The cap is on money paid IN, per person, per year.** A pot that grew past the allowance inside an
   ISA is legitimate and untouched; two ISAs for one person share one allowance.
4. **Measured before building, and the measurement corrected the record.** The DATA-MODEL entry claimed
   the bias was "largest for the highest-surplus sell-and-invest plans, so it is not neutral across the
   plans being compared". **That was wrong in direction and in size.** A sale's proceeds are invested
   into a **GIA** (`HousingComparison::withHousing`), and ordinary surplus banks to **cash**, so no
   housing variant ever sheltered anything through the missing cap; the gap only bit on an
   explicitly-entered ISA contribution above £20,000/yr, which no stored scenario has. Corrected in
   place, because a wrong severity in the divergence list mis-prioritises the next session.
5. **The larger half is now recorded as still open:** the engine never *uses* the allowance either. A
   household holding a large GIA would in reality bed-and-ISA £20,000 each per year, and the model does
   not — which **understates** the after-tax return of the sell-and-invest plans. Not built here
   because it is a modelled *action* (with a real CGT disposal), not a missing rule, and it needs its
   own decision about whether the tool should assume the household takes it.

**Consequences:** `TaxYearConfig` gains an `isa` group; `IsaSubscriptionCapTest` guards the cap and was
**verified to fail** with it removed. No stored scenario moves (none subscribes above the allowance).

## 2026-07-31 — What paying for advice would cost, built from the one figure that can be sourced
**Context:** adviser-parity B1, which the plan itself calls "nearly free once A1 lands" — now that the
engine charges investment costs, the cost of advice is the same projection run twice.

**Decisions:**
1. **The advised side is the plan's own charge PLUS the ongoing advice fee, and nothing else.** The plan
   drafted a ~1.80% "total cost of ownership" from the NextWealth 2026 report. Re-verifying it at build
   time (the plan's own ⚠️ rule) confirmed the **0.83% ongoing fee** on NextWealth's own page and in two
   trade reports, but the 180bp total appeared **only in search-engine summaries** that could not be
   fetched. *Rationale:* shipping it would have been a magic number wearing a citation. And the missing
   part is not merely unverified — how much dearer an advised fund choice is varies far too much between
   an in-house model portfolio and a whole-of-market tracker to assume for one household, so building
   the advised total from a guessed fund uplift would have meant **inventing the larger half of the
   number**. Constructing it as "your charge + the fee" makes the difference between the two runs
   exactly the fee, which is what the reader is trying to see anyway.
2. **The fee is NOT an `AssumptionSet` field.** It lives in `config/advice.php` with its source and
   `verified_on`. *Rationale:* an assumption set is what the projection assumes about the world; the
   forecast does not pay an advice fee, and putting it there would show it in the assumptions panel as
   though the plan were being charged one. It is the parameter of a side-by-side comparison, and the
   comparison is its only reader.
3. **Editable per scenario as a plain builder field** (`adviceFeePct`), stored **sparsely** — blank means
   "price it at the benchmark", so the benchmark is never frozen into a scenario and no scenario
   predating the field records a spurious what-if delta. A blank box must never read as "advice is free".
4. **Null when nothing is invested.** A percentage fee on no invested money is nothing on both sides, and
   "£0 either way" would read as "advice is free" when the truth is that an adviser would charge such a
   household a **fixed** fee instead. The panel simply does not render.
5. **Framing: a cost, not a verdict.** The panel states in its own words that advice costing X%/yr must
   add more than X%/yr of value, that whether it does is a question this tool cannot answer (the
   most-cited component of adviser value is behavioural and none of it is modelled), and that the cost of
   getting something wrong *without* an adviser is not modelled either. Also states that an initial /
   one-off advice charge is on top and unmodelled.

**Finding on the real household (V2):** advice costs this household very little — about **£1,278** over
the whole plan on the stay-put base — because it has almost nothing invested. The exception is
**sell-and-rent at ~£11,941**, the one plan whose proceeds are genuinely invested, and on the stay-put
base the fee moves the year the money runs short from 2036 to **2035**. Whether the panel is worth much
here therefore depends entirely on which plan is chosen, which is itself the useful output.

**Consequences:** new `config/advice.php`, `App\DecisionSupport\AdviceCostComparison`, a builder field, a
results section and its PDF twin, ASSUMPTIONS §11 and METHODOLOGY. Nothing touches the engine and no
stored figure moves.

## 2026-07-31 — The protection gap: what a death next year costs, and the cover that vanishes at retirement
**Context:** adviser-parity B2, and the plan's own next item after A1 and A2. The engine already
computed the survivor cliff (the secure-income floor before and after the first death, with five
levers), so it already knew the *size of the hole* — but it never named the instrument that fills it,
which is one of the few genuinely valuable things a protection adviser does. Two halves: an engine gap
(employer death-in-service cover was not modelled at all, so an early death of a working partner lost
the salary and gained nothing) and a presentation gap (the cliff was a percentage, not a sum of money).

**Decisions:**
1. **`Person::$deathInServiceCover`** (`?DeathInServiceCover`; null = no cover, byte-identical, and the
   adverse default). Stated as a **multiple of salary** or a **fixed sum assured**, because schemes
   state it both ways, and the difference is real: a multiple is sized on the salary in the year of
   death so it keeps pace with pay, while a stated sum is **nominal** and erodes. The multiple is held
   as a `Percent` (4x = 400%) rather than a float, under the engine's no-floats rule.
2. **Cover is conditional on being in employment, and that is the whole point.** The payout is recorded
   only in a member's last living year and only if they still work a fraction of it, so it **ceases at
   retirement**. Modelling it as a flat asset would have been simpler and would have hidden the one
   fact worth surfacing: the protection disappears exactly when the household can no longer replace it.
3. **Tax through the engine's one income-tax pass**, not a parallel calculation. Verified 2026-07-31
   against HMRC PTM073010 and gov.uk's lump sum allowance guidance: tax-free on a death **under 75** up
   to the member's remaining LSDBA (£1,073,100 less lump-sum allowance already used), taxable as the
   recipient's income above it and in full on a death at **75 or over**. The 45% special charge applies
   only to a non-qualifying recipient (a trust), which a surviving partner is not, so it is not
   modelled. *Rationale:* the same discipline as A2 — one definition, nothing to drift.
4. **Outside the estate for IHT; capital, not income, for the means test.** Registered-scheme
   death-in-service benefits are excluded from IHT, and were explicitly carved out of the April-2027
   measure bringing unused pensions in, so the payout is deliberately not added to the deceased's
   estate. For Pension Credit it is capital, so a new `$excludedFromAssessable` argument keeps it out
   of assessable income while the capital tariff catches the banked cash from the following year — as
   in life. **A payout can therefore end a survivor's Guarantee Credit**, which the forecast now shows
   (`DeathInServiceCoverTest`) rather than hides; it is the same trap the tool already surfaces on a
   house sale.
5. **The protection bar is the household's OWN plan, not an absolute one.** `ProtectionGap` bisects for
   the smallest lump sum that leaves the survivor's money lasting at least as long as the couple's plan
   already makes it last. *Rationale:* an absolute "must never run short" bar is unanswerable for a plan
   that already runs short, and would bill a death for a shortfall it did not cause. Solved sums round
   **up** to £1,000: a bisection on a step function (the year the money runs out) knows the answer only
   to within a step, and a protection figure rounded down would not quite do the job.
6. **Modelled through existing DTO fields, not a new projector mode.** The stress is
   `LongevityAdjustment::fixedAge` on one person; the solved cover is a `CapitalReceipt` (which is
   exactly what life cover written in trust is to a household: one-off, documented, tax-free, outside
   the estate). So a stressed path IS the ordinary projection, and no tax, benefit or drawdown logic
   can diverge between the two.
7. **Household rebuilding collapsed to one place.** Seven sweep levers each rebuilt `Household`
   positionally, so a field added to the DTO and forgotten in a lever would be **silently dropped from
   every swept forecast**. They now go through `withPersons()` / `withPensions()` /
   `withExpenseProfile()` / `withCapitalReceipts()`, all delegating to one private `copy()`, guarded by
   a reflection-driven `HouseholdWitherTest` that enumerates the DTO's own properties (so a new field
   is covered the moment it is declared) — **verified to fail** by dropping a field from `copy()`.
8. **Quantum only, never a product.** The panel sizes the hole and says what closes it; it does not
   price or recommend a policy, and it says out loud that cover on someone older or in poor health may
   be expensive or unavailable, and that less borrowing / more savings / a larger survivor's pension
   close the same gap.

**Finding on the real household (V2):** the exposure runs the **opposite way to the adviser reflex**.
The *working* partner's death leaves the survivor no worse off; the *retired, disabled* partner's death
is the damaging one — it removes their State Pension, their disability benefit and the couple's Pension
Credit while the survivor still carries the stay-put mortgage, moving the shortfall from 2036 to 2030
and needing about **£108,000** to restore the plan (about £110,000 on sell-and-rent; **£0** on
sell-and-buy-cheaper, which is immune). Insuring the earner would have addressed the smaller risk. No
stored scenario records any death-in-service cover, so **Rob has an input to enter** (see HANDOVER).

**Consequences:** new `YearResult::INCOME_SOURCES` key `death_in_service` (shown gross); a builder
input on step 1 for an employed person; a results-page section and its PDF twin; METHODOLOGY and
DATA-MODEL updated. Null cover leaves every stored scenario byte-identical, so no migration and no
re-run is required.

## 2026-07-31 — Pension contributions: net-pay tax relief, and the employer's money is the employer's
**Context:** adviser-parity A2. `PathProjector::applyContributions` took contributions from *net*
surplus and added no relief (the code's own docblock flagged it), so the engine modelled the cost of a
pension and none of its point. Reading that code for the fix surfaced two more defects in the same
place, both structural rather than a missing figure.

**Decisions:**
1. **`DcPension::$reliefMethod`** (`?PensionReliefMethod`; null = relief not modelled, so no stored
   scenario silently shifts). **Net pay is implemented; relief at source THROWS.** *Rationale:* relief
   at source is a real method with a genuine cashflow-timing effect (the provider reclaims basic rate,
   a higher-rate taxpayer recovers the rest through self assessment in a *later* year). Accepting the
   value and giving no relief would be worse than refusing it — the input would say one thing and the
   model do another. Refusing it is the no-silent-failure rule, as with Scotland and the mutually
   exclusive mortgage types.
2. **Net-pay relief is the subtraction itself, not a second calculation.** The contribution comes off
   gross earnings *before* they enter both the income-tax pass and the spendable-income total, which
   is what net pay physically is. *Rationale:* the plan's own warning — relief computed in a parallel
   pass can drift from the engine's one income-tax pass, which the data-layer integrity rule forbids.
   It also dissolves a circularity: a surplus-funded contribution depends on tax, which would depend
   on the relief, which would depend on the contribution. Money taken before the household sees it has
   no such loop. NI is untouched (`niForPerson` reads `grossSalary`), which is correct — net pay saves
   no NI; that is salary sacrifice, still not modelled.
3. **The employer's contribution is no longer funded from household surplus.** It is credited to the
   pot while the member actually works, prorated in a part-year. *Rationale:* it is the employer's
   money and never passes through the household's cashflow. Charging it made the household look poorer
   in cash, and — worse — a year with no surplus **silently dropped it**, which is the completeness
   rule's exact failure mode. Guarded by a test with a household that spends more than it earns and a
   pot locked below its access age (an accessible pot would be drawn straight back down, which is why
   the first version of that test passed for the wrong reason).
4. **A net-pay contribution is capped at pay**, so it stops by itself when the salary does.
   *Rationale:* it is deducted from pay, so it cannot exceed pay — which is also the statutory limit on
   relievable contributions for an earner. No separate retirement gate to forget. (Contributions were
   previously paid for ever out of a retired person's surplus.) **Still open:** the £3,600 non-earner
   route, and the annual-allowance / MPAA interaction (`AnnualAllowanceCalculator` exists and is not
   yet wired to contributions).
5. **An unset relief method is disclosed**, not left to look like an answer: a live contribution with
   no method raises a `no_relief_method` input note saying the full cost is charged and no tax given
   back. *Rationale:* the no-invisible-figures rule — a modelling choice the reader cannot see is
   indistinguishable from one we invented.

**Effect on the V2 scenarios: none, and that is the finding.** No stored scenario has any DC
contribution at all — member or employer, on any of the 14. So this work changes nothing for the real
household until that is checked: a still-working employee in a workplace scheme normally contributes
under auto-enrolment, and if it is simply not entered, the forecast is understating their pension (and
now its tax relief too). Raised as an open question for Rob rather than fixed by assuming a figure.

## 2026-07-31 — Six shipped assumption figures had never reached a single forecast
**Context:** measuring the new investment-charge work against Rob's real scenarios produced figures
**identical to the penny** before and after. The charge was not reaching the app at all. Root cause: the
app reads a scenario's assumptions from the `assumption_sets` **table**
(`ScenarioForecaster::assumptions()` → `$scenario->assumptionSet?->toDto()`), seeded once from
`AssumptionSetLibrary`. A figure added to the library afterwards is simply absent from the stored JSON
payload, where `AssumptionSetMapper::hydrate()`'s back-compat rule reads a missing key as `null`.

That back-compat rule is **right** for a frozen run snapshot (an old result must reproduce byte-identically)
and **wrong** for the live set, where the same `null` silently means "held at the pre-feature behaviour".
Auditing every key showed **six** shipped figures had never reached any of the 14 stored scenarios:

| Figure | Shipped | Actually in use |
|---|---|---|
| `houseGrowthVolatility` | 9% (11% DMS) | null — house growth deterministic |
| `houseEquityCorrelation` | 0.2 | null |
| `salaryGrowthVolatility` | 2% (2.5% DMS) | null — salary growth deterministic |
| `salaryEquityCorrelation` | 0.1 | null |
| `careCostRealGrowth` | CPI + 2% | null — care fees flat-real |
| `investmentCharge` | 0.50% | null — returns gross of charges |

So the stochastic house-price growth and stochastic salary growth of 2026-07-18, and the above-CPI care
escalation of the same day, had **never been active** in any Monte Carlo Rob has looked at, despite each
being built, tested, documented and recorded as shipped. The engine work was correct throughout; the figure
never arrived. This is the completeness rule's exact failure mode (a value that should affect a result and
does not), one layer further out than the tests reach.

**Decisions:**
1. **Re-seeded the three shipped sets** (`AssumptionSetSeeder` is idempotent by name). Verified first that
   the only differences were the six absent keys, so no admin edit was overwritten, and backed up the stored
   payloads before touching them.
2. **`scenarios:audit` gained a pre-flight check** for it: a stored set missing any key the shipped library
   defines is reported as a problem, with the seeder command to run. Only a *missing* key counts — the sets
   are admin-editable, so a differing value is a legitimate edit, not drift. Proved by `AuditScenariosTest`
   in both directions (a stale set fails; a freshly seeded one is clean), per the standing rule that a guard
   which cannot fail manufactures confidence.
3. **Not "fixed" by making `hydrate()` fall back to the library default.** The mapper is shared with the
   frozen run snapshot, where a null must keep meaning "reproduce the old run". Changing it there would
   silently rewrite stored history to fix a live-data problem.

**Measured effect** (3,000 paths, seed 424242, scenario 9): the terminal-wealth p10–p90 spread widens from
£338,965–£486,042 to £219,543–£688,165 — the housing risk that had been missing from every fan. Success
probabilities barely move (they turn on the essentials floor, which the home does not fund). Deterministic
figures move only by the charge (below). **Stored Monte Carlo runs are frozen snapshots and are unaffected;
a re-run will now differ, and should.**

## 2026-07-31 — Investment returns are no longer gross of charges (adviser-parity A1)
**Context:** the largest open correctness gap in DATA-MODEL: no platform fee, fund OCF or ongoing-charges
figure existed anywhere, so every projection assumed the household held its portfolio for free. Cost is the
most reliably predictable drag in the whole model — more certain than any return assumption — and it
compounds every year in the reassuring direction. A1 of docs/build/PLAN-adviser-parity.md.

**Decisions:**
1. **One household-wide `AssumptionSet::$investmentCharge`** (`?Percent`, null = no charge), threaded through
   `PathDraws::investmentChargeRate()` to all three drivers so the deterministic path and the Monte Carlo
   cannot disagree, and deducted in `PathProjector::growState` from each invested balance **after** growth.
   *Rationale:* the same proven null-safe opt-in shape as `houseGrowthVolatility` / `careCostRealGrowth`; no
   migration, and every stored run reproduces byte-identically. Per-account and per-pot overrides are
   deliberately **not** built yet — the correctness gap is the charge existing at all, and the plan's own
   ordering puts fee drag before refinement.
2. **Cash deposits bear no charge**; DC pots, ISAs and GIAs do. *Rationale:* a bank account has no platform
   or fund fee, so charging it would invent a cost. Consequence worth knowing: a plan that parks its surplus
   in cash is barely touched, while a plan that invests its proceeds carries the full drag — which is the
   point, not a flaw.
3. **The charge is reported in pounds, and growth stays GROSS of it.** New `YearResult::$investmentCharges`;
   `ResultPresenter::ladder()` sums it to a lifetime total shown beneath the table on screen and in the PDF.
   *Rationale:* netting it into `investmentGrowth` would satisfy the arithmetic and breach the no-invisible-
   figures rule — the reader would see a quietly smaller growth number and no charge at all. Carried
   separately, opening balance + growth − charges reconciles to the closing balance.
4. **Default 0.50% a year, and deliberately NOT the most adverse figure.** Evidence (all primary, verified
   2026-07-31): UK workplace DC defaults averaged **0.48%** member-borne (DWP Pension Charges Survey 2020),
   median AMC **0.28%** on providers' largest default funds (DWP Pension Provider Survey 2024/25); the
   statutory **0.75%** cap binds auto-enrolment defaults but **not** decumulation; retail DIY runs
   ~0.30–0.60% all-in. *Rationale for departing from the adverse-default rule:* the charge falls on invested
   wealth and not on housing, so it moves the sell-and-invest plans against the stay-put ones. The parameter
   is not monotonic in optimism — an over-adverse figure is a thumb on the scale of the comparison the tool
   exists to make, not a safe margin. Editable as the 8th economic assumption, with the range in
   ASSUMPTIONS.md §10. The *advised* cost stack (~0.83% ongoing advice on top) stays B1's separate
   comparison, not this default.

**Measured effect on the V2 scenarios** (deterministic, after the re-seed above): sell-and-rent (#50) now
runs short in **2042 instead of 2043**; terminal wealth falls ~£2,765 on the plans that hold invested money
(#42, #49, #52, #53, #54) and is unchanged on the plans that hold none. Lifetime charges: £814 on the
stay-put base, £2,404 on the lifetime-mortgage and sell-and-buy plans (their liquid wealth accumulates as
cash, so only the DC pot is charged), **£8,150 on sell-and-rent**, whose proceeds are genuinely invested.

## 2026-07-30 — Income is echoed back like spend; monthly beside annual; no sale talk without a sale
**Context:** three findings from Rob reviewing the rebuilt report, all applying to **both** the results
page and the PDF (his explicit scope): the spending plan gave annual figures only *"(its not like we dont
have the space for an extra column)"*; a **stay-put** plan was shown *"If you sell:"* mechanics it never
performs; and the tool showed what a plan **spends** in detail while never echoing back what **funds** it
— *"need to include income and where funding sources are and how they change over time"*.

**Decisions:**
1. **Every budget figure carries a monthly twin.** `expenseBreakdown()` now returns `amountMonthly` per
   line plus `subtotalMonthly` / `spendingTotalMonthly` / `savingTotalMonthly`. Monthly is rounded **per
   line and then summed**, never re-divided at the total. *Rationale:* dividing each subtotal by twelve
   independently lets the printed column disagree with its own rows by a penny or two — the
   reconciliation rule this project treats as a defect. A reader who adds the column must get the
   subtotal. Guarded in `ExpenseLineReconciliationTest` with a tier whose lines do not divide evenly.
2. **New `ResultPresenter::incomePlan()` — the income counterpart to the spending plan.** Three parts:
   the entered **income** sources (salary, DB, State Pension, annuity/rental/other, one-off receipts) with
   each one's start and stop per person; the **capital** pots (cash / ISA / GIA / Premium Bonds, DC pots,
   home equity) with what is paid in, when it can be reached and **how it is taxed on the way out**; and a
   **timeline** of how each source actually behaves in the projection — first year paid, last year, the
   amount at each end, its largest year — derived from the same `incomeBySource` the ladder and income
   chart read. *Rationale:* the spend side has been echoed since Phase C1 and the income side never was,
   so a reader could see the outgoings in line-item detail and had to infer the income. Deriving the
   timeline from the forecast rather than restating the inputs means it cannot disagree with the ladder.
3. **"No savings entered" is stated, not left as an empty table.** A household with no cash/ISA/GIA is in
   a materially different position from one whose accounts were simply not listed: it starts with nothing
   to fall back on, and any liquid wealth later in the projection is surplus income accumulating.
   *Rationale:* found while checking the real V2 base, whose £18,573.68 of 2026 liquid wealth is exactly
   its first-year surplus (£50,331.24 − £3,265.20 − £28,492.36) and not an opening balance at all.
4. **Sale content is gated on the strategy being shown, not on a sale being configured.** A base scenario
   carries a sale and buy price so Compare can run all three variants, so "is a sale configured?" was the
   wrong question: it handed a stay-put plan the funding waterfall, the selling-cost assumptions and CGT
   signposting for a disposal it never makes. All three now follow `LadderContext::homeSold()`.
   *Rationale:* irrelevant figures are not free — they invite the reader to plan around costs this plan
   does not incur. On screen the gate follows the ladder's strategy picker, so switching to a sell variant
   restores the section; in the PDF the printed strategy decides.
5. **The same gate governs the assumed-figure disclosures** (added on Rob's follow-up review of the
   printed report, which still carried *"we've assumed 1% of its value a year"* for a home the stay-put
   plan never buys). `ResultPresenter::housingActionFor($action, $variant)` is now the single home for
   the rule — the results page, the PDF **and `scenarios:audit`** all resolve the applicable housing
   action through it, so the audit demands exactly the disclosures the reader is shown. *Rationale:* the
   no-invisible-figures rule requires disclosing figures the model **uses**; disclosing one it does not
   use is the same failure inverted — it asserts a cost that is not in the projection. Guarded by a test
   verified to fail with the gate removed.

**Impact:** the stay-put report *shrank* by a page despite gaining a whole income section. Both surfaces
changed together, from one presenter definition.
**Status:** active

## 2026-07-30 — The PDF is a COMPLETE print of the results page, charts included
**Context:** Rob: *"Need to get all of the information in the webpage into the pdf download"* and, on the
shape of the report, *"the lack of graphs in the PDFs makes them very difficult to use for sharing as
intended"*. The PDF is how a plan reaches family and an adviser, so a digest is the wrong artefact — but
the export carried roughly a third of the screen. Missing entirely: the **input-sanity and assumed-figure
notes** (the "no invisible figures" disclosures), the what-if delta, longevity, care risk, the fan chart
and its percentile table, the interpretation panel, assumption sensitivity, Pension Credit how-to-claim,
the IHT distribution, withdrawal sequencing, the historical stress test, the assumptions panel, the
milestone timeline, the three time-series charts, and eleven of the cashflow ladder's columns. And **no
chart at all**, because dompdf executes no JavaScript and every chart on screen is an ApexCharts canvas.

**Decisions:**
1. **The export prints every section the screen renders**, assembled from the *same* `ResultPresenter`
   calls the Livewire component makes. Only genuinely interactive controls are omitted (run buttons,
   what-if sliders, the "How far can we go?" explorer — whose sliders open at a lever's mid-range and
   whose limits are queued on demand, so there is nothing to print until the reader drives it — and the
   assistant). *Rationale:* the displayed-figure provenance rule, and the hard "no invisible figures"
   rule in particular: a disclosure the reader cannot see in the artefact they were given is no
   disclosure at all.
2. **Charts are re-drawn server-side as vector SVG** by the new `App\Export\ChartSvg`, from the very
   ApexCharts option blob the screen chart is initialised with — not from a second data pipeline. So a
   printed chart plots the identical numbers; only the drawing differs. Verified that dompdf **ignores an
   inline `<svg>`** but renders `<img src="data:image/svg+xml;base64,…">` as true vectors (dompdf 3.1 /
   php-svg-lib 1.0), so the template embeds data URIs. *Rationale:* a browser-shot renderer (Browsershot
   / headless Chrome) would print the real canvases but adds a Node + Chromium dependency to a local-first
   tool and a second failure mode; re-drawing from the shared option blob keeps one source for the data.
3. **Milestone verticals are numbered, not labelled.** php-svg-lib's rotated-text support is unreliable,
   so each life-event vertical carries an index and a key line beneath the chart resolves it, matching a
   numbered "When the big events happen" table. *Rationale:* nothing is dropped, only moved — the
   alternative was losing the event markers or risking unreadable text.
4. **The report is A4 landscape.** The full ladder (income by source + the monthly and capital columns)
   and the chart-plus-table twins do not fit a portrait measure without shrinking the figures past
   readability. *Rationale:* this is a data report meant to be shared and read, not a letter.
5. **The print mirrors the screen's visual language, and the charts are page-width.** Rob's review of the
   first cut: *"the charts and general layout leave a lot to be desired… closer to the web view might be
   better"* and *"the graphs on the PDF are too small"*. So the template now uses the results page's own
   idiom — white cards per section, the coloured stat tiles the screen shows as `<dl>` grids, verdict
   pills, badges and the ladder's green/amber/red row tints — rebuilt as borderless layout tables because
   dompdf has neither flexbox nor CSS grid. Charts were redrawn at **1000×480** (10.4in — the full text
   width of the landscape page) instead of 720×320, with axis and legend type raised to 11px.
   *Rationale:* a report shared with family or an adviser is read, not just consulted; a postage-stamp
   chart and a wall of grid-lined tables fail at that even when every figure is present.
6. **Both fan bases print, not one.** On screen the fan's basis is a live "Include home value" checkbox;
   paper cannot be toggled. The report prints **two** charts — spendable money excluding the home, then
   total wealth including home equity — each with its own percentile table. *Rationale:* picking one
   would silently drop a view the reader can see on screen, and the two answer different questions
   ("will it last?" vs "what will we leave?").
7. **The ladder is split into two tables sharing the Year / Age(s) key.** All ~24 columns as one table
   overflowed the page and dompdf **clipped it**: the final "Total (incl. home equity)" column printed as
   `£225,5`. *Rationale:* a figure cut off at the paper edge is an invisible figure. dompdf does not
   shrink or wrap an over-wide table, so the fix is structural, not cosmetic.
5. **Ladder strategy selection moved to one home**, `App\Forecast\LadderContext`, used by the results
   page, its CSV export and the PDF. *Rationale:* it fixed a live divergence — the PDF read
   `$scenario->variant` directly while the screen clamps to a strategy the inputs actually configure, so
   a scenario stored as "sell & rent" with no sale price printed a *rented* ladder while the screen
   showed stay-put. This is the "`deterministic()` ignores the variant" trap for a third time; it now has
   a single guarded resolver.
8. **Completeness is guarded by derivation, not by a checklist**, and **clipping is guarded by position,
   not presence.** `ScenarioPdfTest` reads the results component's own view data and fails when a key the
   screen renders is absent from the export (short documented allowlist of interactive-only keys); a
   second guard decodes the rendered PDF's content streams, maps text back through the graphics-state
   transform into page coordinates, and fails when anything is painted past the paper edge.
   *Rationale:* a hand-written list of sections drifts the moment someone adds a panel. And the obvious
   clipping check — asserting the figure appears in the PDF — is worthless: **dompdf still writes text
   that overflows the page**, so a presence assertion passes while the reader sees nothing. Verified by
   inflating the ladder's font until it overflowed and confirming the guard fails.

**Impact:** one scenario prints ~27 landscape pages (was ~3), ~1.4 MB, ~1.3 s. **"Export all to PDF" is
now heavy:** at ~17 pages/scenario it measured 236 pages, 2.3 MB, ~38 s and ~538 MB peak against Herd's
1512 M limit and a 300 s gateway timeout, and the page count has since risen by roughly half again. It
works today with headroom, but memory scales roughly linearly with scenario count, so past ~20–30
scenarios it will need batching or a queued export. Flagged in HANDOVER open items rather than pre-solved.
**Status:** active

## 2026-07-29 — Pension Credit severe-disability addition: the couple-eligibility rule (+ carer addition)
**Context:** a review of the Pension Credit modelling (prompted by a benefits check for the private V2 household —
figures in the gitignored SCENARIO/BENEFITS docs) surfaced an error. `PathProjector::meansTestedBenefitNominal()`
set a single `$disabled` flag true if **any living** household member received a disability benefit, and fed it
straight into the **severe-disability addition (SDP)** flag of `PensionCreditCalculator` (the `carer` flag was
never passed). But the SDP couple rule (confirmed:
[Turn2us](https://www.turn2us.org.uk/get-support/information-for-your-situation/severe-disability-premium/can-i-get-a-severe-disability-premium),
[entitledto](https://www.entitledto.co.uk/help/disability-premiums-in-benefits), verified 2026-07-29) is that a
**couple qualifies only when BOTH partners** receive a qualifying disability benefit (or the other is registered
blind) — a non-disabled co-resident partner blocks it. So a couple with one disabled partner should get **£0**,
not the single rate the engine was adding.

**Decisions:**
1. **SDP eligibility now follows the household rule.** A *single* disabled pensioner qualifies (single rate); a
   *couple* qualifies only when *both* partners receive a qualifying disability benefit, and then at the **couple
   rate = 2× single** (`applicableAmountWeekly` doubles the addition for a couple). *Rationale:* accuracy-first —
   the old flag over-credited every one-disabled-partner couple, in the reassuring direction.
2. **The carer addition is wired.** New engine field `Person::caresForPartner` (default false — the cautious
   assumption: claimed, not assumed); when a living member cares for a living partner who receives a qualifying
   disability benefit, the projector passes the `carer` flag and the £48.15/wk carer addition applies. Underlying
   entitlement (the modelled route) does **not** remove the disabled partner's SDP — only *paid* Carer's Allowance
   would, which is not modelled. *Rationale:* for the common one-disabled-partner couple the correct addition is
   the carer one, not the SDP.
3. **App-builder exposure of `caresForPartner` is deferred.** The engine field defaults false, so no stored
   scenario or what-if child delta changes ([[new-builder-field-delta-gotcha]] bites only on non-empty defaults),
   and it is immaterial to V2 (below). *Rationale:* the correctness fix (SDP) flows through existing data with no
   app change; exposing the carer flag in the UI is a separate, low-value-for-V2 follow-up.

**Impact:** the fix removes the spurious both-alive-years SDP. For a one-disabled-partner couple whose combined
State Pension sits between the plain couple guarantee (£363.25/wk) and guarantee-plus-SDP, the model was awarding
Pension Credit in every both-alive year that should be £0; once a partner dies the survivor (not disabled) already
carried no SDP, so those years are unchanged. The carer addition is immaterial where both-alive income already
exceeds the carer-boosted guarantee. The quantified effect on the private V2 base (a material cut to lifetime
Pension Credit, confined to the both-alive years) is recorded in the gitignored benefits doc. Guarded by
`PathProjectorTest` (one disabled partner → £0; both → couple rate; a caring partner → carer addition) +
`PensionCreditCalculatorTest` (single vs couple rate; carer). Full suite green.

## 2026-07-30 — Park-home running costs raised £3,000 → £5,000/yr (the pitch fee is not the whole cost)
**Context:** Rob asked what the ongoing costs of a park home actually are. The scenarios modelled
**£3,000/yr — the pitch fee alone**, which buys site maintenance and communal facilities. The home's own
upkeep is the owner's, and the research shows it is not small.

**Decisions:**
1. **Model £5,000/yr**: £3,000 pitch fee + **£1,500** ongoing maintenance (mid of a sourced
   £1,000–£2,000/yr) + ~**£350** amortised exterior repainting (£1,500–£2,500 every 5–7 years).
2. **Re-roofing stays a separate £10,000 one-off in 2041** (~£100/m², lasting 20–40 years) — it lands in
   the survivor years, which is exactly the lump a fixed income cannot absorb.
3. **Chassis work (£1,200 clean-and-paint, ~£3,750 if corroded) and underfloor insulation
   (£1,800–£2,400) are NOT modelled** — occasional and condition-dependent. Flagged as the reason to get
   a structural survey before buying, rather than folded into an annual average.
4. **Utilities may be worse, not better.** Many parks have no mains gas, so heating is **LPG** —
   materially dearer than natural gas, and resold by the site owner (case law caps the charge at the unit
   price the site owner paid). Not modelled as a separate figure; flagged. **Offsetting:** park homes are
   usually **council tax band A**, so the scenarios carrying the flat's £2,004 forward are conservative.

**Consequences — this narrows the park home's advantage materially, and the earlier claim was
overstated.** Free-spending capacity falls: £128k + art sale **£1,235 → £994/mo**, £150k + art sale
£1,109 → £930, £128k without the art sale **£931 → £693**. Against sell-and-buy-cheaper's £865/mo that
means: **with** the art sale the park home still wins on spending (sell-and-buy still leaves the bigger
estate); **without** it, sell-and-buy-cheaper now wins on both. Freed outgoings vs staying put fall from
~£1,750/mo to **~£1,584/mo** — still above the ~£1,300 Rob expected.

## 2026-07-30 — Let-to-let BTL repriced 6.5% → 5.75%; the depreciation rate is decided, not open
**Context:** Both figures had been researched and given sourced defaults, then wrongly filed in the
handover as open questions for Rob. He had explicitly asked me to research them, and the standing rule
([[adverse-default-user-editable]]) is to research, default to the most adverse defensible figure, and
expose it as editable — never to hand the call back. Correcting that surfaced a wrong assumption.

**Decisions:**
1. **The let-to-let BTL rate is repriced 6.5% → 5.75%.** The 6.5% rested on an inferred "later-life
   specialist premium" that **does not exist**: buy-to-let is underwritten on **rental income (ICR),
   not the borrower's earnings**, so age is not the binding constraint it is for a residential loan.
   BM Solutions lends to **99**, several specialist lenders publish **no maximum age**, and specialist
   BTL pricing is competitive (Shawbrook single lets from 4.84%, TML 5-year fix from 4.74%, mid-2026)
   against a 4.38% best buy and their existing TMW BTL at 4.49%. **5.75%** — the market average 5-year
   fix — is ~1pt above the best buys: adverse without inventing a premium. Interest cover rises to
   181%, comfortably clear of a 125–145% ICR test.
2. **Park-home depreciation stays -8%/yr real, and is DECIDED.** A neutral UK index was searched for
   and **does not exist**: the government's own park-homes research is policy analysis (sector size,
   commission-effect modelling) and publishes **no price or resale series**. So the figure is a
   judgement between a campaigning source and a marketing one, labelled as such, with -3/-5/-8/-15%/yr
   shipped as selectable sensitivities.
3. **Neither is a blocker.** A better BTL figure needs only a real broker quote — and specifically for
   a **consumer** buy-to-let (letting your own former home), a narrower, more regulated market than the
   headline tables cover.

## 2026-07-30 — Hard rule: no invisible figures. Plus a permanent scenario audit
**Context:** Rob, after finding a £0 "Mortgage" line on the live results page for a plan that charges
£15,822/yr: *"the model shouldn't ever be able to use a figure that the user cannot see / interrogate
in some way."* Asked for the ad-hoc scenario sweep to become a permanent guard.

**Decisions:**
1. **New hard rule in CLAUDE.md: no invisible figures.** A default the engine supplies for itself is,
   to a reader, indistinguishable from a number we invented — and it moves their result.
2. **Two live violations found and fixed.** A bought home's upkeep (**1% of value a year**) and the
   cost of moving (**£2,000**) were private engine constants applied silently, with nothing on any
   screen. Both are now disclosed via `ResultPresenter::assumedFigures()` as `assumed_figure` input
   notes, stating the value, the resulting pounds and why it applies.
3. **A disclosure READS the constant that owns the figure, never restates it.** `HOME_MAINTENANCE_RATE_BPS`
   and `DEFAULT_MOVING_COSTS_PENCE` became public for exactly this. *Rationale:* a disclosure that
   drifts from the figure actually used is worse than none — pinned by a test that a bigger home moves
   the disclosed pounds.
4. **A computed figure on screen is labelled computed.** The repayment-mortgage instalment is the
   worked example (see the previous entry).
5. **`php artisan scenarios:audit`** — a permanent, runnable guard over the user's REAL saved
   scenarios, which a fixture-based test cannot reach. Seven checks: variant label vs modelled
   variant, orphaned overrides, a mortgage the reader cannot see, monthly figures reconciling every
   year, a depreciating home that doesn't disclose it, an unfunded purchase not charged, and every
   assumed figure disclosed. Exits non-zero so it can gate a release.
6. **The audit is itself guarded.** `AuditScenariosTest` proves it catches each defect, not merely
   that it passes — *"a guard that always passes is worse than none: it manufactures confidence."*

**A real bug the audit found immediately:** `Scenario::projectFrom()` defaulted the `variant` COLUMN to
**Rent** when the form-state carried no variant, while the forecast defaults to **stay_put**. Any such
scenario was labelled "Sell & rent" on every screen while being projected as staying put. Changed the
fallback to `StayPut` so the label agrees with the plan modelled. Rob's own scenarios all carry an
explicit variant, so none were affected — but the trap was live.

## 2026-07-30 — A bought home can cost what it costs, and can LOSE value (the park-home option)
**Context:** Rob asked to consider a park home between Wokingham and Tring. Research
([docs/build/PLAN-park-home.md](build/PLAN-park-home.md)) established that the *holiday*-park version is
not legally possible as a housing plan (a holiday home cannot be a main residence; the owner must be
registered elsewhere) but the *residential* park-home version is squarely in budget — and that the
engine could not model it at all: a bought home could only appreciate at the assumption set's house
rate, with running costs derived as 1% of value.

**Decisions:**
1. **Two optional `HousingAction` fields, not a new "park home" type.** `buyRunningCosts` (?Money) and
   `buyGrowthOverride` (?Percent, **may be negative**). *Rationale:* no new DTO or enum, it composes
   with everything already built (Pension Credit disregard, IHT, care means test), and the same two
   fields model a short-lease flat or any depreciating home. Follows the `buyMortgageRate` precedent.
2. **An explicit running cost REPLACES the derivation, never adds to it.** *Rationale:* a pitch fee is
   a flat annual charge unrelated to value; the 1%-of-value proxy understated it by £1,500/yr on a
   £150k home. Adding them would double-count upkeep — asserted against.
3. **Negative growth is a first-class case, and it must announce itself.** New `home_depreciates`
   input note stating the rate, what the home is worth by the end, and that the 10% sale commission is
   excluded. *Rationale:* a reader's mental model of a home is that it appreciates, so a quietly
   falling wealth line reads as a bug — or goes unnoticed. Same honesty treatment as the
   lifetime-mortgage roll-up.
4. **Default depreciation -8%/yr real, user-editable, with four sensitivities shipped.** *Rationale:*
   the evidence is weak and partisan — "90% over 10 years" (≈-20%/yr) comes from campaigning sites,
   "3–6%/yr" from manufacturer marketing and partly the US "park model" market. -8% is the
   adverse-but-defensible midpoint per [[adverse-default-user-editable]]; at that rate a £150k home is
   worth ~£22k after 23 years.
5. **A correction recorded against this plan's own earlier draft:** pitch fees are **CPI**-linked by
   statute, not RPI, since the Mobile Homes (Pitch Fees) Act 2023 (in force 2 July 2023). The engine's
   flat-real treatment is therefore already correct and the escalation limitation previously flagged
   **does not exist**.

**Consequences.** The park home is the **strongest option in the V2 family**: £128k Wokingham + the
£80k art sale supports **£1,235/mo** of free spending (vs sell-and-buy-cheaper's £865/mo), and the
£128k version works **without** the art sale (£931/mo). £150k Tring cannot complete without it — **no
mortgage is available on a park home** (you own the structure, not the pitch), so the £46,412 gap has
to be cash and exceeds their savings. The trade is the estate: -8%/yr plus up to 10% resale commission.

**Not modelled (flagged):** the 10% resale commission (bites only on an actual resale); above-CPI pitch
drift via "agreed park improvements"; site-closure and pitch-agreement risk (qualitative).

## 2026-07-30 — "Available capital" + "monthly allowance", and a SOLVED affordable-spend figure
**Context:** Rob: *"we really need to highlight 'Available capital' and 'budgeted monthly allowance' for
each year, for each scenario, to compare how much they should plan to be able to spend."* It arose from
the park-home work, where "what annual holiday budget can they afford?" turned out to be the **output**
of the exercise. Spec: [docs/build/PLAN-spendable-view.md](build/PLAN-spendable-view.md).

**Decisions:**
1. **One definition, in `ResultPresenter::spendableFor(YearResult)`**, read by the ladder, Compare, the
   affordability screen, the CSV and the PDF — so a figure cannot drift between surfaces (the existing
   displayed-figure-provenance rule, now extended to these columns in `DisplayedFigureProvenanceTest`).
2. **"Available capital" is `liquidWealth` ONLY** — cash + GIA + ISA. Home equity is excluded (it cannot
   be spent while lived in) and **pension money is carried separately, labelled taxable**. *Rationale:*
   the ladder's existing `usableWealth` adds liquid + pension at face value, so it counts £100k of
   pension as £100k in the hand. Deliberately NOT reused here.
3. **"Monthly allowance" is what the plan can FUND** (`spendTarget − unmetSpend`), not what it targets.
   *Rationale:* `spendTarget` is an input echoed back; in a short year it promises money the household
   does not have.
4. **Divide once, derive the remainder.** Monthly figures are `intdiv(annual, 12)`; "free to choose" is
   `allowance − essential`, not a third independent division. *Rationale:* three separate `intdiv`s let
   the parts disagree with their own total by a penny — caught in development on a year with a 1p
   shortfall. Same total-must-equal-its-parts rule as everywhere else.
5. **A solved "most you could spend" figure — `App\DecisionSupport\SustainableSpend`.** Everything above
   is **bounded by the entered budget**, so it can only ever say whether the plan worked, never what
   they could afford. New `DiscretionarySpendLever` (mirroring `EssentialSpendLever`) plus a
   **deterministic bisection** finds the highest discretionary spend at which the plan still holds.
   Synchronous (~20 forecasts, milliseconds) — **no queue worker needed**, unlike the Monte Carlo
   threshold explorer.
6. **The bar is "full budget funded EVERY year, and money never runs out".** *Rationale:* an
   essentials-only bar was tried and is **degenerate** — where income alone covers the essential floor,
   essentials are met however large a discretionary budget is set (the excess just goes unfunded), so
   the search is insensitive to the lever and runs to its ceiling. This resolves the open question the
   plan posed. Documented in code so it is not reintroduced.
7. **Variant-aware.** The scenario's housing choice is applied first, then the lever — so a sell-and-rent
   plan is searched as a renter. *Rationale:* `deterministicForecastAt` models the stay-put path, a live
   trap in this codebase (it bit me earlier in the same session).
8. **Null, not £0, when a plan cannot cover essentials at all.** "This plan is broken" and "no room for
   treats" must not render identically.

**Consequences (V2 family, deterministic path).** The affordable free-spending budget is the sharpest
discriminator built so far: **sell & buy cheaper £865/mo**, **lifetime mortgage £652/mo**, **YCC to 72
£212/mo**, **YCC to 71 £135/mo**, and **everything else fails even at zero discretionary spend** —
including the LiveMore stay-put base and both £80k art-sale variants. So on these figures the LiveMore
mortgage leaves **no holiday budget at all**, which is the direct answer to the question the park-home
exercise was raised to settle.

**Flagged, not fixed:** `usableWealth = liquid + pension` still overstates available capital and drives
the safety-buffer warning, so that warning fires later than it should (now in DATA-MODEL "Known
divergences"). The solved figure inherits the deterministic path's optimism, so every surface shows it
as "on the expected path" beside the Monte Carlo "how sure", never instead of it.

## 2026-07-29 — Repayment (capital & interest) mortgages amortise; the V2 Stay-put base moves onto a real quote
**Context:** Rob produced a real indicative quote for the V2 couple — a LiveMore Capital ESIS dated 29 July 2026
(via broker "When The Bank Says No"): **£160,000 over 16 years, capital & interest**, 6.23% fixed for 60 months
(£1,318.54/mo) then 7.24% SVR for 132 (£1,384.65/mo), first payment September 2026, cleared August 2042. The
Stay-put base modelled a **hypothetical** instead: ~£90k found from outside pays the £208k buy-to-let down to
£118k, refinanced as a retirement interest-only loan at 6% = £7,080/yr, balance static for ever. The engine
could not represent the quote at all: a repayment mortgage's balance was **static** (see DATA-MODEL "Known
divergences"), so the only shapes available were interest-only and equity-release roll-up.

**Decisions:**
1. **Model the repayment mortgage properly rather than approximate it.** New `RepaymentMortgageTerms` +
   `MortgageRatePeriod` DTOs and an `AmortisationSchedule` calculator: month-by-month, integer pence, monthly
   rate = **nominal annual / 12** (the UK lender convention, not an effective-rate conversion), the instalment
   recomputed at each rate tier as the annuity clearing the then-balance over the then-remaining term (which is
   what produces the step a lender illustrates), and the final instalment trued up so the loan lands exactly on
   zero. *Rationale:* accuracy-first. Approximating with the existing roll-up + overpayment mechanism (annual
   compounding) drifts, cannot express two rate tiers, and never reaches zero.
2. **The schedule owns BOTH the balance and the payment.** The old "Mortgage" expense line is **dropped** when
   terms are set, so the two can never double-count, and the instalment is added **after** the CPI and survivor
   multiplies. *Rationale:* three properties an expense line gets wrong, each in the reassuring direction or
   worse — a mortgage payment is **fixed nominal** (an expense line is a real figure the projector re-inflates
   annually), it does **not** shrink by the survivor factor when a partner dies (the lender wants the same
   instalment from a smaller income — precisely where a later-life mortgage becomes unaffordable), and it
   **stops** at the end of the term.
3. **The loan amount keeps one home.** The terms carry no principal; the schedule amortises
   `Property::$outstandingMortgage`. *Rationale:* the data-integrity rule — a balance must never be able to
   drift from the loan the rest of the forecast sees.
4. **Mutually exclusive with `mortgageRollUpRate`** — the `Property` constructor throws. *Rationale:* a loan
   cannot both amortise and roll up; failing loudly beats a silent precedence rule (and it caught four
   real scenarios that would otherwise have been silently wrong — see 6).
5. **Pinned to the lender's own illustration, as a worked example.** The ESIS repayment table is asserted
   against directly: both monthly instalments exact, every quoted balance **within 21p over 16 years**, total
   interest within 11p, zero at term. *Rationale:* the same standard as the HMRC worked examples — an
   independently produced schedule, not a self-consistent fixture. The residual is the lender's own per-month
   rounding (its interest and balance columns disagree by a penny on row 1).
6. **The V2 Stay-put base moves onto the real quote (Rob's call), and all children inherit** — except seven
   that model a *different mortgage product* and therefore cannot: the two let-to-let children (17, 32 — a
   £208k interest-only BTL at £16,170.96/yr) and the five equity-release children (27, 28, 31, 38, 39 — lifetime
   mortgages). Those get an explicit `property.mortgageRepaymentTermMonths = ''` override. *Rationale:* not a
   preference — four of them **threw** under decision 4, and the other three would silently have had a
   residential C&I product imposed on a loan that is not one.

**Consequences.** The base's `mortgageRedemptionYear` is cleared (this remortgage *is* the answer to the
December-2026 redemption call, so no unmodelled maturity event remains). **The quote is unaffordable on the
modelled figures:** the instalment (£15,822/yr, then £16,616/yr) is carried while both partners live, but from
the first death (~2035) the survivor is on ~£11.7k/yr against £16,616/yr, so the plan **runs short in 2036**
versus 2043 on the old RIO base, and stays ~£15k/yr short every year until the loan clears. What it buys is the
other side: the debt is gone by 2042 and terminal net wealth is **£440,007 vs £365,177** (+£74,830). This is
the survivor-affordability constraint the 2026-07-03 RIO research already put at £45–85k of borrowing — £160k
is roughly twice it. It also needs **~£49,495 found up front** (£48,000 to close the gap to the £208k
redemption, plus £95 + £1,400 of broker fees) against the ~£42k Rob has said is realistically available.

**Not modelled (flagged):** lender fees, the £100 redemption fee, early-repayment charges (5% then 4%), and the
10%/yr penalty-free overpayment allowance — `mortgage_overpayment` applies only to a roll-up, so an amortising
loan has no overpayment input.

## 2026-07-19 — The three hero time-series charts (C1 income, C2 wealth, C3 costs)
**Context:** An adversarial review (2026-07-18, docs/PLAN-output-inflation-and-charts.md Part C) found that
nearly every chart a user would want already has its data computed per year on `YearResult` and thrown at a
table instead of a picture — only one time-series chart (the Monte-Carlo wealth fan) existed. Third build-order
item of that plan (the presentation slice, after A1/A2 correctness).

**Decisions:**
1. **Three stacked-area charts on the deterministic projection**, added as a "Money over time" section before
   the year-by-year cashflow ladder: **C1 income staircase** (every income source stacked over time — the
   salary → DB → State-Pension → drawdown handover), **C2 wealth composition** (pensions / savings &
   investments / home equity, summing to net worth), **C3 costs** (essential vs discretionary, showing the
   age-varying spending smile). Presenter + Blade only, **no engine change** — all figures already on
   `YearResult`.
2. **Built from the SAME `ForecastResult->years` the ladder reads** (`ResultPresenter::timeSeriesCharts()`), so
   a chart can never drift from the ladder table (one definition). Each chart ships its `<details>` table twin
   (the accessible source of truth; the canvas is a progressive enhancement) and reconciles to the ladder
   cell-for-cell — guarded by `TimeSeriesChartsTest` (income cols sum to the total; wealth legs sum to net
   worth; essential + discretionary = spend; all cross-checked against the ladder).
3. **Real (today's-money) terms only**, like the ladder and fan; every stacked band is therefore ≥ £0, so the
   axis anchors at zero with no shortfall band. **The nominal-pounds toggle (also in the plan's slice #3) is
   deferred** to its own slice: showing nominal figures needs the engine's internal pre-deflation values
   exposed, and re-deriving them by re-inflating in the presenter would duplicate the projector's deflation
   logic and risk drift (violating the one-definition rule) — so it is an engine decision, not a presenter one.
4. **Categorical colour from the validated dataviz reference palette** (eight-hue set, documented stacking
   order, CVD-checked on the app's white surface via `scripts/validate_palette.js`); the three sub-3:1 light
   slots meet the relief rule via the `<details>` table twin. The C1 stack **caps at the eight palette hues**:
   if more than eight income sources occur, the smallest-contributing fold into a neutral "Other" band on the
   CHART only — the table + CSV still list every source, so completeness holds (no silent drop). The same
   life-event milestone verticals the ladder marks are overlaid on all three charts.

**Guard:** `TimeSeriesChartsTest` (reconciliation to the ladder + net-worth/spend totals; non-negative stacked
pounds; the >8-source fold keeps every source in the table). `ScenarioResultsTest` renders the new section.

## 2026-07-19 — Voluntary overpayments on a rolled-up lifetime mortgage
**Context:** The equity-release roll-up mechanic (`Property::mortgageRollUpRate`, 2026-07-06) compounded the
balance untouched — it could model "no payments" but not the common product feature of penny-free voluntary
overpayments (typically up to ~10% of the loan a year) that slow the roll-up. A real equity-release proposal
being evaluated hinged on exactly this ("you could overpay £1,000/mo"), and modelling it faithfully — the
balance held near-flat vs ballooning — needed engine support, not just commentary.

**Decisions:**
1. **`Property::mortgageOverpaymentAnnual` (`?Money`, null = pure roll-up).** A FIXED-nominal amount subtracted
   from the balance each year in `PathProjector::growState` **after** the roll-up compounds and after the NNEG
   cap, floored at zero. Applies only when `mortgageRollUpRate` is set (a serviced/RIO mortgage has no rolling
   balance to overpay). Null/absent reproduces the pre-change roll-up byte-for-byte.
2. **The cash to fund it is modelled separately, on the "Mortgage" expense line.** The engine reduces the
   balance; the household must still find the money, entered as the mortgage expense outflow. The two together
   are the honest trade-off — the estate is better preserved, but the cashflow that pays for it can push the
   money to run out sooner — rather than a free balance reduction. (Minor known wrinkle: the expense line
   inflates with CPI while the balance reduction is fixed-nominal, a small conservatism on the cash cost.)
3. **Builder-wired as an optional field** (blank default, validation, loadState backfill, `BuilderStateFixture`)
   per the new-field discipline, so a blank value never perturbs a delta-child what-if.

**Guard:** `LifetimeMortgageRollUpTest` — a £5,000/yr overpayment reduces the balance by exactly that after each
year's compounding (penny-exact), and holds the balance strictly below the pure roll-up every year.

## 2026-07-18 — Care in the deterministic path as an "if care is needed" stress (A2)
**Context:** Care was a Monte-Carlo-only risk, absent from the deterministic central projection. But the
plain-English "What you can afford" verdict (built for the elder couple who can't read the fans) and the
central cashflow ladder run the *deterministic* path — so "do the essentials last for life? **Yes**" was
computed on a path that omits the household's biggest late-life expense. That is not just inaccurate, it is
*falsely reassuring* for the least-numerate reader — the worst failure mode for this tool. Second build-order
item of docs/PLAN-output-inflation-and-charts.md (Part A), following A1.

**Decisions:**
1. **A care-stress scenario shown BESIDE a labelled care-free base — not expected-value averaging.** This is
   what the FCA frame and the professional cashflow tools (Voyant, CashCalc, Timeline) do: care is a
   user-toggled late-life stress shown alongside the base, never a probability-weighted amount smeared into
   the central line. Averaging a severely right-skewed tail is both unrealistic (almost nobody experiences the
   average) and falsely reassuring (it understates the very person in the tail the projection exists to
   protect). So the central line stays care-free but **labelled**, and an adverse care-stress runs beside it.
2. **The stress is ONE ~4-year nursing spell at £1,800/wk on the last-surviving partner**, ending at their
   representative death age, means-tested and CPI+2%-escalated through the existing projector care leg (A1).
   `CareStressScenario::adverseDefault()` holds the params; `DeterministicPathDraws` gains optional injected
   care episodes (empty = the byte-identical care-free path); `DeterministicForecaster::forecastWithCareStress`
   builds the end-of-life spell. **Last survivor** = the adverse means-test position (alone, so the home is
   assessable, no partner income to share the cost) and the more communicable case. **One spell, not both
   partners':** a single significant spell still discriminates a strong plan from a weak one, where a
   both-partners worst case would sink every plan and inform nothing. Fee/duration are the adverse-but-
   defensible end (LaingBuisson top-decile nursing; PSSRU upper-tail duration) per
   [[adverse-default-user-editable]]; flagged user-editable (a params UI is a later refinement).
3. **Surfaced on the Affordability screen (feeds B1).** Each plan card carries the care-stress verdict
   ("even if one of you needed several years of nursing care…" / "…the money would run short in {year}") beside
   the expected-path verdict; the bottom line qualifies "for life" with a care caveat. The **tier and ordering
   stay the care-free expected path** (a strong plan still ranks strong) — the stress is shown, never folded
   into the rank. `ScenarioForecaster::deterministicCareStressVariants` mirrors `deterministicVariants` on the
   same variant inputs, so care-free and care-stress differ only by the injected spell.
4. **Still open:** care-stress params UI (edit fee/duration/onset); a probability-weighted "typical outcome"
   option (captioned as not a safety margin); putting the care-stress line on the main results ladder (this
   slice scopes it to Affordability). Age-conditioning of onset and a sex split of duration remain the older
   flagged refinements.

**Guard:** `DeterministicCareStressTest` — the injected spell reaches the central result (positive care cost,
lower terminal wealth) and can tip a marginal household (State-Pension-covered essentials, modest pot) into an
essentials shortfall; the care-free path carries no care cost (never averaged in). `AffordabilityTest` — every
plan card carries a care-stress verdict and the bottom line carries the care caveat (completeness).

## 2026-07-18 — Care fees escalate above CPI (A1: per-category care cost inflation)
**Context:** The engine draws one CPI series and models every other cost as a *real spread* over it; only
property service charges (`ExpenseProfile::propertyCostsRealGrowth`) and rent had their own real rate. **Care
fees rode flat CPI** — the sampled self-funder fee was applied in real terms with no above-CPI escalation.
Care is the single largest fat-tail cost in the model *and* the fastest-inflating major category in UK
retirement (self-funder fees ran ~10%/yr to Dec-2025, ~20% over two years — several points above CPI), so a
tool whose whole purpose is to surface care and longevity risk was *understating the cost of exactly that
risk*. First build-order item of docs/PLAN-output-inflation-and-charts.md (Part A correctness), and the
highest-value item by the accuracy-first rule.

**Decisions:**
1. **`AssumptionSet::careCostRealGrowth` (`?Percent`, null = flat-real).** The projector compounds the sampled
   care fee at CPI + this real rate to the year the (late-life) spell falls, mirroring the proven, null-safe
   `propertyCostsRealGrowth` mechanism exactly (`PathProjector` care leg — a `(1+g)^yearIndex` real escalation
   before the means test). Threaded through the `PathDraws` interface (`careCostRealGrowth(): float`) so all
   three drivers expose it uniformly; care is Monte-Carlo-only, so it bites only on sampled paths for now.
2. **Shipped default CPI + 2% real across all presets; user-editable.** Care fees are ~60–75% National-Living-
   Wage-pinned staff cost, which government ratchets deliberately above prices; PSSRU/LSE + OBR long-term
   social-care projections escalate care unit costs on earnings/productivity (~2% real above CPI). The recent
   ~10%/yr is an NLW + employer-NI spike, not a standing assumption; defensible standing range 1.5–3% real.
   Per the adverse-default rule ([[adverse-default-user-editable]]) the shipped value is the most adverse of the
   plausible standing values (**CPI + 2%**), exposed as the seventh editable economic assumption
   (`assumptionOverrides.careCostGrowth`) with the sourced alternatives. A time-limited "care shock" (CPI+4–5%)
   remains an unbuilt option. Sourced in `AssumptionSetLibrary` + docs/ASSUMPTIONS.md (verified_on 2026-07-18).
3. **Null-safe / byte-identical.** A null rate keeps care flat-real (the pre-change behaviour); the mapper
   round-trips the field and hydrates a pre-A1 snapshot to null, so every stored care run reproduces exactly.
   New runs from the presets carry 2%. No DB migration.
4. **A2 (care in the deterministic path) remains open.** Care is still Monte-Carlo-only, so the affordability
   verdict still reads a care-free path — the next build-order item. This decision only fixes *how fast* care
   fees rise, not *where* they appear.

**Guard:** `CareCostInflationTest` — the escalation compounds by the expected `(1.02)^yearIndex` factor; a null
(and an explicit zero) rate is byte-identical to the pre-feature engine across the whole wealth path.
`MappingRoundTripTest` — the rate reaches storage (completeness) and a pre-A1 snapshot hydrates to null (back-compat).

## 2026-07-18 — Sex-differentiated late-life care probability in the Monte Carlo
**Context:** The stochastic late-life care risk (`CareCostSampler`, opt-in via `ForecastSettings::modelCareCost`)
drew one flat lifetime care probability (0.25) for everyone, even though `Person::sex` was already collected and
threaded as far as the `Simulator` before being dropped at the sampler boundary. Women's lifetime chance of needing
residential/nursing care is materially higher than men's — they live longer and more often outlive a co-resident
carer — so a flat rate understated a woman's (and a two-woman household's) care tail and overstated a man's. Care
feeds a headline output ("does the money last for life"), and accuracy is the overriding priority, so this was the
highest-value item on What's next #3 (chosen over CGT deemed-occupation absences — near-moot for a continuously
occupied main home — and the annuitisation retirement-month override).

**Decisions:**
1. **Care probability is now sex-differentiated.** `CareAssumptions` replaces the single `probabilityOfCare` float
   with `probabilityOfCareMale` / `probabilityOfCareFemale` and a `probabilityOfCare(Sex): float` accessor;
   `CareCostSampler::sampleHousehold`'s people shape gains `sex` and the per-person Bernoulli draws against that
   person's rate; `Simulator` threads `sex` through the map it already builds. No new persisted field, no data-shape
   change (`sex` pre-existed on the Person DTO — this closes a collected-but-under-consumed use of it).
2. **Default rates: male 0.20, female 0.30 (~1.5:1), calibrated to preserve the ~1 in 4 population mean** at an even
   sex split. The mean stays anchored to the Dilnot Commission / PSSRU "~a quarter of people aged 65 need residential
   or nursing care"; the ~1.5:1 female:male ratio is a conservative reading of the consistent evidence that women's
   lifetime care-home use runs well above men's (NHS Digital HSE 2021 "needs help with ≥1 daily task" 28% vs 24%; US
   lifetime nursing-home use ~38% vs ~21% NEJM 1991, paid LTSS ~55% vs ~38% HHS ASPE). Deliberately keeps a mixed-sex
   couple's aggregate care risk essentially unchanged (no unexplained drift) while a single-sex household now differs
   correctly. Sourced in `CareAssumptions` (verified_on 2026-07-18).
3. **Reproducibility preserved.** The change is a threshold swap, not an extra draw: exactly one Bernoulli per person
   is still drawn, so the RNG stream is structurally unchanged and a fixed seed still reproduces byte-identically.
   Stored snapshots are immutable, so only *fresh* care-modelled runs shift (the intended accuracy gain); care is
   opt-in, so no default/non-care run changes at all.
4. **Age-conditioning of the onset rate and a sex split of the care *duration* remain flagged refinements.** Timing is
   already end-of-life anchored (the spell sits in the final duration-years before the sampled death age), so the
   dominant lever was the incidence probability; duration stays sex-blind (women's stays run somewhat longer — a
   smaller, flagged effect). Supersedes the "a sex/age-differentiated rate is a flagged refinement" note in the prior
   `CareAssumptions` sources block.

**Guard:** `CareCostSamplerTest` — `test_the_default_probability_is_higher_for_women_than_men` (asymmetry + the mean
stays 0.25) and `test_the_sex_split_reaches_care_incidence` (over 2,000 same-seed draws a female cohort incurs care
more often than an identical male cohort, each tracking its assumed 0.20 / 0.30 within noise — proving the split
reaches the sampled outcome, not silently dropped).

## 2026-07-18 — "Hide non-viable plans" toggle on the Compare screen
**Context:** The Compare screen shows the base plan beside every what-if in one table, one burndown chart and one
set of Monte-Carlo cards. When several what-ifs run out of money (their usable-wealth line falls below £0), the
reader has to eyeball which plans actually last against the ones that don't, and the burndown chart is crowded with
lines diving through the axis. A simple filter to focus on the plans that survive was wanted.

**Decisions:**
1. **"Non-viable" is defined as deterministic depletion:** a plan whose usable-wealth line falls below £0 at some
   point in the deterministic projection (`YearResult`/forecast `depletionCalendarYear !== null`) — the same
   depletion the "Money lasts: No" column reports and the burndown draws crossing the axis. Not a Monte-Carlo
   probability threshold: the filter is a factual "this plan runs out on the expected path", consistent across the
   table, chart and cards from one definition.
2. **Pure presentation, no shape change.** A `ScenarioCompare::$hideNonViable` bool filters the assembled plan set
   (`CombinationComparisonData::assemble`) in `render()` only; the engine, DTOs and stored runs are untouched. The
   base plan's forecast still drives the shared milestone annotations even when the base row is itself filtered out
   (captured before the hide filter). "Re-run all" still names and queues **every** plan (`planCount`), not just the
   visible ones — hiding is a view convenience, never a change to what gets run.
3. **The toggle only appears when there is at least one non-viable plan to hide** (`anyNonViable`), and an empty-state
   line covers the all-hidden case. The burndown wrapper is `wire:key`ed on the filter state so toggling replaces the
   `wire:ignore`d chart subtree and re-inits ApexCharts with the filtered series (without the key the ignored canvas
   would keep plotting the dropped plans).

**Guard:** `ScenarioCompareTest` — `test_hide_non_viable_drops_plans_that_run_out_of_money` (a spend-beyond-income
what-if is dropped, exactly the viable names remain) and `test_the_toggle_is_absent_when_every_plan_is_viable`.

## 2026-07-18 — Stochastic salary growth in the Monte Carlo
**Context:** With house growth made stochastic earlier today (the entry below), salary growth was the last
deterministic straight line in the Monte Carlo — the other half of the flagged v1 limit "house/salary growth
deterministic inside the Monte Carlo" (DATA-MODEL Known divergences; What's next #3). A still-working household's
future pay rises, and the savings/pension contributions the surplus funds, are genuinely uncertain, so holding them
certain understated the spread of what a working couple can accumulate by retirement. Rob picked this up as the
highest-value remaining accuracy refinement (accuracy is his overriding priority). Supersedes decision 1 of the
house-growth entry below ("salary growth stays deterministic — noted, not done").

**Decisions:**
1. **Salary growth is now stochastic in the Monte Carlo**, on exactly the same footing as house growth. `AssumptionSet`
   gains `salaryGrowthVolatility` (`?Percent`, REAL annual σ) and `salaryEquityCorrelation` (float); `ReturnModel`
   draws a per-year salary shock correlated to the equity shock; `SampledPathDraws` reads the sampled per-year path.
   A per-person `Person::salaryGrowth` override still sets a trend, not a risk, so it bypasses the shock (mirroring the
   per-pot / per-property growth overrides) — no change to that override's behaviour.
2. **Deliberately LOW salary–equity correlation (0.10), weaker than housing's 0.20.** Aggregate real wage growth is
   near-acyclical once workforce composition nets out, and the contemporaneous GDP-growth/equity-return link is close
   to zero, so tying salary tightly to markets would overstate the co-movement. Same scalar-correlation-to-equity
   construction as house (independent component `√(1−ρ²)`, ρ clamped), not a matrix row.
3. **Opt-in via a nullable volatility → reproducibility preserved**, identical contract to house. `salaryGrowthVolatility`
   defaults to `null` = deterministic (the prior behaviour); a null-vol set consumes **no** salary draw, so every
   existing set, test and stored snapshot is byte-identical. The salary shock is drawn **last** in each year so a
   null-salary set never touches the house/asset/inflation stream; once salary vol IS on, the extra draw advances the
   shared stream for later years (fresh runs only — a stored snapshot has no salary vol, so is unaffected). No DB
   migration: `AssumptionSetMapper` round-trips the pair and a pre-today snapshot hydrates to null.
4. **Sourced, tunable figures** (docs/ASSUMPTIONS.md): REAL salary-growth volatility **2.0%** (Set B's long-run
   **2.5%**), salary–equity correlation **0.10**. Grounded in aggregate real wage growth being ~0.51× the volatility of
   GDP growth and far smoother than profits (Champagne-Kurmann-Stewart, FRB San Francisco WP 2011-23), sanity-checked
   against the UK ONS real regular-pay series (~2% annual real-earnings volatility). Narrower effect than housing (it
   only bites during a working person's pre-retirement years), but it stops a working couple's accumulation looking
   artificially certain. The deterministic central projection is unchanged (uses the mean).
5. **Minimal app surface:** two new set fields; the assumptions panel gains a salary-volatility "show-your-working" row
   (only when stochastic), placed after the salary-growth row, exactly as the house-volatility row was added.

**Completeness (the guard):** `StochasticSalaryGrowthTest` proves the salary risk reaches the aggregate — a salary-driven
still-working couple's terminal total-wealth spread widens under stochastic salary growth vs the deterministic mean, it
collapses to the flat mean at null vol, the sampled path is reproducible under a seed, and a null-salary set draws
nothing extra (whole path byte-identical). Not a silent-drop.

## 2026-07-18 — Stochastic house-price growth in the Monte Carlo
**Context:** The Monte Carlo held house-price growth deterministic (a straight line at the mean), so a home — the
largest, most variable slice of this couple's wealth — carried no uncertainty in the fan. That understated the risk
of the stay-put and buy options relative to sell-and-rent (whose invested proceeds were already stochastic): a
home-heavy plan looked artificially certain. This was the flagged v1 limit "house/salary growth deterministic inside
the Monte Carlo" (DATA-MODEL Known divergences; What's next #3). The tool's whole purpose is a housing decision under
uncertainty, so this is the highest-value accuracy refinement (accuracy is Rob's overriding priority).

**Decisions:**
1. **House growth is now stochastic in the Monte Carlo**, drawn per year from the same seeded RNG. `AssumptionSet`
   gains `houseGrowthVolatility` (`?Percent`, REAL annual σ) and `houseEquityCorrelation` (float). `ReturnModel`
   draws a house shock correlated to the equity shock; `SampledPathDraws` reads the sampled per-year house path.
   Salary growth stays deterministic (a narrower, lower-value remaining refinement — noted, not done).
2. **Single house–equity correlation, not a full extra matrix row.** House is not an asset class in the allocation,
   and adding a 4th matrix row breaks the `assetClasses`-ordered contract. A scalar correlation to the equity shock
   (index 0) captures the one load-bearing linkage — the *low* (~0.2) co-movement that makes selling-and-investing a
   genuine diversification of concentrated housing risk — with one extra draw per year. The independent component is
   `√(1−ρ²)`, ρ clamped to [−1, 1].
3. **Opt-in via a nullable volatility → reproducibility preserved.** `houseGrowthVolatility` defaults to `null` =
   deterministic house growth (the v1 behaviour), and a null-vol set consumes **no** house draw, so every existing
   set, test and stored snapshot is byte-identical to before. Only the shipped presets set a real vol, so only they
   become stochastic. No DB migration: `AssumptionSetMapper` round-trips the new fields and a pre-2026-07-18 snapshot
   hydrates to null (deterministic), keeping old queued runs reproducible.
4. **Sourced, tunable figures** (same standard as every other assumption; docs/ASSUMPTIONS.md): REAL house-price
   volatility **9%** (Set B's long-run **11%**), house–equity correlation **0.20**, from the long-run record
   (Jordà-Knoll-Kuvshinov-Schularick-Taylor, NBER w24112: housing far less volatile than equities, low equity–housing
   covariance). The deterministic central projection is unchanged (uses the mean); only the fan widens.
5. **No canonical-shape churn to the app:** the two new set fields are the only additions; the assumptions panel
   surfaces the house volatility (show-your-working) so the fan's width traces to a stated figure.

**Completeness (the guard):** `StochasticHouseGrowthTest` proves the house risk reaches the aggregate — a
home-owning couple's terminal total-wealth spread widens under stochastic house growth vs the deterministic mean
(a home-dominated £500k example: p10–p90 spread £169k → £759k, median ~unchanged), that it collapses to the flat
mean at zero/null vol, and that the sampled path is reproducible under a seed. Not a silent-drop.

## 2026-07-17 — "What you can afford": the plain-English affordability screen
**Context:** The engine is trustworthy but the app is hard to communicate to the elder couple it models — no
patience for ladders, fan charts and Monte Carlo. They want one answer: "what can I / should I do?". Rob asked for
a screen showing only the plans that actually work, with the limits tested (can they afford £2,000/mo rent if they
sell for £290k?).

**Decisions:**
1. **New `/scenarios/{base}/afford` surface** (`App\Livewire\Affordability` + the pure `App\Forecast\AffordabilityAssessment`
   presenter), base-centric like Compare. It reduces the base + every what-if child to one plain yes/no — do the
   *essentials* (the must-pay floor) stay paid to the end? — leads with the plans that pass, and collapses (never
   hides) the ones that fail, each showing the year it runs short and why. A factual "bottom line" names the
   strongest working plan; a directive "lean towards" sentence is added only behind the walled-off `interpret`
   ability (on in personal-use mode), exactly as `Interpretation`/Compare, so the guidance-only partition holds
   when off.
2. **Verdict = the deterministic central projection** (fast, synchronous, covers every plan incl. brand-new
   what-ifs with no stored run). Because that path is optimistic for this survivor-cliff household, each plan's
   stored full Monte Carlo success ("how sure") is shown ALONGSIDE the verdict when a run exists — never instead of
   it — and a one-click **Check how sure** queues the full runs (reusing `SimulationRunner`, then hands off to
   Compare's existing progress UI). Tiers: *comfortable* (full budget lasts) > *essentials covered* (floor lasts,
   extras don't) > *fails*. "Works" = essentials met every year.
3. **No canonical-shape change:** pure presentation over the engine's own `ForecastResult`/`SimulationResult`; no
   new persisted entity, no new DTO field.

**Finding (the £290k / £2,000-mo question):** selling and renting at £2,000/mo fails at any realistic sale price —
runs out 2037 on a £290k sale (2040 even on £350k); the affordable rent ceiling on a £290k sale is ~£1,000/mo (and
even that is tight — essentials only). Selling to *buy* cheaper (£165k) survives even the pessimistic £290k price
with ~£82k left, and is the strongest plan overall (£135k left / 87% MC on the base £350k sale). Renting forfeits
~£41k lifetime Pension Credit plus CGT. Five limit-test what-ifs added to the V2 family in the app (DB scenarios
33–37; app data, not repo data — see docs/SCENARIO-V2.local.md).

## 2026-07-16 — No magic money: the purchase-funding waterfall + documented capital receipts
**Context:** Rob spotted that scenarios which buy a home "magic up" the money. Confirmed: a cash-only buy above
the net proceeds floored the surplus at £0 and still handed the household the home at full price, owned outright —
the shortfall appeared from nowhere as home equity, inflating that plan's wealth (2026-07-03 below described the
mechanism but only added the RIO route; the cash-only case kept a UI flag over a silently-modelled buy). Savings
were never drawn to fund a gap even when the household had them, the RIO borrowed the *entire* gap, and there was
no way to model a documented one-off receipt (a family gift / outside-asset sale) — the real V2 base's ~£90k
paydown is literally "assumed from outside the modelled assets". **Rob's rule: money never appears in any scenario
without a documented source** (sale residual, savings, work income, family contribution, a mortgage).

**Decisions (Rob's, via structured options):**
1. **Funding waterfall, savings first:** a purchase gap is funded net proceeds → liquid savings, drawn
   automatically cash+Premium Bonds → GIA → ISA (never pensions — a forced pension draw would trigger tax/MPAA;
   model that explicitly if wanted), persons in declaration order → the RIO mortgage takes the **post-savings
   remainder** (only when `buyMortgageRate` is set) → any residue is the **unfunded gap**.
2. **An unfunded buy still projects but visibly fails:** the gap is charged as a year-0 one-off cost
   (`ExpenseProfile::withOneOffCost`, "Unfunded purchase shortfall", the RepayFromCapital precedent — it charges
   the spend target, not the essential floor), so year 0 shows `unmetSpend` ≥ the gap and every surface flags it.
   Nuance (accepted as *accurate*): the in-projection shortfall machinery may then fund some of it from pensions,
   grossed-up and taxed — the "never pensions" rule applies to the automatic pre-projection waterfall only.
3. **`CapitalReceipt` input** (owner, label/source, amount in today's money, calendar year): the documented way
   money from outside the plan enters — credited to cash in its year, a new 11th canonical income source
   `capital_receipt` on the ladder, tax-free, disregarded as means-test income (the banked cash raises tariff
   income from the following year), **excluded from `SECURE_SOURCES`** (one-off ≠ income floor). A dead owner's
   receipt still reaches the household (inflows pool; the surplus convention); after the last death it is never
   realised. Rob re-models the V2 £90k with it in the UI (label = the real source).

**How (engine first, one home per figure):** `Housing\SavingsFunding` (pure static draw; a GIA draw realises the
pro-rata gain via the single `disposeGiaSlice` definition and reduces the carried `unrealisedGain`, so the basis
stays exact); `HousingPurchase` gains `fundedFromSavings` + `unfundedGap` and **asserts the extended identity in
its constructor** — `netProceeds + fundedFromSavings + mortgage + unfundedGap == buyPrice + SDLT + moving +
surplus` — so a non-reconciling decomposition cannot exist; `HousingComparison::fundingFor` is the ONE home both
`buyOutcome` (figures) and `buyVariant` (the projected household, accounts actually reduced) read, so the reported
split and the projected money can never disagree. **Year-0 CGT is real:** the realised gain seeds
`Household::$realisedGainsAtStart`; the projector charges it up-front in year 0 and shares ONE annual exempt
amount with any in-year disposal (incremental closing charge) — a year-0 disposal is taxed exactly once, never
free. New `Household::$capitalReceipts` + both fields threaded through every positional rebuild (six sweep levers +
`withHousing` — guarded by a lever-rebuild test). **Fixed in passing:** `withHousing` dropped `relationshipStatus`,
silently reverting a cohabiting couple's buy/rent variants to married IHT treatment.

**Consequences:** the RIO mortgage now borrows less when savings exist (2026-07-03's "borrow the whole gap" is
superseded); a buy variant's accounts are genuinely spent (means-test capital falls — correct); the Compare
burndown's net-position line shows an unfunded buy plunging £-millions negative (usable minus cumulative unmet) —
that is the honest picture, not a bug. Sweep monotonicity across all four funding regimes (surplus → savings →
mortgaged → unfunded) is pinned. Presentation: the sale-explainer buy block shows the full funding split; savings-
funded reads as a neutral note, an unfunded gap as a loud red failure on results/Compare/assistant; the builder
gains a step-3 "One-off capital receipts" repeater. PDF surfaces deferred (uncommitted PDF work in the tree).
Tests: reconciliation identities, waterfall order, exact year-0 CGT (via the engine's own `cgtOnGain`), per-source
completeness (a receipt and a savings draw each demonstrably reach the forecast), builder delta round-trip.

## 2026-07-16 — The external-origin pin is host-conditional, not global
The 2026-07-12 `APP_EXTERNAL_URL` pin (below) was unconditional: while set, *every* request — including
local dev at `retireforecast.test` — generated `*.ts.net` asset/link URLs, so the local site rendered as
bare unstyled HTML whenever sharing was enabled (and whenever `artisan serve`/Tailscale were down, those
URLs were dead too). Verified empirically that **Tailscale Serve preserves the original `*.ts.net` Host
header** when proxying to the loopback backend (it also adds `X-Forwarded-For/Proto/Host` and
`Tailscale-User-*`). So the pin now applies **only when the incoming request's Host matches the configured
external host** — family traffic gets pinned https URLs, local dev keeps its own, both work simultaneously
with no env toggling. Still spoof-safe without trusting `X-Forwarded-Host`: a forged Host merely opts in
to the legitimately *configured* origin; no request-supplied value is ever used in generated URLs. In
console (artisan, queue workers, tests) the bound request's host is `localhost`, which never matches, so
the pin stays off there — same net effect as before for URL generation outside HTTP. Guarded by
`ExternalUrlPinTest`.

## 2026-07-12 — Share with family privately via Tailscale Serve, not a Hostinger deploy
**Goal:** let family view the current (real) scenarios as-is — a private share, explicitly **not** a public
launch. **Rejected Hostinger** (the SSH offered was shared hosting — port 65002, `u…` user): shared plans
are **MySQL-only** (no Postgres), cannot keep a persistent `queue:work` daemon, cannot run the Ollama
assistant, and would force a DB migration + re-entering the encrypted data on a third party + crossing the
public-compliance line (`COMPLIANCE_PERSONAL_USE`). All cost, no fit for "as-is + private". (The schema *is*
MySQL-portable — migrations use plain `json()` columns, no pgsql-specific types — so Hostinger stays a
*possible* target for a future public build; it is just the wrong tool for private family viewing.)
**Chosen: Tailscale Serve.** The app stays exactly as-is on this machine; reachable only inside the private
tailnet; the encrypted data never leaves the box. No migration, no rebuild.
**Wiring (all inert until enabled):**
- **Serve Host-agnostically:** `php artisan serve --port=8000` + `tailscale serve --bg 8000` (tailnet-only,
  auto HTTPS on the `*.ts.net` name). Herd/Valet routes by `Host`, so proxying to the Valet vhost would
  miss the app under the `*.ts.net` hostname — a fixed port sidesteps Host routing and serves this app
  regardless of Host.
- **`APP_EXTERNAL_URL`** (new `config('app.external_url')`): when set, `AppServiceProvider` pins absolute
  URLs/redirects to that https origin (`URL::forceRootUrl` + `forceScheme`) so a login does not bounce to an
  unreachable local host. Blank = local dev untouched.
- **Loopback trusted proxy** (`bootstrap/app.php`): trusts `127.0.0.1`/`::1` for `X-Forwarded-For/Port/Proto`
  **only — not Host** (host cannot be spoofed; it is pinned by the env var), so Laravel sees the request as
  HTTPS through Tailscale's TLS-terminating proxy.
- **`APP_DEBUG=false`** while shared, so an error cannot leak the private scenario figures in a stack trace.
**Operational:** reachable only while the machine is on with `artisan serve` + a queue worker + Tailscale;
the `tailscale serve` config persists across reboot but `artisan serve`/`queue:work` do not (relaunch them).
Scenarios are per-user, so family log in with Rob's credentials — there is no read-only share. Stop sharing:
`tailscale serve --https=443 off`, then blank `APP_EXTERNAL_URL`. The concrete `*.ts.net` URL lives in
`.env` (gitignored), not here. **This is private sharing, not the public go-live — the public-release
blockers (set `COMPLIANCE_PERSONAL_USE=false`, tighten CSP, etc.) still stand.**

## 2026-07-10 — Queued Monte Carlo reproducibility independently re-verified on Postgres; a stale pre-migration worker gotcha
Re-verified the 2026-07-09 SQLite→Postgres fix independently, at Rob's request (a standing trust concern
about run-to-run variance with no other changes). **Method:** computed an in-process reference for all 18
scenarios (3 variants × 10,000 paths — the path proven deterministic), then dispatched the whole family
**twice** through the real `queue:work` daemon (batch 1 = a single worker; batch 2 = **two concurrent
workers**, deliberately heavier `jobs`-table contention than the original failure ever saw), comparing each
stored result to the reference on **both** the success probabilities (the original "up to 14 points"
symptom) **and** a full-payload md5 (catches any single-path drift in any figure). **Result: 108 queued
variant-results across 36 runs, every one byte-identical — max probability gap 0.0000 points, 0 hash
mismatches.** The fix holds; in-app "Re-run all" is trustworthy.
**Operational finding (new):** a `queue:work` daemon started 2026-07-07 (before the migration) was still
polling the **old SQLite `jobs` table** and processed none of the Postgres jobs — my batch sat unprocessed
for 10 minutes until a fresh worker drained it. `queue:work` **caches its DB connection at boot**, so every
worker must be restarted after a `DB_CONNECTION` change or it silently polls the old database (no error —
jobs just never run; an in-app "Re-run all" would hang). Captured in [[queue-worker-caches-db-connection]].
**Residue:** the 36 verification runs (ids 437–472, fixed seed 424242) became each family scenario's latest
completed run and overwrote `result_snapshots`; deleting them was automode-blocked as pre-existing app data,
so a subsequent in-app "Re-run all" (at canonical seeds) supersedes them. **No code changed** — verification
scripts stayed in the session scratchpad. Status: RESOLVED remains RESOLVED, now independently confirmed.

## 2026-07-09 — The Monte Carlo is reproducible via CLI but NOT via the `queue:work` worker (open bug)
**Context:** Chasing a family figure that swung more than 10k-path sampling noise allows, I found the
**stored Monte Carlo runs do not reproduce**: recomputing a plan at its own stored seed gave a
different success probability than the stored run — up to **14 points** — for ~9 of 16 plans, with
*which* plans varying per batch and two adjacent jobs once landing the identical wrong value.
**What was ruled out (all proven):** the engine is deterministic — the same seed gives byte-identical
results across 6 independent processes, with and without JIT (so **not JIT**); a fresh `execute()` in
a CLI process reproduces the correct value; **a CLI loop of all 16 `createRun()`+`execute()` calls in
one process is 100% reproducible (every gap 0.0)**. The RNG is a seeded `Mt19937`; there is no static
state, no engine memoization, no unseeded randomness; the job class holds only the run id.
**Conclusion:** the corruption is specific to the **queued-job execution path** — not the engine, not
the code path, not JIT, not SQLite lock errors (those surface as exceptions, not wrong values).
**Further findings (2026-07-09 later):** (a) **per-job process isolation does NOT fix it** —
`queue:work --once` (a fresh process per job) still produced mismatches, so it is not cross-job daemon
state accumulation; (b) **instrumentation proved the queue and a direct call compute on BYTE-IDENTICAL
inputs** — logging `md5(serialize())` of the household, assumptions, action, settings and effective
builder-state showed the **same hashes** from the worker process and a direct CLI call at the same
seed, so the queue is **not** reading different scenario state; (c) the corruption is **intermittent**
— a single instrumented queue run reproduced the correct value, but a 16-job batch reliably has
several wrong; the wrong values are **un-reproducible at their own seed**. So it is intermittent
non-reproducible computation in the queued context on identical inputs. **Leading hypothesis:** the
**`database` queue driver on SQLite** — the CLI loop (no queue at all) is 100% correct while every
path that goes *through the queue* is intermittently wrong, and SQLite has caused repeated trouble
this session (the earlier "database is locked" failure). The queue driver's own DB transaction on the
`jobs` table likely interacts with the job's reads under SQLite. **Root cause still not isolated.**
**Interim resolution:** the V2 family was recomputed via the **CLI loop** (`compute-family-cli`,
verified all-0.0 gaps) and set as the latest completed runs, so the stored/app-displayed figures are
correct and canonical. **The app's UI still dispatches to `queue:work`, so in-app "Re-run all" remains
affected until fixed — avoid it.**
**Recommended fix (to investigate — NOT yet done):** (1) **move the queue off SQLite** — use the
`sync` driver (correct but blocking; fine for a personal tool if a spinner is acceptable), or `redis`,
or move the whole app DB to Postgres/MySQL (SQLite's concurrency limits are the recurring theme); (2)
add an **inputs-hash to `SimulationRun`** like `ThresholdRunner` — it catches *staleness* (inputs
changed) but NOT this bug (identical inputs, wrong result), so it is defence-in-depth, not the fix;
(3) a reproducibility guard test. Per-job isolation is **ruled out** as a fix.
**RESOLVED 2026-07-09 — moved the app database from SQLite to Postgres.** The hypothesis held: on
Postgres the **`queue:work` daemon now reproduces every run exactly** (the family re-run through the
identical queued path gives all-0.0 stored-vs-fresh gaps). SQLite could not handle the `database`
queue driver's concurrent access (its rollback-journal locking intermittently disturbed a run's
computation); Postgres' MVCC handles it correctly. **Setup:** local Postgres 18 (`postgres`/`postgres`,
db `retireforecast`); `.env` `DB_CONNECTION=pgsql` (the old sqlite line kept commented for revert);
schema via `php artisan migrate`; the SQLite data (users, scenarios incl. the encrypted V2 family,
runs/results) was copied across verbatim (encrypted `text` columns transfer under the same APP_KEY;
booleans converted; sequences reset) — a one-off `copy-sqlite-to-pg` script, `database.sqlite`
retained as `.bak`. **Tests still run on in-memory SQLite** (phpunit.xml) — fast + isolated, and the
bug was runtime-concurrency, not unit-testable. In-app "Re-run all" is trustworthy again.
**Status:** RESOLVED (Postgres migration). The inputs-hash on `SimulationRun` remains a nice-to-have
staleness guard (not needed for this fix).

## 2026-07-09 — A let property's mortgage interest gets the buy-to-let finance-cost tax reducer
**Context:** Reviewing why "Let out home & rent elsewhere" (#17) came out 0%, Rob asked whether the
rental income (£1,800/mo) was counted. It was (£17,500/yr as entered), but two things were off: the
entered figure was £17,500/yr not the £21,600/yr Rob intended, and the rent was taxed at the full
marginal rate with **no relief for the mortgage interest** — wrong for a let property since the April
2020 finance-cost restriction (landlords deduct nothing but get a basic-rate tax reducer).
**Decision:** `PathProjector` now applies the **buy-to-let finance-cost reducer** when the primary
residence is **let** (`isLet`): the household income tax falls by **20% × min(mortgage interest,
rental income)**, capped at the tax due (a reducer cannot create a refund). The interest is still
charged as a real cash outflow (the mortgage spend line); only the tax relief was missing. New
`rentalIncomeNominal` sums only `IncomeStreamType::Rental` streams, so generic "other" income is not
mistaken for rent. #17's rental set to £1,800/mo (£21,600/yr). `ENGINE_VERSION` →
`finance-engine/phase-3-btl-finance-cost`. Deterministic effect on #17: depletion 2030 → **2035**
(rent bump + credit together); it still does not fully last — letting keeps the £208k mortgage AND
pays rent elsewhere, which no realistic rent covers.
**Why:** accuracy — taxing rental income with no interest relief overstates a landlord's tax by up to
20% of the interest (~£3,234/yr here). The rule is real and sourced (finance-cost restriction, fully
phased in from April 2020).
**Guards:** `BuyToLetFinanceCostTest` — a let property gets the 20% reducer a residential one does
not; the base is the lower of interest and rental income.
**v1 flags:** household-level (joint-ownership split not separated); rental *profit* approximated by
rental income (no other let-expenses modelled); the reducer's third statutory cap (adjusted total
income above the personal allowance) is not applied, only the tax-due cap.
**Not changed (reconsidered):** the let flat still charges the household its council tax + utilities.
On reflection this is **not** an error — when they let the flat and rent elsewhere they pay occupier
costs at the *rented* home, and the flat's set is a reasonable proxy for that one set (the tenant
pays the flat's actual bills). Charging one set of occupier costs is correct; dropping it would
under-cost.
**Status:** active

## 2026-07-08 — A bought home carries standard maintenance (the buy variant is no longer upkeep-free)
**Context:** Rob flagged that the sell-and-buy plans model **no ownership costs on the replacement
home**. Root cause: the buy variant scales the *current* home's `runningCosts` to the new home
(`scaledRunningCosts`), but the V2 flat's `runningCosts` is empty — its building maintenance sits
inside the £6,685 **service charge** (a `while_owning_home` spend line, stripped on sale). So a
freehold house bought for £165k–£300k inherited zero property-specific upkeep, flattering every buy
plan. Rob's instruction: use the industry standard for now; add exact costs later if a real property
is chosen.
**Decision:** `HousingComparison::newHomeRunningCosts` (was `scaledRunningCosts`) — when the current
home has its own positive `runningCosts` (a house with entered upkeep), scale them pro-rata as
before; **otherwise apply a standard 1%-of-value home-maintenance default** to the bought home. 1%
is the widely-used UK rule of thumb ([Checkatrade 2023: homeowners spent ~1% of property value a
year on maintenance](https://allservices4u.co.uk/understanding-the-1-rule-for-budgeting-property-maintenance/);
newer homes ~1%, older 1.5–4%, so 1% is conservative). It replaces the service charge the sold flat
no longer pays, so a bought freehold isn't modelled upkeep-free. A sourced `HOME_MAINTENANCE_RATE_BPS`
constant in the housing layer (verified_on 2026-07-08); a real property's actual costs override it.
`ENGINE_VERSION` → `finance-engine/phase-3-home-maintenance`.
**Why:** accuracy — a zero-upkeep house is not real, and it biased the buy-vs-stay comparison toward
buying. 1% is defensible and conservative; the lever to enter exact per-property costs already exists
(`Property::runningCosts`), so this is a sensible default, not a ceiling.
**Guards:** `HousingComparisonTest` — a current home with runningCosts still scales them; a
leasehold-flat household (empty runningCosts) buying £250k gets exactly £2,500/yr.
**v1 flags:** the current leasehold flat keeps its service charge as its building-maintenance proxy
(no double-count), but a leaseholder's uncovered *internal* maintenance (boiler, decorating) is not
separately modelled; the 1% is flat-real (no age-driven step). **The sell/buy V2 plans were re-run
under this stamp.**
**Status:** active

## 2026-07-08 — A child's regular cash gift is tax-free unless work is exchanged (plan #23)
**Decision:** Plan #23's £300/mo from the child is modelled **tax-free** (`taxable=false`) and the
plan renamed "…child £300/mo (tax-free)". Rob confirmed no work is exchanged for it.
**Why:** a cash gift is not income for the recipient in UK law (no source), so no income tax, and it
is disregarded in the Pension Credit and care means-test assessments too — the taxable flag had
understated the plan on all three axes. Only genuine earnings for work done, or (separately) the
paying child's own close-company tax, would change that; neither applies. See the session Q&A.
**Status:** active

## 2026-07-08 — Home-ownership costs can outpace inflation (the service-charge lever)
**Context:** Rob questioned whether CPI-linked cost growth under-models his service charge, and
supplied the building's 12-year history (£5,037.10 in 2014 → £6,685.00 in 2026). The analysis cut
both ways: over the full window the charge grew **2.4%/yr against ~2.9%/yr CPI** (it *lost* ~0.5%/yr
real, lagging the 2022–23 surge badly — +0.28% in 2022 against 9.1% CPI — then catching up), but the
last three years ran ~**4.2%/yr ≈ CPI+1.5 real**, and sector-wide pressures (buildings insurance,
building safety) make above-CPI leasehold costs a live risk. Rob's ruling: **assume the worst, not
the best.** The model could not express it: every spend line rode CPI exactly (real-flat), and the
smile bands deliberately don't apply to contingent lines.
**Decision:** `ExpenseProfile::propertyCostsRealGrowth` (Percent?, default none) — an optional REAL
annual growth rate on the **propertyCosts bucket only** (the `while_owning_home` lines: service
charge / ground rent / levies). The projector compounds it per projection year in real pence before
the survivor/CPI multiply, so nominal growth is CPI + the rate and the escalation is treated exactly
like the bucket it grows; it **follows the bucket** (sell variants and a forced sale strip it — no
phantom escalation on a sold home) and the **mortgage payment is not escalated** (contractual).
Builder input on the Spending step (`expense.propertyCostsGrowthPct`, sparse — absent when blank, so
no spurious what-if deltas); a results-page **input note** states the rate and today's bucket so the
later-year squeeze reads as intended. **The V2 base is set to 1.5%** (the recent-trend real rate;
children inherit via their deltas; the sell/rent children are naturally inert — their variant
households own no home with these costs).
**Why:** accuracy with user control — the household's own 12-year history is the best evidence and
mildly favours CPI, but Rob prices the downside; a lever beats a baked-in judgement either way. Zero
growth is byte-identical to the pre-feature engine (guarded).
**Guards:** `PropertyCostsGrowthTest` (penny-exact compounding; essential floor climbs with it;
byte-identical at zero; escalation stops at a forced sale; `withoutPropertyCosts` variants never
escalate), an assembler completeness test (the entered rate demonstrably reaches the profile),
builder sparse round-trip, and an `InputNotesTest` case.
**Status:** active

## 2026-07-08 — Care years are means-tested in the projection (supersedes the gross-fee flag of 2026-07-01)
**Context:** The 2026-07-01 care build charged the **gross self-funder fee** for every care year and
flagged the means test as the refinement (`Care\CareMeansTest` existed but nothing in the projection
called it). That overstates the care burden on exactly the depleted paths — in England, once a
resident's own capital falls to £23,250 the local authority pays the balance above an income-based
contribution — so the "chance the money lasts" on care-modelling runs was biased pessimistic, worst
where it matters most (the fat tail the panel exists to show). First item of the What's-next
"optional refinements", picked by value: all 16 V2 plans model care.
**Decision — assess each care year, per resident, at what the household bears.**
`CareMeansTest::annualCharge()` is the one home for the charge rule:
`min(fee, max(0, capital − upper limit) + tariff income + max(0, income − PEA))` — a comfortable
self-funder pays the full fee; a funded resident contributes income minus the Personal Expenses
Allowance plus tariff income; the crossing year pays capital down to the limit then contributes from
income. `PathProjector` applies it per person in care (England's **individual** assessment): the
resident's **own** accounts (cash/GIA/ISA are per-owner in the engine; pension pots disregarded as
capital, as in the Pension Credit treatment), their **own** taxable income (income from capital is
treated as capital under the charging regs — the tariff covers it; AA/DLA excluded, mirroring the
28-day payment stop), and the home **only** when no partner still occupies it (lone resident) or it
is let — the same let-home rule as the Pension Credit test, split equally for a couple. Capital
limits + tariff stay frozen nominal (15th year running, like the PC thresholds); the **PEA is
uprated with inflation** (as the DHSC circular does each April). `CareParameters` gains the sourced
`personalExpensesAllowanceWeekly` (£30.65/wk 2025-26, £31.80/wk 2026-27 — DHSC LAC charging circular
2026-27, verified 2026-07-08). `careCostReal`/`CareImpact` now report the **household-borne** bill;
`shareOfPathsWithCare` reads "care cost the household anything" (a fully-LA-funded spell — income
below the PEA — no longer counts, honestly relabelled on the panel). `ENGINE_VERSION` →
`finance-engine/phase-3-care-means-test`.
**Why:** accuracy-first — the whole point of the care panel is the tail, and the tail was wrong in
the conservative direction; "deliberately cautious" is not a licence to misstate a statutory scheme
the engine already carried the thresholds for. A spouse's resources are never assessable in England,
so per-person assessment is the correct law, and the engine's per-owner accounts made it free.
**Guards:** `CareMeansTestTest` pins the charge formula penny-exact (all three regimes, the fee cap,
the LA-pays-everything floor, the PEA uprating); `CareMeansTestedChargeTest` pins the projection
(funded resident charged the contribution not the fee; spend steps up by exactly the charge; a
self-funder's behaviour preserved to the penny; a lone owner's home equity makes them a self-funder;
a partner in the home shields it; a resident with nothing of their own is fully funded even in a
wealthy household). Existing property-based care tests (occurrence share, reproducibility,
care-lowers-success) pass unchanged.
**Consequences:** stored care-modelling runs (and their success odds) assume gross fees until re-run;
threshold records re-key via the engine version in the inputs hash. v1 flags: Pension Credit is not
counted into the contribution; the LA is assumed to pay at the self-funder rate; deferred-payment /
12-week-disregard mechanics are below the annual grid; a couple both in care simultaneously still
have the home disregarded.
**Status:** active (supersedes the "gross self-funder cost is charged" flag of 2026-07-01)

## 2026-07-08 — All reported wealth is NET of the mortgage (supersedes part of 2026-07-06)
**Context:** Rob asked Compare "which plan leaves the most money at the end?" and the answer crowned the
LTM roll-up combination at £595,694.63 — spendable £155,687.59 plus the home at its **gross** value, the
rolled-up debt (compounding at 6.5% toward the NNEG cap) nowhere in the figure. Every Stay-put plan's
"total incl. home" was exactly spendable + the same gross home value. The engine tracked the balance
per-year (`mortgageBalance`/`homeEquity()`/`netWealth()`, 2026-07-06) but the terminal headline
(`PathProjector` → `terminalTotalWealth`), the Monte Carlo percentiles/fan and every display surface read
the gross `totalWealth`. The 2026-07-06 call — "surface net worth as an addition, NOT by changing
`totalWealth`" — kept the misleading figure as the headline; the guard that would have caught it
(a terminal-headline assertion) didn't exist, only per-year-row assertions.

**Decision — one net definition, derived, everywhere.** `YearResult::totalWealth` is no longer a
constructor input: it is **derived in the constructor** as liquid + pension + **home equity** (property
net of the mortgage, NNEG-floored — the same definition `EstateValuer` uses at death). The now-identical
`netWealth()` is deleted. `terminalTotalWealth`, the Monte Carlo terminal percentiles, fan charts,
Compare, PDF, CSV, assistant facts and the builder live preview all inherit the net figure from that one
home; gross property remains visible only as the `propertyWealth` leg (equity-breakdown note). Labels
change from "incl. home" to "incl. home equity". `ScenarioForecaster::ENGINE_VERSION` bumped to
`finance-engine/phase-3-net-wealth` (stored phase-3 wealth figures are gross and not comparable).
**Why:** Rob's ruling — the project exists to show what the household can actually live on; "she can't
eat the building", and a lender's share of the bricks is not the household's money. Gross-vs-net is the
lump-sum-tax-shock lesson again (£100 gross pays £20 of food): a total that ignores a liability is not a
total. Completeness rule applied to liabilities: every debt that should reduce a result must reach it.
**Guards:** `WealthReconciliationTest` now runs the parts-sum invariant with and without a roll-up
mortgage and pins that the debt reaches the **terminal headline** (the assertion whose absence let this
live); `LifetimeMortgageRollUpTest` asserts the headline nets the consumed home.
**Consequences:** stored Monte Carlo runs hold gross wealth percentiles until re-run (deterministic
surfaces recompute live and are already net). A **static repayment mortgage now visibly dents total
wealth to the end** (the engine never amortises a balance — flagged v1 limit; set a redemption year +
repay-from-capital to clear it, or build amortisation, which needs a rate input).
**Status:** active (supersedes the "totalWealth stays gross" block of 2026-07-06)

## 2026-07-08 — A scenario's name states what its overrides actually model
**Context:** The rename audit (same session) found names that misdescribed the model: "Let to Rent +
Retire 5 years later" contained no letting (it models retire-at-72 + full SP £241/wk + disability benefit
off); "child helps £330/mo" stores £4,000/yr (£333/mo); "min wage, 1/2 time" stores £12,400; every
disposal plan silently raises the mortgage owed to £208k. In a Compare table the name IS the finding —
a wrong name misattributes a result to the wrong lever.
**Decision:** All 15 what-if children renamed to state their modelled changes (mechanics + figures, e.g.
"Sell (repay £208k) & buy near kids £300k + child £300/mo (taxable)"). Renames went into each child's
`overrides['name']` (its one home) + `projectFrom()`; no runs invalidated (a rename changes no input).
Surfaced for Rob, not changed: the buy plan's child-help is **taxable** while the stay-put ones are
tax-free (a gift isn't taxable income — likely under-credits that plan); confirm £208k is the true
redemption figure. Side effect: cached thresholds re-key (the inputs hash covers the whole effective
state, name included — arguably it shouldn't; open refinement).
**Status:** active

## 2026-07-08 — The assistant generation is queued to the worker (the Compare-page 504)
**Context:** Rob asked the Compare-page assistant a long multi-part question and got a raw nginx 504.
Two causes stacked: the synchronous v1 design ran the whole Ollama generation inside one Livewire
request (up to 120s × 2 guarded attempts) while Herd's nginx applies the default 60s
`fastcgi_read_timeout`; and the asked capability — "build me a what-if" — is the approved-but-unbuilt
docs/PLAN-assistant-scenario-editing.md spec, so the best possible outcome was a polite decline the
timeout then hid. (The requested what-if was hand-built the supported way instead: an ordinary
delta-child composing the three existing override patterns.)

**Decision — the generation moves to the background worker as a transient `AssistantTurn`.** `ask()`
persists a queued row (question + prior turns, **encrypted at rest** — it is real household talk) and
dispatches `RunAssistantTurn` (tries 1, timeout 300s); the panel polls at 1.5s exactly as the results
page polls a run (same `SimulationStatus` lifecycle, same awaiting-worker hint, so a missing worker is
loud, not a hung "Thinking…"). The context assembly moved intact from the Livewire component into
`App\Assistant\AssistantTurnRunner`; guardrails G1/G2 are unchanged. The advice-vs-guidance line is
resolved with `Gate::forUser(` the turn's owner `)` — a worker has no session, and the answer must
carry the asker's own `interpret` permission. **Rows are transient, not a transcript:** the poll
deletes a turn the moment its answer joins the browser-local transcript, Clear deletes leftovers, and
user/scenario deletion cascades — so no conversational record accrues server-side and GDPR erase needs
no new step. Every terminal state surfaces with its reason (done / refused / failed / cancelled-out
-from-under), preserving no-silent-failure. Cost: assistant answers now need `php artisan queue:work`
running locally — the same operational bar as a full forecast run.

**Also — machine config, outside the repo:** a per-site nginx conf
(`~/.config/herd/config/valet/Nginx/retireforecast.test.conf`) raises `fastcgi_read_timeout` to 300
for this site only, keeping the remaining synchronous model call (the Ideas tab's capture) and any
other slow request safe. Documented in HANDOVER "How to pick up"; delete the file to fall back to
Herd's catch-all.
**Status:** active

## 2026-07-08 — Freshness guardrails wired into a scheduled CI run (monthly, not push-triggered)
**Context:** `figures:freshness` (gov.uk statutory figures, 12-month window; 2026-06-30) and
`mortality:refresh` (ONS grid in-sync with its JSON source + 24-month window; 2026-07-01) existed
as on-demand commands only — nothing ran them unattended, so aging figures would rot silently.
The plan's "CI / data hygiene" item asked for a scheduled/CI run.

**Decision:** a dedicated GitHub Actions workflow (`.github/workflows/data-freshness.yml`) runs
both commands **on a monthly cron (06:00 UTC on the 1st) + manual dispatch**, failing the run on
any non-zero exit. Deliberately **not** push-triggered: both checks are time-based (staleness
windows of 12/24 months — a monthly tick is ample resolution), and the only push-sensitive part
(the embedded mortality grid drifting from its JSON source) is already guarded per-push by the
unit-tested in-sync check, so push runs would add noise, not signal. NB GitHub pauses cron on
repos inactive ~60 days; the workflow lands on GitHub only when Rob next pushes.

**Also:** the public `/methodology` page (2026-07-03) joined the Pa11y CI sweep (`.pa11yci.json`)
— it was the one public page added after the sweep was configured; it passes.
**Status:** active

## 2026-07-07 — Decision-support Phase 6: the assistant states computed limits, gated by a live inputs-hash match
**Context:** The final phase of docs/PLAN-decision-support.md. The assistant must be able to answer "how much can
we spend on a house?" with the computed, banded limit — but the plan's hard rule is that it *states* thresholds and
never calculates one, and its known failure mode (risk note) is confidently restating a STALE threshold after an
input edit.

**Decision — freshness is decided by recomputing the inputs hash, not by trusting the row's existence.** A new
`App\DecisionSupport\ThresholdFacts` resolves the scenario's Done `ThresholdResult`s and, per row, recomputes the
Phase-1 inputs hash from the row's own stored parameters (lever, param, condition pair, metric, target, grid,
paths) against the scenario's CURRENT effective form-state + engine version + seed; only a `hash_equals` match may
produce a fact. Builder edits already delete threshold rows (Phase 1's invalidation) — this is the belt-and-braces
the plan demanded before the assistant may voice a figure, and the test pins the exact belt-and-braces case: a
direct builder-state edit that bypasses the delete leaves the row in place, yet the limit never surfaces.

**Decision — a stale limit is excluded from the context, so G1 does the refusing.** The facts are appended to
`ScenarioContext` (a new `$extraFacts` hook), which is BOTH the model's context and the grounding allow-list — one
home. While fresh, the model's "up to about £27,000" sentence is grounded and answers; after an edit the same
sentence contains a figure absent from the allow-list, so `FigureGrounding` (G1) refuses it mechanically. No new
guard code: staleness handling composes out of the existing guardrail. When nothing fresh exists, a single honest
"none computed for the current inputs yet" fact makes the model say so instead of improvising.

**Decision — one wording home per limit.** The 1-D fact reuses `ThresholdPresenter::meterCaption` (made public) —
the SAME sentence the meter shows; the frontier fact reuses `FrontierPresenter`'s both-levers-pinned summary +
column chips; the care fact reuses `careComparison` (a pinned before/after with each state's CI — never an
interpolated limit). So panel and assistant can never disagree, and the neutral-phrasing guardrails (no
"safe"/"should", limits always an "about" band, G2 `OutputPhrasing` runs on every reply) hold in one place —
asserted directly on the generated fact text.

**Also — the context now volunteers the survivor cliff.** `ScenarioContext` gained income-floor facts from the one
`ResultPresenter::incomeFloor()` definition: the both-alive floor, the survivor-year twin and the cliff (coverage
points lost at the first death), plus a starter question — the binding risk a reader rarely knows to ask about.
**Status:** active (decision-support Phases 0–6 all built; feature complete pending Rob's browser sign-off)

## 2026-07-07 — Decision-support Phase 5: the 2-D frontier as a heatmap riding the ThresholdResult record
**Context:** Phases 0–4 of docs/PLAN-decision-support.md are built; Phase 5 renders the engine's parametric
threshold (S2, `SweepEngine::frontier`) — the buy-price ceiling as a function of retirement age — because a
single-lever threshold prints as if it were unconditional ("£260k" hides "at 67; £300k at 70"). The spec offered
two forms: a family of curves or a success heatmap with the target iso-line.

**Decision — heatmap, with the iso-line emerging from the cells (never drawn beside them).** The frontier keeps
**every measured cell**: `FrontierPoint` now carries its full per-column `SweepCurve` alongside the crossing, and
the UI renders the whole grid as a tinted table (each cell's success % as text; the on-track tint flips at the
target). The "iso-line" is therefore the visible boundary in the very cells it is derived from — the crossing is
`findCrossing` of the carried curve (engine-tested equal), so map and line cannot disagree. A curve family would
have re-plotted the same data less legibly for the non-numbers reader and hidden the per-cell evidence.

**Decision — a frontier is the SAME record kind, not a new store.** It rides `ThresholdResult` with two additive
nullable columns (`condition_lever_key`, `condition_grid`) as the discriminator (null = 1-D). Both join the inputs
hash, so a 1-D threshold and a frontier over the same lever can never answer for each other, and an identical
re-request is a cache hit. This buys the whole Phase-1 machinery for free: builder-edit invalidation (the delete
cascade), live progress + cancel (`SweepEngine::frontier` gained a per-cell `onProgress` — the longest run in the
app never runs silently), the awaiting-worker hint, and the owner-scoped CSV route (the controller branches to
`FrontierCsvExporter`). The payload readers are strict: `thresholdOutcome()` is null on a frontier row and
`frontierOutcome()` null on a 1-D row — the column pair decides, never payload sniffing.

**Decision — cost is bounded by construction.** The condition axis defaults to a deliberately **coarse** grid
(`defaultConditionGrid`: five held values over the 1-D span — every column costs a full common-random-numbers
sweep) and the queued run defaults to `FRONTIER_DEFAULT_PATHS` = 1,000 paths/cell, half the 1-D density: a cell's
95% Wilson interval is still ≈±2 points near a 90% success rate — tight enough to band each column's crossing —
and the ~45-cell map stays minutes on a queued worker. Both are recorded provenance and overridable. The care
toggle is refused on either axis (a categorical pin-and-compare has no range to sweep or hold), and a same-lever
pair is refused (the second `apply` would overwrite the first). v1 pairs household-wide levers; the UI offers the
headline pair only (buy price × retirement age), gated to a scenario where both axes are live (a configured buy +
someone still working).

**Correctness pin (the spec's test):** a frontier column is **byte-identical** to the Phase-1 1-D compute run on a
scenario that actually *holds* the condition (same pinned seed; `LeverThresholdServiceTest`) — the iso-line is the
1-D threshold repeated per held value, provably. The summary sentence pins BOTH levers ("up to about £X with
retirement at age A, and up to about £Y at age B") and the Phase-2 guardrails extend here: "safe" never appears,
ceilings are always "about" a banded value, colour never carries meaning alone.
**Status:** active (Phase 5 built; remaining: Phase 6 assistant tie-in; Rob's browser read of the V2 pair)

## 2026-07-06 — Age-varying spend (the "smile"): a per-line, piecewise-real `SpendPath` in the engine
**Context:** `ExpenseProfile` held a single flat-real essential + discretionary spend with no age-banded path anywhere
in the engine. That is not just a fidelity gap — flat-real-to-death **understates** how much can be safely spent
early (spend actually declines through retirement — Blanchett's "smile": ~1%/yr real to a ~26%-below trough at ~84,
then a late health-cost uptick), so a flat plan **manufactures the very under-spending** the FCA-planner case study
(James Shack's "Mark", logged in PLAN "The under-spending case" 2026-07-06) warns about, and it **biases the
buy/rent/downsize verdicts** the tool exists to compute. Rob promoted it to the next engine piece and chose the
**per-line-item** scope; the representation was researched (below) and delegated to me.

**Research (the industry norm).** No single universal form, but for a per-line model the market converges on
**per-item, age-bounded amounts** = piecewise breakpoints per line: **Voyant** (UK adviser market-leader) — stepped
expenditure with per-item start/end ages; **Kitces/Basu "age banding"** (the most accurate method) — decline modelled
**per category**, because the *composition* shifts (travel/leisure fall, healthcare rises); **RightCapital** — a
go-go/slow-go/no-go convenience layer (start age + % per phase) over the same idea; **Blanchett** — a %/yr real-decline
curve at the aggregate level. Fuller write-up: docs/RESEARCH-under-spending-smile.md.

**Decision — one representation: a `SpendPath` value object, a piecewise-constant real path of `{fromAge, amount}`
bands.** It is a strict superset of every industry form (a flat spend = one band; Mark's £60k→£40k@75 = two;
go-go/slow-go/no-go = three; a Basu per-category schedule = however many; a Blanchett curve = a band per year), it is
exactly what the future "hand-draw the smile" editor emits, and convenience templates (%/yr, phases) compile *down to*
bands so the store stays general. Reconciliation-friendly by construction: `amountAt` is a pure lookup and `plus` sums
two paths band-for-band, so an aggregate path is the exact per-age sum of its line paths — the same "line items are the
source, totals derived" discipline, extended from a scalar sum to a per-age sum.

**Decision — per-line-item scope subsumes "discretionary only".** Each expense line carries its own optional path
(`builder_state.expenseLines[].bands`); essentials that stay flat simply carry no band, discretionary that fades
carries a declining one. This is the accurate choice precisely because it captures *composition shift*, which an
aggregate smile only approximates. **Only an `always`-condition line may smile** — a contingent cost (mortgage /
service charge / commute) is flat and stops by its condition, so a band on it is ignored (a flagged v1 limit: contingent
costs don't fade with age). The separately-modelled care spell (`CareCostSampler`) already provides the late-life
upturn, so the engine models the down-slope; the late rise is care, not a discretionary band.

**Decision — the scalar is the path's first band (one home, no drift).** `ExpenseProfile` keeps the
`essentialAnnualSpend`/`discretionaryAnnualSpend` `Money` scalars as the **headline** (start-band) figures — the value
every non-age-aware consumer (benchmarks, presenters, sweep levers, ~30 construction sites) already reads — and adds
the canonical `SpendPath` alongside, defaulting to `flat(scalar)`. The constructor **throws** if a supplied path's
first band disagrees with the scalar; a caller building a path derives the scalar from `SpendPath::startAmount`. The
projector reads spend at the **reference (first-declared) person's age** each year (same convention/limit as
`oneOffCosts`). A flat plan has one-band paths → every scalar and lookup returns the one value → **byte-identical to the
pre-smile engine** (the whole suite stayed green with no test edits at each slice). The Monte Carlo and
`HistoricalBacktester` inherit the smile for free (both run through `PathProjector`). Guards: `SpendPathTest`,
`SpendingSmileTest` (projector steps at the band age, essentials hold, reconciles), `SpendingSmileAssemblerTest`
(aggregate == Σ line paths at every age; only always-lines smile). **Built engine-first (slices 1–4); the builder UI +
result surfacing follow.**

## 2026-07-06 — Equity-release lifetime mortgage: a rolling-up (compounding, unpaid) mortgage in the engine
**Context:** Modelling the V2 couple taking a lifetime mortgage with no payments (vs servicing the interest) needed
something the engine did not have: a mortgage whose balance COMPOUNDS unpaid and is repaid from the estate. The
engine held `outstandingMortgage` as a static figure — never accrued — so a roll-up could only be faked with a
hand-computed balance (lossy: wrong across the Monte Carlo's varying death ages, invisible in headline wealth, no
No-Negative-Equity cap). Rob chose to build it properly rather than approximate (accuracy over less work); it also
fills the equity-release GAP already flagged in the competitive-gap analysis + docs/PLAN.md backlog.

**Decision — one nullable `Property::mortgageRollUpRate` (fixed nominal Percent).** Null = the balance is static,
exactly as before (a repayment/interest-serviced mortgage, whose interest — if any — stays an expense line); set = a
lifetime mortgage that rolls up. Orthogonal to `mortgageRedemptionYear`/`mortgageMaturityAction` (a lifetime mortgage
sets no redemption year — it is repaid on death/sale/care). Mapped through the assembler, builder (blankProperty +
validation + loadState backfill + a UI input + the `BuilderStateFixture` — the four-place new-field move) and a
results note.

**Decision — accrue at the FIXED NOMINAL rate in `PathProjector::growState`, NNEG-capped at the home value.** The
engine works nominally internally (deflated to real for reporting) and a lifetime-mortgage rate is a fixed nominal
contractual rate, so the balance compounds at the entered rate directly each year with NO inflation interaction
(correct fixed-for-life behaviour), capped at the share-scaled home value each year (the Equity Release Council
No-Negative-Equity Guarantee — never owe more than the home). The grown balance already flows into the estate/IHT
(`EstateValuer` subtracts it, flooring home equity at zero = NNEG at death). Guard: `LifetimeMortgageRollUpTest`
(compounding to the penny, NNEG cap, estate erosion, reconciliation).

**Decision — surface net worth as a reconciled `YearResult` addition, NOT by changing `totalWealth`.** `totalWealth`
keeps its long-standing gross-of-mortgage definition (unchanged for every existing scenario); a new `mortgageBalance`
leg + derived `netWealth()`/`homeEquity()` (home equity NNEG-floored, mirroring `EstateValuer`) make the debt
visible. Without it a roll-up MISLEADS — gross total wealth flatters it (not paying the mortgage preserves liquid
assets), so on the V2 base the roll-up reads £530k gross but £212k net. Plus an `inputNotes` roll-up flag stating the
projected end balance and what it leaves to heirs.

**Why:** A lifetime mortgage's entire decision content is the trade-off between freeing cashflow now and the
compounding debt hollowing out the inheritance; only a real accrual (not a static figure) shows it, and only a net
figure keeps the wealth line honest. Deterministic V2 read: the roll-up frees ~£7,080/yr so the money never depletes
(base depletes 2045) but cuts the estate ~£371k → ~£201k. Built the two V2 what-ifs (roll-up vs serviced) on it.
**Status:** active.

## 2026-07-06 — Decision-support Phase 4 (part): the care-on/off "pinned" lever (fifth/last survivor-menu lever) + the first settings-flipping lever
**Context:** The last lever of the Phase-4 menu, and a different shape from the rest. Late-life care is an
off-by-default six-figure fat tail; left out, it silently flatters every other threshold on the page, so the
lever exists to show how much of the odds that tail actually moves. Grounded by a 4-agent workflow (understand
care modelling / the sweep-CRN machinery / the app threshold surface / the toggle + plan intent → a synthesised
design). The two "decisions for Rob" the design surfaced were **already settled by the approved plan** (the plan's
Phase-4 line says "pin-and-compare, not a monotone sweep"; Phase 0 explicitly **defers** per-component RNG
substreams), so they were followed, not re-litigated.

**Decision — a binary `CareModellingLever` flipping a SETTING, not the household; a pin-and-compare, not a sweep.**
New engine `Sweep\Lever\CareModellingLever` toggles `ForecastSettings::modelCareCost` over a two-point grid
[0.0, 1.0] (0 = not modelled, 1 = modelled) — the first lever that flips a **setting** rather than mutating the
household (`SweepInputs` already carries settings; `SweepEngine::sweep` already feeds them to the simulator, and
`Simulator` builds the `CareCostSampler` only when the flag is on, so the flip alone turns care on — no simulator
change). New immutable `ForecastSettings::withModelCareCost(bool)`. It sets both states **explicitly** (threshold
0.5), independent of the scenario's own care setting, so the readout is always a clean off-vs-on. `LeverKey::Care`
('care'), wired through `buildLever`/`defaultGrid` + the whole existing queued `ThresholdRunner`/`ThresholdResult`
store/cache/CSV pipeline unchanged (a 2-point grid needs nothing new there).

**Decision — `LeverDirection::Unknown`, and the two states are NOT common-random-numbers comparable.** Turning care
on inserts extra draws (a per-person Bernoulli always, + duration + type on a hit) **between** the death draw and
the investment-return path (`Simulator` draws lifespans → care spells → returns), so on the same seed the two
states' return streams **desync** — care-off and care-on are two INDEPENDENT samples, not a like-for-like pair.
So it is never monotone-fit, and the difference carries the full sampling noise of two runs. The app layer therefore
**branches** for this lever: no slider, no live line, no green→red meter, no S-curve, and it deliberately **ignores
`ThresholdOutcome::crossing`** (an interpolated "63% of care" limit is meaningless). Instead `ThresholdPresenter::careComparison`
renders a two-state before/after — each state a 10-dot pictograph + word-band + its own Wilson CI + path count — and
reads the delta **qualitatively**: a real drop only when the two CIs clearly separate, "about the same" when they
overlap (never a bare percentage the noise could invent). The CSV likewise omits the crossing and names the two
states. Copy states plainly it is a pinned before/after, not the same paths with a bill added. **Per-component RNG
substreams (which would make the two states a precise CRN difference) stay deferred** per the plan — building
pin-and-compare now does not preclude them later.

**Decision — offered ungated (single or couple).** Unlike the survivor levers, care risk applies to a lone person
as much as a couple and is off by default, so the menu offers it always (household-wide id, no `lever_param`); it
sits last as a sensitivity check on the other levers.

**Why:** A binary presented as a *sweepable threshold* would draw a line through "off" and "on" and print a
meaningless interpolated limit, misleading the exact non-numbers audience the feature serves. The honest shape for a non-CRN-comparable binary is a pinned before/after with each side's own
uncertainty shown — which is what the plan asked for. Tested: the lever flips only the setting (household untouched —
reconciliation) and is `Unknown`; the wither preserves every other setting (no drift); modelling care **lowers the
ceiling** and the care tail **reaches the result** (`careImpact` share > 0 — per-source completeness, no silent
drop) at the production 2,000 paths; the menu offers care ungated (single + couple); `findLimit` stores the binary
[0,1] grid; a completed care threshold paints the two states with no slider/meter/S-curve and no banned "safe"
wording. **Phase 4's lever menu is now complete (5 of 5).** See [docs/PLAN-decision-support.md](build/PLAN-decision-support.md)
+ [[data-consistency-reconciliation]]. **Status:** active

## 2026-07-06 — Decision-support Phase 4 (part): the defer-the-survivor's-State-Pension lever + a State Pension deferral correctness fix (fourth survivor lever)
**Context:** The fourth survivor lever, "defer the *survivor's* State Pension" — reusing the per-person `lever_param`
parameterisation the longevity lever built. Building it surfaced a **modelling gap that had to be fixed first**: the
engine modelled deferral as a **free uplift** — it paid the uplifted rate from State Pension age with **no forgone
income and no delayed claim** (`PathProjector::statePensionIncome` gated on `spaYear`; METHODOLOGY documented it the
same way). On that model deferring is always beneficial, so the lever would be trivially monotone and would tell the
decision-makers "defer as much as possible" — wrong (new-State-Pension deferral only pays back if you live ~17+ years
past State Pension age; no lump-sum option post-2016), and directly contradicting the plan's S3 spine, which assumes
SP-deferral is **non-monotone** (the non-monotonicity *is* the forgone-income cost). Rob chose (asked): **fix the model
first, then build the lever** (accuracy over less work).

**Decision — model deferral as a delayed CLAIM, not a free uplift.** A new per-person `spClaimYear = spaYear +
round(deferralWeeks / 52)` gates the paid State Pension in `PathProjector`; the pension pays **nothing** during the
deferral window (the forgone income) and the uplifted rate from the later start. **`spaYear` itself is unchanged** and
still governs the NI cut-off and the Pension Credit qualifying-age gate — deferring delays *claiming*, not *reaching*
State Pension age. **Pension Credit notional add-back:** a paused deferred pension still counts as assessable income for
Pension Credit during the window (DWP treats it as income you could be drawing), so `meansTestedBenefitNominal` adds the
notional undeferred amount back — deferring cannot conjure Pension Credit it would not otherwise get (a completeness
guard, per the data-integrity rule). The **results-page "State Pension starts" milestone** (`ResultPresenter::milestones`)
now lands on the claim year too, so the milestone and the income line agree. No existing scenario shifts (all live data
defers 0; the `full` fixture's 8 weeks rounds to 0 years). Engine tests pin the trade-off: forgone income in the window,
the uplift from the later start, and an **early death after deferring is a net lifetime loss** — the shape that makes the
lever non-monotone.

**Decision — a per-person `StatePensionDeferralLever`, `LeverDirection::Unknown`, CRN-safe.** New engine
`Sweep\Lever\StatePensionDeferralLever` (constructed with a person id) sets that person's `deferralWeeks` (+ an immutable
`StatePensionEntitlement::withDeferralWeeks`); the lever sweeps in **years** (grid 0–5), converted to weeks. Everyone
else, and every non-State pension, passes through untouched. `LeverKey::StatePensionDeferral` ('sp_deferral'); wired
through `buildLever`/`defaultGrid` + the presenter's value-label ("N years later" / "claim on time") and trade-off
caption; the `ThresholdExplorer` menu offers one entry per person **who holds a State Pension**, gated to a couple
("How long <person> defers their State Pension").
- **Direction is `Unknown`, not monotone** — a little deferral helps a long-lived survivor, too much loses more forgone
  years than the uplift returns. Must not be monotone-fit; the caption points at the full sweep.
- **Resolved the flagged RNG modelling call — CRN-safe (as the longevity one turned out to be).** The lever changes only
  a deterministic figure (an uplift + a shifted claim year); it touches neither the mortality draws nor the return path,
  so common random numbers stay aligned across the grid. `Unknown` stands purely on non-monotonicity, not on RNG. (The
  plan had *assumed* SP-deferral desyncs RNG — it does not; don't assume, verify.)

**Why:** Whose-State-Pension-to-defer is the survivor-cliff insight (the survivor's raises the floor they lean on after
the first death; the first-dier's is largely wasted — a State Pension is not inherited). A lever resting on a free-lunch
model would give misleading decision-support to the exact non-numbers audience the feature is for, so the correctness fix
was load-bearing, not optional. The headline completeness test now lands: **deferring the survivor's State Pension raises
the survivor-year income floor while deferring the first-dier's does not** (read through the `incomeFloor` survivor-year
twin). **One survivor lever remains** (care-on/off pinned — a binary, not CRN-comparable). See
[docs/PLAN-decision-support.md](build/PLAN-decision-support.md) + [[data-consistency-reconciliation]] + [[accuracy-over-less-work]].
**Status:** active

## 2026-07-05 — Decision-support Phase 4 (part): the per-person longevity sweep lever (third survivor lever) + per-person lever parameterisation
**Context:** The third lever of the survivor menu, and the first that is *parameterised by which person* it moves.
The tool already had a combined "live 10 years longer" bump (a QuickWhatIf that offsets everyone). The Phase-4 insight
is to **split longevity per person**: on a survivor-cliff couple, extending the *better-provided* partner's life and
extending the *survivor's* life pull the odds in opposite directions, so "whose longevity" is the decision, not "how
much longer" in aggregate. This is the same shape as the coming defer-the-survivor's-SP lever, so the per-person
parameterisation built here is the shared foundation for both.

**Decision — an OffsetYears lever on ONE named person, `LeverDirection::Unknown`, CRN-safe.** New engine
`Sweep\Lever\PersonLongevityLever` (constructed with a person id) sets that person's `LongevityAdjustment` to
`OffsetYears(value)` and leaves everyone else untouched (+ an immutable `Person::withLongevity`). Grid is a ±year
offset from the cohort peer (−5..+15, 0 = peer).
- **Direction is `Unknown`, not monotone.** Success is genuinely non-monotone in the offset (whose life it is decides
  the sign), so the sweep must report the first crossing and flag that others may exist — never fit a single monotone
  crossing. The presenter's caption is honest about this ("living longer moves the odds both ways — read the full
  sweep, not a single limit").
- **Resolved the flagged RNG modelling call — and it came out better than assumed.** The plan flagged "the longevity
  lever desyncs RNG". Verified against the sampler: `OffsetYears` applies the shift **after** the peer death is drawn
  (`JointLifeSampler`/`RepresentativeDeathAge`), so the *number* of mortality draws is unchanged by the offset —
  common random numbers stay aligned across the grid. (A `FixedAge` or `MortalityMultiplier` lever *would* consume a
  different number of draws and desync; the offset lever does not.) So the lever is CRN-safe; `Unknown` stands purely
  on non-monotonicity, not on RNG. Verified too that the MC sampler honours `LongevityAdjustment` (it does).

**Decision — per-person parameterisation via a `lever_param` column, kept separate from `lever_key`.** A `LeverKey`
enum can't carry instance data, so `?string $leverParam` (the person id) is threaded through
`LeverThresholdService::buildLever/compute/deterministicForecastAt` and `ThresholdRunner::request/inputsHash/createRun/execute`,
and stored in a new nullable `threshold_results.lever_param` column. It **joins the inputs hash**, so one person's
threshold is never served for another. It is a **separate column, not folded into `lever_key`** (encoding
`person_longevity:p1` into the key would break `LeverKey::from`), so `lever_key` stays a clean enum value. The
composite `person_longevity:p1` id lives only in the `ThresholdExplorer` UI menu (one entry per person, gated to a
couple, named "How long <person> lives"); storage stays split.

**Why:** Whose-longevity is the load-bearing survivor-cliff insight; a single combined bump hides it. CRN validity
(pinned seed across the grid) is what makes a threshold trustworthy, so the OffsetYears-after-draw property was worth
verifying rather than assuming. Keeping `lever_key` a pure enum value keeps the cache key, the mapper and the model
accessors simple.

Tested engine-side (offsets only the named person, rounds, direction Unknown; a +12y offset reaches the deterministic
forecast's death year) and app-side (the −5..+15 default grid; a scenario computes an Unknown-direction longevity
threshold; the explorer offers one lever per person for a couple and none for a lone person; finding the limit records
`lever_key`+`lever_param`; the same lever on different people is a distinct cache key; a completed longevity threshold
paints "+8 years" with no leaked directive). **Two survivor levers remain** (defer-the-survivor's-SP — reuses this
parameterisation and lands the "deferring the survivor's SP raises the floor, the first-dier's does not" test — and
care-on/off). See [docs/PLAN-decision-support.md](build/PLAN-decision-support.md) + [[new-builder-field-delta-gotcha]].
**Status:** active

## 2026-07-05 — Decision-support Phase 4 (part): the joint-life-annuity survivor-share sweep lever (second survivor lever)
**Context:** The second lever of the survivor menu, the annuity analogue of the DB survivor-share lever.
`AnnuityPurchase::survivorFraction` (on `DcPension`) is the share of a lifetime annuity's income that carries on to
the surviving partner after the annuitant dies — guaranteed income through the survivor cliff, and, unlike the DB
fraction, an actual purchase decision the household makes.

**Decision — mirror the DB lever exactly: monotone, CRN-safe, varies only annuities that are already joint-life.**
New engine `Sweep\Lever\SurvivorAnnuityFractionLever` (+ immutable `AnnuityPurchase::withSurvivorFraction` and
`DcPension::withAnnuityPurchase`) sets the survivor's fraction on the household's joint-life annuity to the swept
value (0–100%, clamped). The three calls match the DB lever:
- **It never turns a single-life annuity joint-life.** The lever varies only annuities whose `survivorFraction` is
  already non-null. A single-life annuity is priced on a single-life quote, so making it joint-life at the *same
  rate* would model survivor income the quote never paid for (the engine takes the rate as a user input and does not
  reprice for joint-life). Non-annuitised pots and single-life annuities pass through unchanged; the annuitant's own
  income is held fixed — only the survivor's share moves.
- **`LeverDirection::Increasing`, CRN valid.** More survivor income can only raise success, and the change touches
  neither the mortality draw nor the return path.
- **Gated** in `ThresholdExplorer` to a couple with a DC annuity that already provides a survivor fraction — the two
  survivor-share levers (DB, annuity) gate independently, so a household sees each only where it applies.

Wired through `LeverKey::SurvivorAnnuityFraction` + `buildLever` + `defaultGrid` (0–100%) + the presenter's value
(grouped with the DB `%` arm) and caption arms. Tested: the lever moves only joint-life annuities and clamps 0–100
(engine); a bigger annuity survivor income does not lower success on a survivor-cliff couple (MC sweep); the default
grid brackets 0–100 and a scenario with a joint-life annuity computes the threshold (app); the explorer offers it for
the joint-life-annuity fixture and hides it for the rich fixture (which annuitises nothing but does show the DB
lever). **Three survivor levers remain** — per-person longevity (split), defer-the-survivor's-SP, care on/off pinned
(the first two desync RNG → `LeverDirection::Unknown`). See docs/PLAN-decision-support.md Phase 4.

## 2026-07-05 — Decision-support Phase 4 (part): the survivor's-DB-share sweep lever (first of the survivor lever menu)
**Context:** Phase 4's remaining work is the survivor-first lever menu. The binding risk on a couple is
survivor poverty after the first death; a Defined Benefit scheme's `spousePensionFraction` is guaranteed income
that carries the survivor through the cliff, so "how much survivor provision does the money need?" is a first-class
lever. This is the first of the five (the others: per-person longevity, defer-the-survivor's-SP, joint-life annuity
survivor %, care on/off pinned).

**Decision — a monotone, CRN-safe household lever that varies only schemes that already offer a survivor pension.**
New engine `Sweep\Lever\SurvivorDbFractionLever` (+ an immutable `DbPension::withSpousePensionFraction`) sets the
survivor's fraction on the household's DB scheme(s) to the swept value (a percentage, 0–100, clamped). Three
deliberate calls:
- **It never invents a benefit.** The lever varies only DB schemes whose `spousePensionFraction` is already non-null
  (a scheme that offers no survivor pension is left untouched); sweeping a null-fraction scheme would model income
  that does not exist. Non-DB pensions pass through unchanged.
- **`LeverDirection::Increasing`, and CRN stays valid.** More guaranteed survivor income can only raise the chance
  the money lasts, and the change touches neither the mortality draw nor the return path — so the sweep's common
  random numbers hold and the crossing may be found by a monotone fit (unlike the longevity/SP-deferral levers still
  to come, which desync RNG and must declare `Unknown`).
- **Gated to where it means something.** `ThresholdExplorer` offers it only for a couple (a survivor to inherit) with
  at least one DB scheme that provides a survivor's fraction. Default grid spans the whole 0–100% range (a spouse's
  pension is commonly half, sometimes two-thirds). Wired through `LeverKey::SurvivorDbFraction` + `buildLever` +
  `defaultGrid` + the presenter's value/caption arms; the outcome mapper needed no change (it keys the lever by its
  string value, not an exhaustive match).

Tested: the lever moves only survivor-pension schemes and clamps 0–100 (engine); a bigger survivor pension does not
lower success on a survivor-cliff couple (MC sweep); the default grid brackets 0–100 and a real scenario computes
the threshold end-to-end (app); the explorer offers it for the couple-with-a-survivor-pension fixture and hides it
for a single person with no DB. **Four survivor levers remain** (per-person longevity, defer-the-survivor's-SP,
joint-life annuity survivor %, care on/off pinned). See docs/PLAN-decision-support.md Phase 4.

## 2026-07-05 — Decision-support Phase 4 (part): the survivor-cliff story (incomeFloor survivor-year twin)
**Context:** Phase 4 re-scopes decision-support around the binding risk on a couple — survivor poverty
after the first death. Its "survivor-cliff story" half calls out a correctness gap: `ResultPresenter::incomeFloor()`
snapshotted only the **last all-alive year**, so it read the floor *before* the cliff and understated the exact
risk. At the first death a State Pension stops and a DB pension may drop to its survivor fraction, while essentials
fall only by the survivor factor, so the survivor's coverage of essentials can fall sharply.

**Decision — add a survivor-year twin, don't replace the all-alive floor.** `incomeFloor()` keeps the mature
all-alive floor (unchanged for existing consumers) and now also carries a **`survivor`** twin computed at the
**deepest survivor year** (the last year exactly one person is alive) off the same `YearResult`, plus the signed
coverage **`cliff`** between the two. A new private `floorAt(YearResult)` is the single definition both floors read,
so they can differ only by year, never by how the figure is built — and the twin reconciles to the cashflow
ladder's survivor rows (a test pins essentialSpend + secure income to that year's engine figures). Null twin for a
single-person household or no survivor phase. Surfaced as a factual before/after **dumbbell** on the results page
(and a PDF survivor line): the coverage before vs after the first death, never a prediction of who dies first,
never a recommendation.

**Still open (Phase 4 remainder — the survivor lever menu):** re-scope the sweep lever menu around the binding
risk — per-person longevity (split from the combined bump), **defer the *survivor's* State Pension** (deferring the
first-dier's is wasted), **DB survivor fraction**, **joint-life annuity survivor %**, and **care on/off pinned**.
Each is a new engine `SweepLever` with real CRN/monotonicity calls (a longevity lever changes RNG consumption →
`LeverDirection::Unknown`; verify the MC sampler honours `LongevityAdjustment`) + per-scenario gating. Left for a
focused session. See docs/PLAN-decision-support.md Phase 4.

## 2026-07-05 — Decision-support Phase 3: the combination-comparison surface (its own, MC-based, neutral-unless-gated)
**Context:** the Compare page already lays a base beside its what-ifs, but on the DETERMINISTIC central
projection (a Yes/No grid). Phase 3 answers "which combination gives the best chance the money lasts?" across
the full Monte Carlo — the trade-offs the deterministic table can't show. The plan is explicit: this is its own
surface, never mixed into the deterministic grid.

**Decision — a new section on the Compare page (not a new page), backed by a neutral presenter + a walled-off
ranking.** Each compared plan (base + ready children) is scored on its latest completed Monte Carlo run as a
plain **word-band chip** ("Very likely to last") over a **net-position sparkline**, with the exact figures
(chance essentials/full-spend last, runs-short %, p10 + median usable wealth, paths) in a per-plan drill-down and
an owner-scoped CSV. Load-bearing choices:
- **No decimals in the headline.** 94.9% and 95.0% are Monte Carlo noise, so the chip is a *word* — the single
  banding home is `ResultPresenter::lastsBand()` (strong→poor, never the word "safe"), reused so a chip can never
  disagree with another word-band readout. The decimals live only in the drill-down + CSV.
- **Ordering is advice; it stays behind the `interpret` gate.** A best-first list is an implicit recommendation
  the phrasing lint is blind to. So the neutral `App\DecisionSupport\CombinationComparison` presenter is UNORDERED
  (plan order, base first) and never labels one plan "strongest"; best-first reordering + the "which to lean
  towards" narrative come from `Interpretation::combinationRanking()` (the walled-off layer) only when the gate
  allows — the same gate the deterministic compare narrative already uses. A test pins **neutral order when
  `compliance.personal_use = false`**. The row sort uses the SAME comparator the ranking narrative ranks by (most
  futures covering essentials, then full spend, then usable wealth), so the reordered rows and the "strongest"
  named in the narrative can never disagree; unsimulated plans sink to the end.
- **The surprising-lever callout is guidance, not advice.** A plan that models LIVING LONGER yet comes out with a
  HIGHER chance the money lasts (the survivor-cliff signal at the heart of this feature) gets a factual callout —
  phrased as an observation ("something worth noticing…"), never a recommendation, so it stays neutral-zone-clean
  (verified: the banned-phrasing partition passes in enforce mode).
- **One home for the plan set.** `CombinationComparisonData` assembles "base + ready children, each with its
  own-variant deterministic forecast + its latest completed MC result", shared by the Compare render and the CSV
  controller, so the screen and the download can't drift. Sparkline reuses the same net-position series (usable −
  Σ unmet, below £0) as the fan/burndown, so a glance can't contradict the ladder.

**Deferred (fast-follows):** a chosen-subset selector (v1 compares the whole family, matching Compare); per-plan
sparkline data as a full accessible per-year table (v1 gives the chip word + drill-down figures + CSV + an
aria-labelled trend). **Pending Rob's browser sign-off** (needs completed family runs — the local DB has none).
**Next: Phase 4 (survivor-first lever menu).** See docs/PLAN-decision-support.md.

## 2026-07-05 — Decision-support Phase 2: the "How far can we go?" results panel
**Context:** with the queued threshold backend built (Phase 1, below), Phase 2 is the decision-maker's view —
"how far can we move one lever before the money stops lasting?" for someone who is not a numbers person. The
plan's audience split is load-bearing: a plain-language/visual headline over a collapsed drill-down of the exact
figures.

**Decision — a nested Livewire component (`App\Livewire\ThresholdExplorer`) on the results page**, so its slider
drags and its threshold poll re-render on their own without re-running the whole results page (same nesting the
assistant uses). It offers only the levers the scenario can move (buy price when a buy is configured, retirement
age when someone still works, essential spend always), and:
- **The live line is deterministic, the limit is Monte Carlo.** Dragging the slider redraws an INSTANT deterministic
  net-position line (a new transient entry point `LeverThresholdService::deterministicForecastAt` — applies the
  lever via the same `SweepLever::apply` the sweep uses, runs one `DeterministicForecaster`, no MC, no persistence);
  "Find the limit" runs the queued Phase-1 threshold and paints a green→red meter. The line is explicitly labelled
  "a quick central estimate, not the full range" so it is never mistaken for the probability answer — honouring the
  plan's S1 warning that the deterministic pass is optimistically biased for a *threshold* (which is why the meter
  comes from MC, not the line).
- **Reuse over rebuild.** The net-position line reuses `ResultPresenter::burndown` (single plan) so it shares the
  ONE usable-wealth definition (liquid + pension, continued below £0 by cumulative shortfall) with the cashflow
  ladder and Compare — it cannot drift. The chart/table/CSV plumbing reuses the existing `chart()` Alpine contract
  and the Phase-1 CSV route.
- **`App\DecisionSupport\ThresholdPresenter`** turns a `ThresholdOutcome` into the view models: the net-position
  line, the meter (domain + crossing boundary + which side is on-track, from the lever's monotone direction), the
  analyst S-curve + grid, and a 10-dot natural-frequency pictograph.

**Neutral-copy guardrails (enforced + tested):** the word "safe" never appears in neutral copy (we say "on track");
the headline is a dot pictograph + a year-first phrase, never a bare percentage (the % axis is only in the analyst
disclosure); the meter chip carries an icon + text, never colour alone; and the death vertical is recoloured off
the shortfall-red band so the two reds don't collide. **Next: Phase 3 (combination comparison).**

## 2026-07-05 — Decision-support Phase 1: the queued threshold runner + persisted, inputs-hash-keyed store
**Context:** Phase 0 (the framework-free `SweepEngine`) and the Phase-1 *compute core* (`LeverThresholdService`,
scenario → threshold) were built (DECISIONS 2026-07-04). What remained of Phase 1 (docs/PLAN-decision-support.md):
turn the compute into a **queued, cancellable, cacheable, persisted** run so a threshold behaves like a first-class
result — the same discipline a `SimulationRun` gets, because a sweep is a set of Monte Carlo runs (a long run) that
must never run on the web request or silently.

**Decision — mirror the `SimulationRun` triad exactly, one table.** A single `ThresholdResult` model + table is
*both* the run (lifecycle + live progress + cancel, reusing `SimulationStatus`) *and* the stored result (the mapped
`ThresholdOutcome` = curve + crossing in the encrypted `payload`, null until done). `ThresholdRunner` mirrors
`SimulationRunner` (createRun / request-or-cache-hit / execute-with-onProgress-and-cancel), `RunLeverThreshold`
mirrors `RunScenarioSimulation` (holds the id, delegates, `failed()` marks a dead worker Failed). No new lifecycle
enum — `SimulationStatus` is generic (queued/running/done/failed/cancelled).

**Two-layer staleness guard (both, deliberately).** (1) **Primary — delete on edit:** a scenario save deletes its
`thresholdResults()` exactly as it deletes `simulationRuns()`, cascading to children (a threshold can exist without a
run, so it is checked independently). (2) **Belt-and-braces — inputs hash:** each record is keyed by a sha256 of the
**effective builder-state** (the single source of truth for every forecast input) plus the engine version and every
compute parameter (lever / metric / target / grid / paths / seed). So an identical re-request is a **cache hit** (no
second sweep) and a result is only ever surfaced while its hash matches the current inputs — even if the delete were
ever missed. The fixed seed (`LeverThresholdService::SEED`) makes a threshold reproducible and part of the key.

**Provenance + CSV.** Every record freezes seed / paths / grid / engine + tax-year versions / assumption snapshot
(the plan's "every threshold ships with its provenance"). The **CSV export** (owner-scoped route + `ThresholdCsvExporter`)
carries the shared **`App\Export\ExportDisclaimer`** — extracted from `ScenarioResults` so the fan/ladder/threshold
exports have **one home** for the guidance-only wording — plus the provenance, the honest crossing **verdict** (a band,
never a bare point, S3) and the full swept grid with each point's confidence interval.

**Path count:** the queued default is **2,000 paths/point** (`ThresholdRunner::DEFAULT_PATHS`) — higher than the compute
core's 500 so a persisted crossing is tight; recorded as provenance and overridable. The preview→confirm path-count
ladder is a Phase-2 concern. **Lever values stay float lever-space** (a price / age / annual spend), not `Money` — so
the payload mapper is float-not-pence (documented in `ThresholdOutcomeMapper`); the money rule still governs everything
the engine computes underneath. **Next: Phase 2 UI** (the "how far can we go?" panel). See docs/PLAN-decision-support.md.

## 2026-07-04 — Family / third-party contributions are DISREGARDED income for Pension Credit (researched)
**Context:** the decision-support feature has a "a child contributes ~£150–330/mo closes the gap" lever. Open
question (Rob's, then handed to research): does regular family money count as income that erodes Pension Credit,
or is it disregarded? Rob: *"Research and you tell me. How is it actually handled by the DWP."*

**Finding (sourced, verified 2026-07-04):** regular voluntary payments from a relative are **disregarded** for
Pension Credit — not income, not notional income. gov.uk's *"A detailed guide to Pension Credit for advisers and
others"* lists **"regular payments from a charity or relative"** under *"What doesn't count as income"*; entitledto
(*"Income from voluntary or charity sources"*) confirms voluntary payments from friends/family are *"disregarded
completely"*. So modelling the family-contribution lever as **fully disregarded income** is the *correct* DWP
treatment, not merely the optimistic one — the two coincide.

**Caveats to model when the lever ships (flagged in PLAN-decision-support Q2):** (a) **maintenance** (from a former
partner / the other parent of a child) is *not* voluntary and is not disregarded; (b) a **one-off lump sum** banked
as **savings** becomes *capital*, which PC *does* assess (tariff income above £10k; the £16k HB/CTS cliff) — a
regular income stream is disregarded, a large banked gift is not. Deferred lever, but the modelling call is made.
Sources carry `verified_on: 2026-07-04`.

## 2026-07-04 — Inheritance Tax wired into the forecast, relationship-status aware (the toggle now bites)
**Context:** the `ihtModelled` toggle was collected-but-unconsumed — stored, validated, shown in what-if diffs +
the GDPR export, but read by no forecast, so turning it on changed nothing. A silent drop of exactly the class the
completeness rule (CLAUDE.md) exists to catch. `InheritanceTaxCalculator` was complete + tested but called only by
its own test and the Filament tax-audit page. Built per [docs/PLAN-iht-and-relationship-status.md](build/PLAN-iht-and-relationship-status.md)
in six green slices (see `git log`).

**Decision — wire it in, and make it relationship-status aware.** `ForecastSettings::modelIht` drives `PathProjector`
to value the estate at each death (a new pure `Iht\EstateValuer` = liquid + home equity, pensions kept separate) and
compute the IHT due, surfaced on `ForecastResult::iht` (an `Iht\IhtOutcome`; null when off). A new
`Household::relationshipStatus` (married/civil-partner vs cohabiting, default married) drives the treatment; today's
implicit "everyone is married" is now explicit and overridable.

**The modelling calls (the plan delegated these open questions to the executing agent; taken here, with reasons):**
- **Nominal-at-death, deflated to real (open Q2 → option a).** The estate is valued in the death year's nominal
  pounds against the frozen nil-rate bands, then the result is deflated to today's money. So a growing estate against
  a frozen band is taxed more over the horizon — the real fiscal drag, matching how the projector already treats the
  frozen income-tax thresholds. Deflating to real keeps the panel consistent with every other figure.
- **Married: first death spousally exempt (£0, flagged), final death gets both bands (multiplier 2).** The
  transferable NRB/RNRB, since the whole first estate passed spouse-exempt. **Cohabiting: first-death transfer to the
  survivor is chargeable, one set of bands each.** So the same estate pays materially more IHT unmarried — the point
  of the feature.
- **Pensions in the estate only from a death in/after 2027 (the enacted April-2027 rule).** Gated on the death year,
  not a manual toggle; immaterial in practice (deaths are decades out) but correct for an early death.
- **RNRB "home to descendants" defaults ON when there is a home (open Q1).** A builder toggle (`homeToDescendants`,
  sparse-stored when off) unlocks the £175k-per-person residence band; the £2m taper still applies (a large estate
  loses it, correctly).
- **A first death splits the jointly-owned home 50/50 (v1).** Immaterial for a married couple (first death exempt);
  a documented simplification for a cohabiting couple. Per-person liquid + pension are already tracked individually.
- **Cohabiting survivor caveats surfaced, not silently applied.** A cohabiting partner may not receive a DB scheme's
  survivor pension and cannot inherit State Pension (not modelled for anyone) — flagged as input-sanity notes rather
  than auto-zeroing the income (some schemes do pay a nominated cohabitant; no silent overstatement, no silent change).

**Scope (v1, flagged):** deterministic forecast only (a Monte-Carlo IHT distribution is a later add); headline bands
only — no lifetime gifts/7-year taper, trusts, business/agricultural relief, the 36% charity rate, or non-descendant
beneficiaries. Education/guidance only in the UI (signpost to a solicitor / STEP / gov.uk). See DATA-MODEL "Known
divergences" (now closed) + docs/METHODOLOGY.md.

## 2026-07-04 — Assistant may assemble a reviewable what-if (a narrow, deliberate widening of "the model never builds")
**Context:** Rob asked whether the local-model assistant could **create scenarios** — ask targeted questions about
what to change from the base, then fill it in — and, on an explicit request, update the base. This reverses a rule
recorded emphatically in [docs/RESEARCH-local-assistant.md](research/RESEARCH-local-assistant.md) (§0/§3, risk A4),
[config/assistant.php](../config/assistant.php) and DECISIONS 2026-07-03: the model *"never builds; its only write is a
backlog append"* (A4: *"Rob has ruled building out entirely"*).

**Decision — widen it, narrowly and by design (Rob's call).** The assistant may assemble a **reviewable what-if**
from the reader's **own stated figures**, and — behind a separate off-by-default flag — edit the base **only on an
explicit request**. The old rule's protections all still hold, which is what makes this an application of the
doctrine, not a betrayal of it:
- The model is never the **source of a figure** — every value comes from what the reader said (guard **C1**, the
  inverse of G1). It maps "bump my retirement to 68" onto a field; it supplies no number of its own.
- The model never **computes an outcome** — it produces **inputs**; the deterministic engine + Monte Carlo forecast
  the result, exactly as for a hand-built what-if.
- Writes are **reviewable + reversible** — a what-if is a **delta-child** (a throwaway draft off the base), shown as a
  `WhatIfChanges` diff and confirmed before persist, deletable after; the base is untouched by default.
- **Still ruled out:** research / planning / code (Claude Code's job, and worse on a 14B) and any from-scratch
  scenario. This is **data-entry assistance**, categorically different from authoring content or predicting.

**Scope + phasing (Rob, 2026-07-04): full widen.** Conversational what-ifs (Phase 1 value edits; Phase 2 add/remove
rows), then **gated base editing** (Phase 3, `config('assistant.can_edit_base')` **default off**, with orphan +
stale-run surfacing). Mostly a new *producer* of the existing delta shape — reuses `BuilderStateDelta`, `QuickWhatIf`'s
`{name, overrides}`, `QuickWhatIfController` persistence, `WhatIfChanges` for the confirm. New pieces: a **closed
edit-target menu** (C2, so the model selects a real path, never fabricates one), a `BacklogCapture`-shaped extraction,
and the input-grounding guard **C1**. Full spec, guardrails (C1–C5) and risks in
**[docs/PLAN-assistant-scenario-editing.md](build/PLAN-assistant-scenario-editing.md)**.

**Status:** spec approved, **not built**. When built, update the "never builds" wording in RESEARCH-local-assistant.md
(§0/§3/A4) + the `config/assistant.php` header to the narrowed form. Guidance-only phrasing (G2) still wraps every
conversational turn; local-only (LA-3) is unchanged.

## 2026-07-04 — NI category tidied to derive-default-plus-override; IHT-not-wired found + specced
**Context:** Building a what-if, Rob saw the per-person **National Insurance category** field and asked whether
it's expected to change over time and whether we can auto-apply the correct one. Investigating that surfaced a
second, bigger finding about IHT.

**Decision — NI category is now derive-default-plus-override (built):** for a *household* forecast only the
**employee's** primary NI touches their money, so the ~26 category letters collapse to four employee outcomes,
and the two that vary over time are already handled — **over State Pension age → nil** and **no salary → nil**
are auto (the calculator returns £0 at SPA; NI is charged only on an employment salary). So the field only
matters for **working years under SPA**, and the only non-derivable exceptions are the *married-woman's/widow's
reduced rate* (a pre-1977 election) and *deferred* (a second job). The builder now shows the field **only when
the person is `Employed`** (live), defaults to **Standard**, offers just **Standard / Reduced rate / Deferred**
in plain English, and **drops the misleading manual "over State Pension age" and "not liable" options** (both
auto). The hint states NI stops at SPA and never touches pension income. Behaviour-preserving (engine unchanged;
`niCategory` still stored, default standard).

**Finding — `ihtModelled` is collected but unconsumed (a silent drop).** The IHT toggle is stored, shown in
what-if diffs + GDPR, but **no forecast or engine code reads it**; `InheritanceTaxCalculator` (complete +
tested) is called only by its own test and the Filament tax-audit page. **IHT is never computed in a forecast.**
This is the collected-but-unconsumed class the reconciliation rule exists to catch.

**Decision — spec, don't build (Rob's call): relationship status only matters once IHT is wired.** Married vs
cohabiting materially changes IHT (spousal exemption + transferable nil-rate band) and survivor treatment, but
the engine implicitly assumes married (`settleEstates`; the calculator's `nilRateBandMultiplier=2` path) and
there's no field to say otherwise — so a cohabiting couple is over-relieved. Adding a relationship field *now*
would just create another inert input; it earns its keep only when IHT is actually computed. So the unit of work
is **"wire IHT into the forecast (consume the toggle), relationship-status aware"** — fully specced for a fresh
agent in **[docs/PLAN-iht-and-relationship-status.md](build/PLAN-iht-and-relationship-status.md)** (the
calculator is done; only wiring + an `EstateValuer` + a `relationshipStatus` input + a results panel remain).
Not built this session.

## 2026-07-04 — Relax the guidance-only partition for personal/family use (keep it re-enforceable + flagged)
**Context:** The tool is, for now, purely for Rob's own family scenario (internal use, not a public release). The
build-time banned-phrasing partition (`BannedPhrasingTest` + `OutputPhrasing`) was getting in the way: it is a static
lint that fails if any directive phrasing ("you should", "the best option") appears anywhere outside the walled-off
`Interpretation` layer, so writing direct advice for real use meant routing everything through the wall. Runtime advice
mode was already on (`compliance.personal_use` default true; the `interpret` gate open); the friction was the test +
the partition discipline. Supersedes the "suite runs in public posture so the guard stays tested" stance of
DECISIONS 2026-06-30 — for the private phase only.

**Decision — relax by default, do not delete; keep it reversible and flagged:**
- The suite now runs in **personal-use advice mode** (`phpunit.xml` sets `COMPLIANCE_PERSONAL_USE=true`). The partition
  test is **posture-aware**: in advice mode it **skips** (reporting the advice-spot count) instead of failing; in the
  public posture (`personal_use=false`) it **fully enforces** the partition as before. Nothing is removed — `OutputPhrasing`,
  `Interpretation`, the `interpret` gate and the walled-off view all stay.
- The neutral-zone definition moved to one home, `App\Compliance\NeutralZoneScanner`, shared by the test and a new
  **`compliance:advice-audit`** command that lists every advice-vs-guidance spot on demand (a report; `--strict` exits
  non-zero for a pre-release CI gate). This is the standing "flag it for later" inventory the user asked for.
- **Re-enforcement path (before any public release):** set `COMPLIANCE_PERSONAL_USE=false` and the partition test turns
  every advice spot back into a listed failure to fix (move behind the `interpret` gate, or reword). The regulatory line
  is unchanged — only its *default enforcement while private* is relaxed.

**Why:** personal recommendations on pensions/drawdown are FCA-regulated activity, so the guard must survive intact for a
possible future public release — but it should not obstruct the owner's own family use now. Skipping (visible) beats
deleting (silent), and an on-demand audit + a single flag flip keep the crossings findable and the posture one toggle
away. See CLAUDE.md (regulatory-line bullet) + `config/compliance.php`.
**Status:** active (private phase). Reverts to full enforcement when `personal_use` is set false.

## 2026-07-04 — Wealth-over-time charts show the funding gap below £0; live Compare MC progress
**Context:** Rob's browser review flagged that the "usable wealth over time" charts bottom out at £0, and that
"Re-run all" on Compare runs the Monte Carlo with no progress indicator.

**Decision — a distinct net-position series, not a redefinition of usable wealth:** the engine floors wealth at £0 by
construction (you cannot draw cash you do not have; running out is recorded as £0 wealth + a separate `unmetSpend`), so
removing the axis floor alone shows nothing. Added `SimulationResult::netPositionFanChart` = **usable − Σ unmet spend**,
a new derived quantity (one definition, one home — `usableFanChart`/`usableWealthPercentiles` stay literal usable
wealth). Rob chose **one continuous line** (net position dips below £0 to show the *cumulative shortfall*) on the **full
target-spend** basis (reuses the existing `unmetSpend`; no new engine figure). Net = usable while solvent; nullable/
empty for runs persisted before it (fall back to the £0-floored usable fan). The fan + Compare **burndown** plot it and
show it in their data tables; `yaxis.min = 0` is dropped only when a series goes negative; `charts.js gbpAxis` is
sign-aware (`-£80k`). **The cashflow-ladder table deliberately keeps usable wealth ≥ £0** (plus its separate
`shortfall` column) — a "what you hold" table showing −£80k of cash would be false; the graphs are the on-track-over-
time view where negative reads naturally as the gap. Reconciliation preserved and tested (burndown = ladder usable − Σ
unmet; equal while solvent).

**Follow-on (same day) — shade the below-£0 region light red:** Rob asked to highlight the shortfall territory the
net-position series exposed. Added a light-red `annotations.yaxis` band from £0 down, on **all three** over-time charts
(the results fan + strategy-comparison and the Compare burndown), via one shared `ResultPresenter::belowZeroBand()`.
**Two non-obvious calls a future agent should not undo:** (a) the band's `y2` is a fixed **sentinel floor** (−£1bn), not
a computed axis minimum — ApexCharts clamps a y-axis region to the plot and clips it to the grid mask, so the sentinel
just fills to the chart bottom whatever the auto scale is (verified against the ApexCharts 4.7 source); no need to know
the axis min server-side, and it does not drag the scale down. (b) the fan chart's milestone verticals are **merged**
into `annotations.xaxis` at the `ScenarioResults` call site (`…['annotations']['xaxis'] = …`), not assigned to the whole
`annotations` key — the old `= ['xaxis' => …]` would clobber the band. Drawn only when a series dips negative (solvent
charts show no empty band); guarded by unit tests.

**Decision — live batch progress on Compare (no silent long-runs):** "Re-run all" queued the runs and showed a one-shot
static note. `ScenarioCompare` now tracks the batch's run IDs (public prop, re-scoped to the owner) and polls a progress
panel — aggregate bar + "X of N done", per-plan status/% bars, **Cancel all**, the results-page **awaiting-worker** hint
— until every run is terminal, then stops polling. A family run already in flight (launched from a plan's own page, or
after a reload) is **restored on mount**. Reads the existing `SimulationRun` status/`progress_pct` — no data-model
change.

**Gotcha fixed + guarded — Blade `word@if` gluing:** a control directive glued to a word char (`finished@if`,
`payments@if`) is **not compiled** — it leaks literal `@if`/`@endif` to the page and renders the conditional body
unconditionally (`word@endif` sometimes compiles, desyncing the block and leaking a stray `@endif`). Fixed the new panel
(`@elseif`) and a **pre-existing** PLSA-footnote instance on the results page (ternary echo — it had been showing raw
Blade tokens). Added `BladeDirectivesCompileTest` (compiles every view, fails on any leaked control directive) so the
whole class can't recur; Livewire panel tests gained `assertDontSee('@endif')`. See memory `blade-directive-word-glue-gotcha`.

## 2026-07-03 — Local-model assistant Phase 3 built: idea capture, the model's only write
**Context:** The last specced assistant phase — the model's ONLY write: capturing a reader's "we should look at X"
idea to a work queue for a human to promote later. It never builds, edits code, or offers to.

**Decision + how (app-layer only; engine untouched):**
- **Append-only, attributed, reversible store:** an `assistant_backlog_items` table (user_id, kind, title, note,
  source, timestamps) — NOT a curated doc (never PLAN/DECISIONS/HANDOVER). Ideas are about the tool, not the
  household's finances, so nothing is encrypted; a deleted user's items cascade away.
- **The model structures, never builds:** `App\Assistant\BacklogCapture` turns free text into {kind, title, note}
  via the local model (research|feature|task). Safe by construction — an unreachable model or an unusable reply
  falls back to storing the raw idea as a Task, so an idea is never lost (no silent failure); injected `ChatClient`,
  unit-tested with a fake.
- **UI:** the panel gains an **"Ideas" tab** (alongside "Ask") — a capture box + a review list of the reader's queued
  ideas, each deletable. On-screen confirmation on capture (visible, never silent). No confirm step (reversible, per
  the spec).
- **Promotion stays a human act:** an **`assistant:backlog`** command lists the queue for a human/Claude Code to
  promote into docs/PLAN.md and clear. The model never touches curated docs.

**Evidence:** 9 new tests (BacklogCapture structuring + fallbacks; Livewire capture/list/delete/owner-scoping/inert);
verified end-to-end vs real `qwen3:14b` — it classified "equity release" → feature, "Scottish tax bands" → research,
"reclaim wording confusing" → task, each into clean parseable JSON. Suite green. **The assistant's three specced
phases (1 explainer, 2 methodology doc-RAG, 3 capture) are now all built.** See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — /methodology page + doc: engine-computation methodology, one source for page + assistant
**Context:** Phase 2's doc-RAG deliberately left broad "how does the engine compute emergency tax / the Monte Carlo /
CGT" coverage to a purpose-written /methodology page (the curated corpus was only ASSUMPTIONS/MORTALITY/stress-test —
LA-9). Rob picked building that page next.

**Decision + how:**
- **One source, two homes:** `docs/METHODOLOGY.md` is BOTH the public **/methodology page** (rendered by a
  `MethodologyController` + `Str::markdown`, cached by the file's mtime, scoped `.methodology-prose` CSS — no
  typography plugin) AND part of the assistant's methodology corpus (`config('assistant.methodology_docs')`). The page
  a reader opens and the passage the assistant retrieves are the same words.
- **Accuracy first (Rob's overriding priority):** the engine-computation content was written from a **code-grounded
  survey of the actual engine**, not the planning prose — so it is true to the implementation, including the honest
  caveats (IHT and the care means-test are standalone calculators, NOT in the year-by-year loop; the SDLT
  additional-property surcharge and the Pension-Credit carer addition exist but aren't wired into the live path; the
  standalone lump-sum panel omits State-Pension/DB other income the full forecast includes). A thorough **"what we
  don't model"** section lists the flagged v1 limits.
- **Public + education-only:** the route is public (no user data), linked from the footer; the doc closes with the
  guidance-not-advice posture. It complements ASSUMPTIONS.md (economic inputs) + MORTALITY.md (life tables), which it
  links rather than duplicates.

**Evidence:** page renders (test + browser-fetched); corpus re-indexed (METHODOLOGY.md → 21 chunks, 43 total);
**verified end-to-end vs real `qwen3:14b`** — "how does emergency tax work / the Monte Carlo / CGT on a let home?" now
answer accurately and grounded from METHODOLOGY.md (before, they surfaced planning noise or nothing). Suite green.

## 2026-07-03 — Assistant on the Compare page: a multi-plan context so it can answer comparison questions
**Context:** Rob asked to show the assistant on Compare too — but there it must answer COMPARISON questions ("which
plan leaves the most / runs short?"), which the single-scenario `ScenarioContext` cannot.

**Decision + how (app-layer only; engine untouched):**
- **A shared `AssistantContext` contract** (`promptBlock()`, `includesMonteCarlo()`, `systemIntro()`) that both
  `ScenarioContext` and the new **`ComparisonContext`** implement. `SystemPrompt` + `AssistantService` now work against
  the interface, so a new kind of context drops in without touching the orchestration or the two guardrails (G1/G2).
- **`ComparisonContext`** lays out each compared plan (base + ready what-ifs) with its deterministic headline figures
  (money lasts / runs short + year, essentials met, full spend met, spendable + total wealth left, and how it differs
  from the base), built from the SAME per-variant deterministic forecasts the Compare table renders (`deterministicVariants`,
  provenance). Its `systemIntro()` tells the model to name the plan(s) and **not do arithmetic across plans** (state each
  plan's own figure, not the difference), so a compared figure is always grounded (G1), never an invented delta.
- **`ScenarioAssistant` gains a `compare` flag** (`<livewire:scenario-assistant :scenario="$base" :compare="true" />` on
  the Compare page): it builds the comparison context from the base's family and offers comparison starter questions
  ("which plan leaves the most?", "which run short?"). **Deterministic only** (matching the Compare table); per-plan
  Monte Carlo is a possible later add.

**Evidence:** unit-tested (`ComparisonContextTest` — each plan reaches the block, comparison figures ground) + Livewire
(compare mode offers comparison starters); verified end-to-end vs real `qwen3:14b` — it correctly named the plan leaving
the most and which plans last vs run short, every figure grounded. See docs/RESEARCH-local-assistant.md.

## 2026-07-03 — Assistant UI: a docked side panel (not a chat bubble), Clear, adviser-style starter questions
**Context:** Rob's UI asks on the built assistant — make it a fixed/docked sidebar rather than a floating "live chat"
bubble; add a **Clear** to wipe history; and offer **more pre-populated questions** in the "what would I ask a
financial adviser" vein.

**Decision + how (view + `ScenarioAssistant` only; engine + service untouched):**
- **Docked side panel.** The launcher is now an **edge-anchored tab** (rounded-left, attached to the right edge), and
  the open panel is a **full-height right-docked sidebar** (`fixed inset-y-0 right-0`, `border-l`), not a bottom-right
  floating card. Still collapsible and inert unless `config('assistant.enabled')`; CSP-safe (Livewire state only).
- **The page makes room instead of being overlapped.** The open `<section>` carries `data-assistant-open`, and a
  CSS-only `body:has([data-assistant-open])` rule pads the shell right by the panel width (`24rem`) on **lg+**, so the
  fixed sidebar sits beside the content, not over it (no JS, no component/layout state-sharing; narrow screens keep the
  overlay since the panel is near-full-width there). The results-page left "on this page" nav column was also trimmed
  (`13rem`→`11rem`, `gap-8`→`gap-6`) to give the content back the width the sidebar takes.
- **Clear.** `clear()` wipes the local transcript + input back to the starter state (nothing is persisted
  server-side); the button shows only once there is a conversation.
- **Grouped, clickable starter questions** (`suggestions()`; a click asks directly via `ask($preset)`): "Your plan";
  **"Risks worth checking (and worth raising with Pension Wise or an adviser)"** — the plain-English form of the five
  **COBS 9.4.10G** drawdown risk warnings (run-out likelihood, returns below illustration, longevity, care shock,
  inflation), the same "take to Pension Wise / an adviser" material as the adviser-pack backlog
  (docs/RESEARCH-delta-2026-07-02 §3); "Tax and the home" (the home-sale starter shown **only for a sell strategy** —
  a stay-put plan pockets nothing); and "How the forecast is worked out" (methodology, Phase 2). Every question is
  neutral (passes `BannedPhrasingTest`, which scans the component + view) and answerable from the grounded context.
  Livewire-tested (starter groups render, Clear wipes, sale starter gated on variant).

## 2026-07-03 — Local-model assistant Phase 2 built: methodology doc-RAG, corpus CURATED not whole-folder
**Context:** Phase 2 of the assistant (see the entries below) — "how does it model X?" methodology questions via
local embeddings (`nomic-embed-text`) over `docs/`, kept distinct from the scenario-figure questions Phase 1 answers.

**Decision + how:** App-layer only (engine untouched), same discipline as Phase 1 — **prompt-stuff, don't route.**
- New `App\Assistant\`: `EmbeddingClient` (+ `OllamaEmbeddingClient`, local-only, fails loudly via
  `AssistantUnavailable`), `DocChunk` / `DocChunker` (heading-anchored chunks, hard-word-capped so none exceeds the
  embed model's context), `DocIndex` (hand-rolled cosine search — no vector DB, the integer-pence spirit),
  `MethodologyRetriever`. An `assistant:index-docs` command builds a gitignored JSON index under
  `storage/app/private/assistant/` (a derived build artifact; missing index degrades gracefully to no methodology).
- **No fragile 14B intent-routing.** Every turn embeds the question, cosine-searches the index, and attaches the top
  chunks above a threshold under a labelled METHODOLOGY section — threaded into `AssistantService::answer()` by the
  Livewire component (the service stays pure/unit-testable, no runtime). A scenario question matches nothing → no doc
  noise. Grounding (G1) widens to context+methodology, so a real methodology figure it cites is groundable while an
  invented one is still refused; the prompt forbids taking the reader's OWN figure from METHODOLOGY (LA-6/LA-8).
- **The corpus is CURATED, not the whole folder — the load-bearing call, forced by live verification.** Indexing all
  of `docs/` made the assistant surface internal PLANNING/build-record prose ("DrawdownStrategy enum, both shipped")
  as if it were methodology, and nomic's compressed cosines (~0.64–0.71 for *everything*) gave a global threshold no
  discrimination between methodology and scenario questions. Restricting the index to the genuinely
  methodology-bearing, sourced docs (`config('assistant.methodology_docs')` = ASSUMPTIONS.md, MORTALITY.md,
  RESEARCH-stress-test-and-official-sources.md) fixed both: methodology questions now score 0.70–0.76 and hit the
  right source doc; scenario questions sit at 0.58–0.63 and attach nothing at the 0.66 threshold. Broader "how does
  the engine compute emergency tax / the Monte Carlo" coverage is **DEFERRED to a purpose-written /methodology page**
  (already on the backlog) — that is the right corpus for it, not the internal planning docs.

**Evidence:** 15 new unit tests (chunker, cosine index, retriever graceful-degradation, methodology-widens-grounding),
suite green; verified end-to-end against real `qwen3:14b` + `nomic-embed-text` — a mortality-methodology question is
answered grounded and sourced (ONS cohort q(x), ages 50–100, years 2025–2074, the JSON resource), a scenario question
attaches no methodology and answers from the scenario context. **Phase 3 (backlog capture) remains specced.**
See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — Assistant context: add the home-sale waterfall (Phase-1 sources now comprehensive)
**Context/decision:** Added the **home-sale waterfall** as the next context source, same pattern as the tax shock: a
fourth optional already-formatted array on `ScenarioContext` (`?array $saleExplainer`), reused from
`ResultPresenter::saleExplainer()` (so the figures match the sale-waterfall panel) and resolved by the Livewire
component exactly as the results page assembles it. For a sell strategy it exposes what the household actually pockets
— sale price less mortgage, selling costs and CGT = net proceeds — then the invest-and-rent or buy-cheaper split
(surplus, or the shortfall when the buy costs more than the proceeds cover). Null (no facts) for a stay-put scenario.
Verified end-to-end vs real `qwen3:14b`. **With this, the Phase-1 context sources are comprehensive** — central
projection, year-by-year ladder, Monte Carlo, lump-sum tax shock, sale waterfall — all grounded, all reusing the
panels' own figures. The next meaningful work is phase-level: Phase 2 (methodology doc-RAG) and Phase 3 (backlog
capture). See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — Assistant context: add Monte Carlo probabilities and the lump-sum tax shock
**Context:** Continuing to widen what the assistant can interrogate (Rob: "a big part of the point is to interrogate
data not visible in the UI"). The deterministic snapshot couldn't answer "what's my chance of running out?" or "how
much tax on my lump sum?" — the two figures the tool most exists to make visible (Monte Carlo risk; the flagship
lump-sum tax shock, PRD goal #1).

**Decision + how:**
1. **Monte Carlo probabilities/ranges** join the context when a completed run exists: chance full/essential spending
   is funded for life, chance of running out (+ typical depletion year), the terminal spendable-wealth spread
   (p10/p50/p90), longevity (last-survivor age range, P(reach 95/100)) and the care-cost tail. Built from the run's
   `SimulationResult` via the SAME `ResultPresenter` helpers the panels use (provenance), every label prefixed
   **"Monte Carlo —"** and the system prompt told to use those for likelihood/range questions and never present a
   central figure as a probability. **No completed run → the context says so explicitly** (honest, not a silent gap).
2. **The lump-sum tax shock** joins when a lump sum is planned: 25% tax-free, taxable part, marginal tax, the Month-1
   emergency over-deduction + which reclaim form (P55/P50Z/P53Z), net received, MPAA — reused from the already-formatted
   `App\Forecast\LumpSumTaxShock::assess()` array (same figures as the tax-shock panel).
3. Both are **optional inputs to `ScenarioContext`** (`?SimulationResult`, `?array $taxShock`), so it stays pure and
   unit-testable from hand-built inputs; the Livewire component resolves them (`latestCompletedRun()` for the run,
   `LumpSumTaxShock` for the shock). App-layer only; engine untouched.

Both verified end-to-end against real `qwen3:14b` (18% run-out / 71% funded / the wealth range; £17,432 tax + £11,568
reclaim via P55). Known limit unchanged: the model won't compute across years/figures (an ungrounded aggregate is
refused by G1). See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — Assistant refinements: carry the full year-by-year ladder; a side panel, not a centre panel
**Context:** Testing Phase 1, Rob asked "how much are my essentials in 5 years?" and it refused — the context held
only the *headline* facts, not per-year figures. His framing: **a big part of the point is to interrogate the data
for information not immediately visible in the UI.** He also asked to move the panel to the side.

**Decision + how:**
1. **`ScenarioContext` now carries the full year-by-year cashflow ladder** — every projected year's essentials /
   discretionary / total spend, income by source, tax, investment growth, shortfall and spendable/total wealth —
   built from the **reconciled `ResultPresenter::ladder()` rows** (so the assistant's per-year figures ARE the
   ladder panel's; provenance), **one line per year, every figure inline-labelled**. Inline labels (not a bare
   table) keep the model stating the right number for the right thing — the right-number-wrong-meaning risk
   (gotcha LA-8) bites hardest on exactly this drill-down. All figures land in the grounding allow-list, so any
   per-year question is answerable. **Limit (by design):** the model still won't compute *across* years (a summed
   aggregate is ungrounded → G1 refuses it); pre-computed aggregates are a clean future add. Verified against real
   `qwen3:14b` ("essentials in 5 years" → the right year's figure; maps "5 years" to a calendar year).
2. **The panel is a fixed, collapsible SIDE panel** (bottom-right; a launcher when closed), out of the report's
   content flow — not a centre panel. Toggle is pure Livewire state (`$open`/`toggle()`), so it stays CSP-safe
   (no inline JS). Still inert unless `config('assistant.enabled')`.

Both are Phase-1 refinements (see the entry below); the engine is untouched. See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — Local-model assistant Phase 1 built: prompt-stuff the figure snapshot, not tool-calling
**Context:** Building Phase 1 (the grounded scenario-explainer) of the assistant specced in the entry below.
The spec sketched **tool-calling** into `ResultPresenter`/the engine as the grounding mechanism.

**Decision + how:** Build Phase 1 app-layer only (engine untouched) and, in place of tool-calling, **prompt-stuff a
bounded, labelled figure snapshot** (`App\Assistant\ScenarioContext`, built from the deterministic `ForecastResult`)
into the system prompt. Rationale: a 14B local model does tool-calling less reliably (risk A3), the snapshot is
small and bounded, and — the key property — the snapshot is the SINGLE source of both the model's context AND the
grounding allow-list, so "the figures it is given" and "the figures it may state" are one thing. Tool-calling stays
the fallback only if the snapshot ever grows too large to inline. Guardrails as specced: **G1** `FigureGrounding`
refuses any £/year/% figure not present in the snapshot or the reader's question (strict — no re-rounding — because
the tool promises penny-accuracy); **G2** reuses `App\Compliance\OutputPhrasing` (recommendation phrasing blocked in
guidance-only mode, a direct steer allowed in advice mode via the existing `interpret` gate). `AssistantService`
gives one corrective retry then **refuses rather than show a bad answer**. The local client (`OllamaChatClient`) sits
behind a `ChatClient` interface so the whole thing is unit-testable with a fake — no running model needed — and it
**fails loudly** (`AssistantUnavailable`). Inert by default (`config('assistant.enabled')`).

**Evidence:** 19 unit tests incl. the headline **"never surfaces an invented figure"** invariant; verified
end-to-end against the real `qwen3:14b` (a grounded answer passes; it declined to invent a 2070 house value). v1 is
a synchronous call with a "Thinking…" state; streaming/queueing, richer context and a side-nav entry are flagged
fast-follows. **Phases 2–3 remain specced.** See docs/RESEARCH-local-assistant.md §6.

## 2026-07-03 — In-app local-model assistant: explain + capture only, it never builds
**Context:** Rob wants an in-page **"chatbot"** on a **local AI model** to (1) answer questions about the
loaded scenario + the project, (2) queue research requests, and (3) be the interface for building the
feature/task list. Investigated feasibility ([docs/RESEARCH-local-assistant.md](research/RESEARCH-local-assistant.md)).
Two findings de-risk it: the project **already settled a local-AI stance** in the 2026-06-28 document-import
investigation (*"wrangling and explaining, not predicting"*; never the source of a number; local-only;
walled off — [docs/RESEARCH-document-import.md](research/RESEARCH-document-import.md) §2), and the **runtime is
already present** (Ollama on `localhost:11434` with tool-calling models + `nomic-embed-text`). The honest
reframe: only the **scenario-explainer** has unique in-app value; "queue research / build the task list" is
thin NL→structured-item capture, where a 14B local model doing real research/planning would be strictly
worse than, and duplicative of, Rob + Claude Code.

**Decision:**
1. **Build it, scoped tight and phased** — (1) grounded scenario-explainer, (2) methodology doc-RAG, (3)
   research/feature capture. App-layer only; the engine stays framework-free.
2. **The model may only append to the dev backlog / work queue. It never builds anything, edits code, or
   offers to** (Rob's explicit constraint). "Autonomous writes" = exactly one capability (`queueBacklogItem`,
   no confirm step) to a **dedicated, attributed, append-only** store — never an in-line edit of curated
   PLAN/DECISIONS/HANDOVER prose. Promotion into the real backlog stays a human/Claude Code act.
3. **Local-only**, because the scenario is real financial PII (same rule as document-import DI-7).
4. **The model is never the source of a number.** Scenario/figure questions use **tool-calls into the
   engine/`ResultPresenter`** (never embeddings); methodology questions use doc-RAG over `docs/`. Two
   guardrails wrap every response: **G1** figure-grounding (a verification pass rejects any number not in
   the tool output) and **G2** the runtime phrasing partition (`App\Compliance\OutputPhrasing` +
   the `interpret` gate). Reuses the existing `Interpretation` wall rather than adding a new one.

**Consequence / caveat:** a runtime LLM cannot be build-time-linted, so G2 is a **flagged public-release
blocker** alongside `config('compliance.personal_use')=false`, the JST-dataset swap and CSP nonces. Safe
in personal-use mode. Specced, not yet built.

## 2026-07-03 — "Check these figures & get help" panel surfaces sources + contacts in the UI
**Decision:** The researched backup for the mortgage (RIO) and CGT figures — authoritative sources + real contact
details — is now surfaced **in the app**, not just the chat/`.local` file. A reusable `<x-sources-and-contacts>`
component (Pensions & money always; **Later-life mortgages** and **Capital gains tax** shown **contextually** — only
when a plan involves a mortgage, or would sell an ever-let home) renders on the **results** page (a `sec-sources`
section in the on-this-page nav) and the **Compare** page, and a compact version is in the **PDF** export. It carries live contacts: Pension Wise (0800 138 3944), MoneyHelper (0800 138 7777), the Equity Release
Council later-life-adviser directory, GOV.UK CGT + report-and-pay (60-day rule), HMRC CGT (0300 200 3300), TaxAid
(0345 120 3779, low income), a Chartered Tax Adviser, and the FCA register. **Wording is neutral signposting only**
(it points to help + says figures are estimates until a professional confirms them), so it passes the
`BannedPhrasingTest` guidance-only lint — consistent with the existing `<x-signpost>` / disclaimer posture. Contact
details were verified against gov.uk / MoneyHelper / TaxAid / CIOT / Equity Release Council (2026-07-03). Suite green
(595).

## 2026-07-03 — Buy-cheaper can be funded by a mortgage on the shortfall (RIO), not just cash
**Context:** Modelling the V2 couple, "Sell & buy cheaper" looked strongest but was flagged **unaffordable** (a £200k
home needs ~£97k more than their thin equity frees). The engine only modelled an **outright** buy — when the buy
exceeded the proceeds it floored the surplus to £0 and handed them the home for free, inflating that plan's wealth,
and the advice layer still ranked it top. Real-world research: an older couple could bridge the gap with a
**retirement interest-only (RIO)** mortgage, but survivor-affordability (the lower earner must carry it alone —
here YCC on ~£9.8k after FRC dies) caps it well below £97k; a ~£150–185k purchase with a modest RIO is the realistic
ceiling. Repayment mortgages are worse (higher payments fail the survivor test sooner).

**Decision:** Model a **mortgaged buy**. `HousingAction` gains `buyMortgageRate` (a RIO rate); when set and the
purchase (buy + SDLT + moving) exceeds the net proceeds, the shortfall is **borrowed** rather than floored:
`HousingPurchase` gains `mortgage`, the new home is `Mortgaged` with that balance, and its **interest-only payment
(mortgage × rate)** is charged for life via a new `ExpenseProfile::withMortgageCosts` (added back after
`withoutPropertyCosts` strips the old home's). Null rate = the old cash-only behaviour. Reconciliation generalises to
**netProceeds + mortgage == buyPrice + SDLT + moving + surplus**. The interest-only balance stays owing (repaid from
the estate on sale/death) — the existing "mortgage not netted from displayed wealth" v1 caveat applies, so a
mortgaged buy's *total* wealth is gross of the RIO still owing.

**Wiring:** builder input `housing.buyMortgageRate` (validation + `blankHousing` default + assembler); the Compare
affordability note is now mortgage-aware — a funded gap reads "Funded by a £X interest-only mortgage (~£Y/yr)"
(neutral) instead of the amber "not affordable" warning (which stays for a cash-only unaffordable buy). Tests pin the
reconciliation, the new home carrying the loan + interest, and the builder round-trip. **V2:** the "Sell & buy
cheaper" what-if now uses a realistic **£165k** home funded by the **£107.6k proceeds + a £61.7k RIO at 6% (£3,703/yr
interest)** — and it *lasts* (usable £154.6k at 2049), a genuinely affordable option. Suite green (595).

## 2026-07-03 — Mortgage-maturity is a user-modelable input; "Stay put" never inherits a forced sale
**Context:** Browser-verifying the forced-sale build, the one-click "Compare buy vs rent" produced a "Stay put"
plan that silently sold the home at the redemption year (it was identical to "Sell & rent"), because
`BuyVsRentCompare` overrode only `variant` and the plan inherited the base property's `forced_sale`. Before the
forced sale was modelled this was masked (forced_sale did nothing, so stay-put accidentally kept the home). Asked
Rob how a stay-put plan should behave when the mortgage is force-called; his decision: **the different treatments
(refinance / repay-from-capital / forced sale) should each be scenarios the user can build and compare**, not a
behaviour baked in.

**Decision + how:**
1. **`mortgageMaturityAction` + `mortgageRedemptionYear` are now editable builder inputs** (property step: a year
   field + a Refinance / Repay from savings / Sell the home select). They were real `Property` DTO fields the
   assembler already read, but had **no UI at all**, so the treatment could not be modelled by the user — the gap
   behind Rob's answer. Validation added; `blankProperty()` defaults them (refinance, blank year); `loadState()`
   backfills them for pre-input scenarios so the select binds (mirrors the cgtHistory backfill).
2. **A generated "Stay put" plan keeps the home**, so `BuyVsRentCompare` now resets a `forced_sale` maturity action
   to `refinance` on the stay-put child (a neutral keep-the-home baseline; the user can then model repay-from-capital
   explicitly). Mirrors the existing `QuickWhatIf::letOutAndRent` precedent (which resets to refinance when it keeps
   the flat). The reset is scoped to stay-put — buy replaces the home anyway, rent sells.

**Notes:** `refinance` (not `repay_from_capital`) was chosen for the auto stay-put reset as the least-alarming
keep-home baseline (repay-from-capital would force a large one-off that usually shows an immediate shortfall); the
user drives the harder cases via the new input. Adding a non-empty-default builder field re-surfaced the
new-field-spurious-delta gotcha (`BuilderStateDelta::diff` records a child key the base lacks): fixed by adding the
keys to `BuilderStateFixture::full()`, matching the `ownershipShare` precedent. `ScenarioBuilderTest` pins the
round-trip; `BuyVsRentTest` pins the stay-put reset (+ that buy stays a variant-only delta). **Migration note:** a
`forced_sale` base's *existing* stay-put child (created before this) still inherits the forced sale — regenerate it.
Suite green (592).

## 2026-07-03 — In-place forced sale built (last Lane-B item; the "keep the home for ever" bug closed)
**Decision:** Built the in-place forced sale (`docs/PLAN-in-place-forced-sale.md`, Rob's decisions resolved
2026-07-01). `MortgageMaturityAction::ForcedSale` was a projector no-op, so a stay-put projection with a forced
redemption **kept the home for ever** — the exact plausible-but-wrong outcome the project guards against. Now the
projector sells the home **in place, at the redemption year**, mid-projection.

**How:** an additive event in `PathProjector::projectYear` (after the RepayFromCapital block) fires once at
`mortgageRedemptionYear` when the action is `ForcedSale`: it sells at the **grown whole-property value**, frees the
net proceeds into the first living person's GIA (cost basis = proceeds, no latent gain), clears the home + debt
(`property`/`propertyWhole`/`mortgageOutstanding` → 0; `homeSold`/`mortgageRepaid` → true), and from that year stops
the mortgage payment + property/running costs and charges the entered rent. Two new state keys: `homeSold` and
`propertyWhole` (the un-scaled whole value, grown in lockstep with the share value, so CGT reads the whole gain).

**One definition (CLAUDE.md data-integrity rule):** the sale maths was extracted to
`HousingProceeds::compute(salePriceWhole, mortgageWhole, components, cgtHistory, ownershipShare, config)`, the single
reconciled decomposition. `HousingComparison::saleProceeds` (year-0) now delegates to it, and the projector's
redemption-year sale runs the same code on the grown value — the two can never drift. The `DEFAULT_SELLING_COST_RATE_BP`
(2%) moved onto `HousingProceeds`.

**Rent + selling-cost basis via settings:** the projector has no `HousingAction`, so the post-sale rent
(`annualRent`, `rentInflationReal`) and the selling-cost components (new `ForecastSettings::$sellingCosts`) ride on
`ForecastSettings`, populated by `ScenarioForecaster::settings()` **only for a ForcedSale scenario** (every other run
leaves rent null, so an owner is never charged rent). The rent leg is gated on `! ownsHome`, so it starts at the
sale year for a forced sale and stays as-is for a year-0 rent variant. Decisions honoured: rent is the user's input
(no invented default; £0 + a results note when none is entered); this is a what-if, not base behaviour (the base
stays "find capital and stay"); CGT on the grown, sale-year value via the `CgtHistory`; freed proceeds are
investable GIA. The Pension Credit erosion falls out for free (the proceeds are now liquid, already assessed as
capital), so no `homeSold` capital branch was needed.

**v1 limits (flagged in code + the spec):** with a partial `ownershipShare` the sale reconciles penny-exact only for
whole ownership (grown-share vs `share × grown-whole` can differ sub-penny); Pension Credit sees the freed capital
from the year **after** the sale (the benefit is computed before the sale event within the year). `ForcedSaleTest`
pins wealth conserved across the sale to the penny (zero-growth fixture), costs-stop-rent-starts, Pension Credit
erosion, let-home CGT vs lived-in-£0, and the no-redemption-year no-op; a `ScenarioForecasterTest` completeness test
pins the rent + selling costs reaching the settings. The `ForcedSale` enum doc + results input note were updated
(now "the home is sold that year", not "weigh alternatives on Compare"). Suite green (590).

## 2026-07-03 — Property ownership share now consumed (`Property::ownershipShare`; last silent-drop closed)
**Decision:** Wired `Property::ownershipShare`, the fifth and last silent-drop. Rob asked me to **research the
correct real-world convention** (not guess), since it touches the flagship buy-vs-rent path. Researched:
**tenants in common** each hold a distinct **beneficial share**; HMRC apportions both the **gain and the sale
proceeds** by that share, and each co-owner is taxed on their share with their own annual exempt amount (the
joint-owner split the engine already does). Sources: gov.uk/HMRC guidance on jointly-owned property + CGT (the
beneficial-interest / tenancy-in-common treatment).

**Convention chosen (documented in the builder + DTO):** the user enters the **whole property's** figures
(value, mortgage, running costs, purchase price); `ownershipShare` (null = 100%) is the household's beneficial
fraction, applied to derive its position everywhere the property matters.

**How:** the share scales `$state['property']` and `$state['mortgageOutstanding']` at projector init (so wealth,
the Pension-Credit means test on a let home, IHT, and growth all reflect only the share owned), the stay-put
running costs in `projectYear`, and — in `HousingComparison::saleProceeds` — the household's sale price,
mortgage and selling costs, with **CGT computed on its share of the gain** (then split across its owners, so the
beneficial share and the per-owner allowances compose). Scaling by the share is a **no-op when null**, so the
reconciliation-tested whole-ownership sale math is byte-identical and every existing housing test is unaffected.
The buy-variant's new home is a fresh 100%-owned purchase, so its running-cost ratio stays on whole figures.

**v1 note:** running costs and the mortgage are apportioned pro-rata (the standard tenants-in-common assumption);
a household paying a non-pro-rata share of upkeep is not modelled. `OwnershipShareTest` pins the share reaching the
sale proceeds (halved + still reconciling), the CGT (a smaller share taxes a smaller gain, still non-zero), and the
forecast property wealth (a half share counts half). Builder gained an ownership-share input with a whole-property
hint. **With this, all five collected-but-unconsumed silent-drops from the 2026-07-02 audit are closed.**

## 2026-07-03 — Employee NI is now category-aware (`Person::niCategory` consumed; sourced B/E/I + D/J/L/Z rates)
**Decision:** Wired `Person::niCategory`, the fourth silent-drop. Rob chose to **wire fully** (add the real
per-category rate tables, not a minimal stub) — his standing steer that an accurate, true-to-life forecast beats
less work. Employee (primary) Class 1 NI now varies by category letter instead of charging everyone the standard
category-A rate.

**Sourced rates (gov.uk `rates-and-thresholds-for-employers` category-letter tables, 2025-26 and 2026-27
identical, verified 2026-07-03):** standard **8%** main / 2% upper (A, F, H, M, N, V); married-women's/widow's
**reduced 1.85%** / 2% (B, E, I); **deferred 2%** / 2% (D, J, L, Z); **nil** employee NI (C, K, S, X). Only the
main-band rate varies; the upper-band rate is 2% across all. `NationalInsuranceParameters` gained
`reducedMainRate` + `deferredMainRate`; `NationalInsuranceCalculator::onEmploymentEarnings` takes the category
letter (case/space-insensitive; null/unrecognised → standard) and picks the main-band rate, returning zero for the
nil categories (alongside the existing over-SPA zero). The projector passes `Person::niCategory` through.

**Scope note:** category C (over State Pension age) is redundant with the engine's existing SPA zeroing but is
honoured for completeness. The builder gained a category **select** (Standard / B / C / J / X — the realistic
choices; empty = standard) with a note that it only affects NI on earnings. Tested at both levels:
`NationalInsuranceCalculatorTest` pins each rate to the penny on a £62,570 earner (clean £37,700 main band),
`NiCategoryForecastTest` proves the category reaches the forecast (year-0 total tax falls by exactly the sourced
band-rate delta for B/J, and by the whole NI for X). **Last silent-drop: `Property::ownershipShare`** — Rob asked
me to research the correct convention (tenants-in-common beneficial-share apportionment of value/proceeds/gain);
in progress.

## 2026-07-02 — DB commutation now modelled (third silent-drop backlog fix; Rob chose wire-not-remove)
**Decision:** Wired `DbPension::commutationLumpSum`/`commutationFactor`, the next unconsumed silent-drop.
Rob was asked wire-or-remove for the three remaining lower-impact fields and chose **wire all three**;
commutation is the most on-brand (the tool's headline is the pension lump-sum decision). Commutation = take a
tax-free lump sum at retirement in exchange for a permanently lower DB pension.

**Type fix:** `commutationFactor` was mis-typed `?Percent` (a commutation factor is a ratio like 12:1, not a
percentage — the fixtures worked around it by storing `Percent::fromPercent(1200)` so `asFraction()` == 12).
Changed to a plain **`?float`** (the £-lump-sum-per-£1-pension-given-up ratio, e.g. 12). Ripple: DTO, the
assembler (`floatOrNull`), both test fixtures (`12`, not `1200`/`Percent`), and `WhatIfChanges` (dropped from
the RATE list so it renders as a plain number, not "12%"). No `commutationFactor` mapper exists (DB pensions
derive from `builder_state`), so storage was unaffected.

**Model (in `PathProjector`):** a shared `commutedAnnualPence(DbPension)` returns the annual pension after the
election — `accrued − round(lumpSum ÷ factor)`, floored at 0, factor null/≤0 defaulting to 12 — used by both
`dbIncome` and `survivorDbIncomeNominal` (so the survivor inherits a fraction of the *reduced* pension). The
tax-free lump sum is paid once, in the year the member reaches NRA **while alive** (`age === NRA`, so a member
already past NRA at the base year — who commuted pre-forecast — is not re-paid), escalated by `dbFactor` so the
£-for-£ relationship holds at the retirement date, and routed through the same tax-free-cash path as a DC PCLS
(`pension_lump_sum` source → net cash → wealth).

**v1 limits (flagged in code):** the lump sum is treated as fully tax-free (the LSA cap is not enforced for DB
commutation — it needs the DB capital value); a member who dies before NRA never takes the lump sum, and the
survivor pension then uses the reduced base (a simplification). Builder gained a **commutation factor** input
(placeholder 12) + hints on both commutation fields. `DbCommutationTest` pins the reduction, the one-off lump
sum at retirement, the null-factor default of 12, and the no-commutation baseline. Suite green. Remaining
silent-drops: `Person::niCategory`, `Property::ownershipShare` (Rob: wire both — next).

## 2026-07-02 — Per-person salary growth: `Person::salaryGrowth` now consumed (second silent-drop backlog fix)
**Decision:** Fixed the next data-integrity silent-drop after the survivor DB pension (below):
`Person::salaryGrowth` was a live, validated, DTO-mapped builder input read by no engine code. The
projector escalated a single household-wide `salaryFactor` off the assumption set's economy-wide salary
growth, so a person's own entered figure silently vanished — and two earners could not grow their pay at
different rates.

**How:** the projector's `salaryFactor` became a **per-person map** (keyed by person id, 1.0 in the base
year). Each year `growState` escalates each person's factor at their own **real** override
(`Person::salaryGrowth`), falling back to the assumption-set `salaryGrowthReal` when null; both are
compounded with that year's inflation to nominal. The override sets the trend, not risk (no added
volatility — the same convention as the per-asset growth overrides). The two salary read points
(earnings in `projectYear`, NI in `niForPerson`) now index the factor by person.

**Semantics chosen:** the per-person figure is a **real** (above-inflation) rate, matching how the
assumption-set salary growth is already framed and surfaced ("Salary growth (real)") — one definition, so
a user's per-person entry and the economy-wide default mean the same thing. The builder field was relabelled
"Salary growth (%/yr, real)" with a hint (it previously did nothing and was ambiguous about real-vs-nominal).

**Tested:** `PerPersonSalaryGrowthTest` — the override reaches the forecast at the exact compounding rate
(£50k at 5% → £52.5k → £55.125k), two workers with different growth diverge (proving per-person, not one
shared factor), and a null override follows the assumption set's 3%. Suite green. Remaining silent-drop
fixes (`DbPension` commutation, `Property::ownershipShare`, `Person::niCategory`) stay on the PLAN backlog.

## 2026-07-02 — Survivor DB pension: `spousePensionFraction` now paid (first silent-drop backlog fix)
**Decision:** Fixed the top data-integrity silent-drop the 2026-07-02 doc audit re-found (see the docs-only
entry below): `DbPension::spousePensionFraction` was collected, validated and mapped into the DTO but consumed
by **no** engine code, so on a DB member's death the surviving partner received **£0** DB income regardless of
the fraction entered. This violated the CLAUDE.md completeness rule (every input that should affect a result
must reach it) and understated a couple's secure income after the first death.

**How:** a new `PathProjector::survivorDbIncomeNominal()` — the joint-life analogue of the already-correct
`annuityIncomeNominal()` — pays `accruedAnnualPension × dbFactor × spousePensionFraction` to the first living
partner once the member has died, escalated by the same in-payment `dbFactor` as the member's own pension and
sourced as `defined_benefit` (so it is taxed and counts as assessable income for the Pension Credit test, like
the member's own DB income). The member-alive path (`dbIncome()`) is unchanged, so no double-count (owner alive
XOR dead). A scheme with a **null** fraction still stops on death, exactly as before.

**v1 scope choice (flagged in the method docblock):** a single fraction, paid from the member's death regardless
of whether they had reached normal retirement age (real schemes pay a spouse's pension on death in service /
deferment / payment alike), escalated by the household `dbFactor` from the base year (no separate deferred-
revaluation basis) — consistent with how the member's own DB pension is already approximated.

**Tested:** `SurvivorDbPensionTest` — a completeness test (the entered 50% fraction demonstrably reaches the
forecast: full pension while the member lives, half for life after death, not £0) plus the boundary guard (a
null fraction pays nothing after death). Suite green. Also added a builder hint under the "Survivor fraction (%)"
field (it previously did nothing and had no explanation — trust depends on the copy matching the engine).
Remaining silent-drop fixes (`Person::salaryGrowth` next, then the lower-impact fields) stay on the PLAN backlog.

## 2026-07-02 — Docs-only pass: delta research folded in, doc set reconciled to code, household scope decided
**Decision:** On Rob's ask ("review the project + the improved brief; then update the plan and documentation so we
can build any features really well"), a two-workflow pass ran (a) a **delta research wave** over the five topics the
2026-06-30 competitive scan left as residuals/gaps, and (b) a **full doc-set audit** against code. No engine/app
code was changed — this pass sets up the build. Outcomes:
- **Household scope decided.** RetireForecast will **not** support a third full **planning subject** — the
  1–2-person (couple) ceiling stands. This matches the whole market (Boldin is explicitly "one user/couple per
  plan"; Timeline is main-profile+spouse; ProjectionLab's family account is an unbuilt 239-vote request; Guiide is
  single-only; even Voyant's extra people are dependants). Throuples / 30+ accumulation planning are **back-burner
  aspiration**, not scope. **But** Rob's real ask — "model 3 adults contributing to upkeep" — is a **backlog item**
  served *without* third-person planning: a household-owned **`BoardContribution`** income stream (three-way UK tax
  enum: family cost-sharing non-taxable · Rent-a-Room £7,500/£3,750 · taxable rent) + a lightweight
  **`HouseholdMember`** presence record (flags only) that can **reduce** entitlements (the 25% council-tax discount,
  the PC Severe Disability addition) — reconciliation in reverse. See docs/RESEARCH-delta-2026-07-02.md §2 + the
  PLAN "Delta-research backlog".
- **Delta research captured** in **docs/RESEARCH-delta-2026-07-02.md** (uncertainty communication; household
  composition; adviser/Pension-Wise outputs; accessibility + mobile; methodology disclosure), every load-bearing
  claim adversarially source-checked, recommendations sized, folded into the PLAN "Delta-research backlog". Load-
  bearing UK anchors: **WCAG 2.2 AA** is now the operative UK baseline (RF targets 2.1); the FCA's deterministic-
  leads / stochastic-supplement convention (COBS 13.5) + the scrapped PRIIPs percentile scenarios validate RF's
  ladder-first layout; the five **COBS 9.4.10G** drawdown risk warnings + **TR24/1** define an adviser output pack;
  RF's per-figure `source`+`verified_on` provenance already exceeds every public methodology page found.
- **Silent-drop class re-found (data-integrity).** The doc audit found the same collected-but-unconsumed bug class
  the 2026-07-02 engine pass fixed for the per-asset overrides, still live in **five** inputs — worst:
  **`DbPension::spousePensionFraction`** (a survivor's DB pension is silently **£0**; the annuity's analogous
  `survivorFraction` **is** consumed) and **`Person::salaryGrowth`** (the engine always uses the assumption-set
  figure). Logged in DATA-MODEL "Known divergences" + the PLAN backlog as the **first** fixes (wire with a
  per-source completeness test, or remove the input); **not fixed this pass** (docs-only, per Rob).
- **Doc set reconciled to code.** PRD open questions that shipped (everything-user-editable, the forced-housing
  workstream) marked resolved; DATA-MODEL entity tables caveated for the five unconsumed fields + three
  never-materialised fields moved to Known divergences; PLAN DONE/superseded markers corrected (withdrawal-
  sequencing core shipped, neutral-diagnostics declined, CSP/freshness/mortality-refresh done, the report is
  single-strategy, a11y form-UX defects built); ASSUMPTIONS/MORTALITY de-staled (runtime editability, the mortality
  grid-edge behaviour + `mortality:refresh`, the 2024-based ONS release); the stress-test doc records that
  `HistoricalReturns` embeds **JST only, ending 2020** (the recommended ONS-inflation extension was not built).
**Why:** the brief overstated "full backlog delivered" and "throuples/30+ support"; grounding both against reality
before building avoids a fresh agent building the wrong thing, and the research turns "align with real UK tools"
into a concrete, sourced, sized backlog. Docs-only keeps the green invariant untouched and leaves the actual
feature builds — with the data-integrity fixes first — for Rob to prioritise. Links: [[data-consistency-reconciliation]],
[[handover-doc-hygiene]].

## 2026-07-02 — Review pass: two engine correctness fixes, per-asset overrides wired, and a personal-data scrub + history purge
**Decision:** A project review (multi-agent audit of the engine + docs) surfaced and fixed the following.
- **Personal data purged from the repo.** Real figures/names for the V2 couple had leaked into **tracked** files
  (the stale, orphaned `docs/morning-worklist.md`; `docs/PLAN.md` + this log's narrative; and several import
  tests/fixtures — exact salary / State Pension / DLA / mortgage amounts, the couple's first names, and their
  workbook tab labels), violating the **no-hardcoded-client-data** rule. Everything was first captured privately
  (kept only in the gitignored local files — deliberately not restated here, per the same rule; alongside
  `docs/SCENARIO-V2.local.md`), then scrubbed from the working tree — doc figures → neutral placeholders (rationale
  preserved), test figures → clearly-synthetic values (assertions kept consistent, suite green), names → Alex/Sam/Blake.
  Because the data was already in pushed history, git history was **rewritten and force-pushed** to remove
  it from all past commits (Rob authorised the force-push). The `docs/*.xlsx` + `docs/*.local.md` gitignores already
  guard the source files; this closes the leak into tracked markdown/tests.
- **HIGH — DC pension drawn before its earliest access age (fixed).** `DcPension::earliestAccessAge` was mapped and
  builder-validated but **never consulted by `PathProjector`**, so a shortfall in an early-retirement year drew the DC
  pot at any age — making an infeasible plan (e.g. retire 52, access 57) read as feasible. Each pot now carries its
  access age; `fundShortfall`'s `drawPension` skips a pot until its owner reaches it (an inherited pot carries age 0 —
  a beneficiary drawdown is accessible at any age). Engine-tested.
- **HIGH — CGT band-straddle under-taxed low-income GIA disposals (fixed).** `capitalGainsTax` computed the 18% band
  room as `(PA + basic-rate band) − income`, letting the **unused personal allowance extend the 18% CGT band** when
  income is below the PA (the PA is not available against gains). A £50k gain at £0 income was taxed £8,460 vs the
  correct £9,018 (£558 under) — hitting exactly the target population (low-income retirees drawing a gainful GIA). Room
  is now `basic-rate band − max(0, income − PA)`. Extracted `PathProjector::cgtOnGain` (public static, like
  `disposeGiaSlice`) with an exact-figure test across the below-PA / straddle boundaries.
- **MED — per-asset overrides now reach the forecast (was a silent drop).** `DcPension::growthAssumptionOverride`,
  `Property::growthAssumptionOverride` and `Account::yield` were collected + mapped but **ignored by the engine**. Now
  a pot/home grows at its own real rate, and a GIA distributes income at its own yield (per-person, balance-weighted
  blend where a person holds multiple GIA accounts — held at base-year weights, a flagged v1 approximation), each
  falling back to the assumption-set default when unset. Return-only override (no volatility change), so it composes
  with the stochastic Monte Carlo the same way house/salary growth already do. A completeness test per override.
- **MED — CGT `incomeBySource` reconciliation (guard added, no numeric change).** In a GIA-disposal year the capital
  drawn to pay the CGT is a real withdrawal, so `asset_drawdown`/`pension_drawdown` deliberately exceed the spend-only
  `shortfallFunded` by exactly that tax — which keeps the cashflow ladder's money-in == money-out. This was correct but
  unpinned; added a per-year reconciliation test and a code comment so it is not "fixed" wrongly by restoring the totals.
**Why:** the two HIGH items are trust-critical correctness bugs in the core engine that no test pinned; the override
wiring closes a silent-drop (the project's cardinal sin — every input that should affect a result must reach it); and
the data scrub restores the no-client-data-in-the-repo invariant. Full suite green throughout.

## 2026-07-01 — Mortgage payment stops after a repay-from-capital redemption (built — the last Lane-B deferred item)
**Decision:** Built the `while_mortgaged` expense condition per
[docs/PLAN-mortgage-payment-stop.md](build/PLAN-mortgage-payment-stop.md), removing the v1 simplification flagged in
`PathProjector`. A mortgage payment line now auto-classifies to `while_mortgaged` (not `while_owning_home`), summed
into a new `ExpenseProfile::mortgageCosts` marked subset; the projector **drops it once the mortgage is redeemed**
(`RepayFromCapital`), so a repay-and-stay path is no longer charged both the one-off repayment **and** the ongoing
payment. Service charge / ground rent stay `while_owning_home` (they continue while the home is owned); the sell
variants strip both via `withoutPropertyCosts()` (widened to remove `mortgageCosts` too); `Refinance` / no-redemption
paths keep paying (unchanged). PLSA comparable spend excludes `mortgageCosts` as well (outright-ownership basis).
Resolves the "Still deferred" item noted in [[2026-07-01 — V2 pressure-test: deferred-refinement resolutions (let-home capital, first-class tax-free-benefit type, income-ends-on-sale declined)]].
**Why:** without it, redeeming the mortgage from capital cleared the balance but the modelled monthly payment kept
charging — a double-count that understated a keep-the-home plan. Occupation-vs-mortgage is a real distinction: you can
own a flat mortgage-free (service charge continues, mortgage payment does not). Engine-tested (payment stops from the
redemption year on `RepayFromCapital`, persists on `Refinance`, and is removed with the other housing costs on a sale).
The **in-place forced-sale** model remains the one open Lane-B refinement.
**Status:** active.

## 2026-07-01 — Care-cost stochasticity in the Monte Carlo (Lane A — last post-v1 backlog item)
**Decision:** Model the fat-tail risk of late-life residential/nursing care fees as a sampled
event in the Monte Carlo (not the deterministic central line — most people pay nothing, a minority
pay a great deal, so an "expected" figure would mislead). `CareCostSampler` draws a per-person
spell — Bernoulli on the probability of ever needing care, an exponential duration, a residential
vs nursing fee — placed at the end of life (care need concentrates near death, so it depends on the
already-sampled death age). Wired through a new `PathDraws::careAnnualCost` (the deterministic and
historical drivers return 0), charged as an essential outflow in `PathProjector`, and accumulated
onto `ForecastResult::careCostReal`; `Simulator` aggregates a `CareImpact` (share of paths with
care + median/p90 bill) onto `SimulationResult`. **Sourced `CareAssumptions`** (verified_on
2026-07-01): probability ~1 in 4 (Dilnot Commission / PSSRU), duration mean ~2.5 yr from PSSRU/LSE
length-of-stay, self-funder fees ~£1,300/wk residential and ~£1,600/wk nursing (LaingBuisson 35th
ed. 2025). This resolves the [[2026-07-01 — Care-cost + ONS-refresh data sources (Lane A; ONS-refresh built later)]] source decision.
**Key choices:**
- **Opt-in, default OFF** (`ForecastSettings::modelCareCost`, a sparse builder toggle), so existing
  runs/tests are unchanged; on a shared multi-lane tree this avoided shifting every stored MC result.
  The rent variant's reconstructed settings propagate the flag, so buy-vs-rent models care on all legs.
- **Made visible, not buried** (completeness): the risk surfaces as its own results panel (chance of
  care + typical/high-end bill), not just a lower success rate — a per-source test proves it reaches
  the result and is reported. The sparse storage mirrors the include-flag / assumptionOverrides pattern.
- **v1 simplifications (flagged):** the GROSS self-funder cost is charged (the means-test LA
  contribution once assets fall below the threshold is not modelled → conservative, and the existing
  {@see Care\CareMeansTest} is the hook for the refinement); end-of-life timing rather than ONS
  health-state life expectancy; a single probability (no sex/age split); annual granularity.
**Status:** built (engine + Monte Carlo + builder toggle + results panel), suite green. **This was
the last open Lane A post-v1 backlog item.**

## 2026-07-01 — ONS mortality refresh + integrity guardrail (Lane A)
**Decision:** A `mortality:refresh` command is the ONS-refresh backlog item. The engine's
`OnsPeriodMortalityData` is **generated from** a sourced JSON resource
(`ons-2024-period-qx.json`, ONS 2024-based, verified 2026-06-24), but **nothing verified the two
still agreed** — a hand-edit or a half-finished refresh could silently drift the class from its
source. The command closes that gap and adds freshness + a refresh path:
- **Integrity** — diff the embedded `OnsPeriodMortalityData::periodQx()` against the JSON cell for
  cell (5100 cells); any drift is a non-zero exit. This is the mortality counterpart of the gov.uk
  figure-verification pass, and the data-integrity rule applied to mortality (one home = the JSON;
  the class is a projection of it).
- **Freshness** — flag when the ONS data was verified more than `--months` ago (default 24, ONS
  updates biennially), reusing the pure `FigureFreshness` date maths (the engine stays clock-free).
- **Refresh/ingest** — `--against <newRelease.json>` diffs a freshly downloaded ONS release against
  what we embed and shows the **cohort-life-expectancy impact** (via `CohortLifeTable` on each grid)
  before adoption. Sits beside `figures:freshness` (the 2026-06-30 source-freshness guardrail).
**Scope choice (flagged):** the command ingests the ONS data **as the JSON resource shape** (the
existing sourced home), not the raw ONS xlsx — converting a new ONS "mortality rates qx (principal
projection)" release into that JSON is the documented manual front step (source_url is in the file).
Auto-parsing the xlsx (phpspreadsheet) is a later enhancement; the integrity + impact-diff value
does not depend on it. Grid work is the pure, unit-tested `App\Finance\MortalityDataset`.
**Status:** built + tested (incl. the retroactive in-sync guard proving the committed class matches
its JSON). This leaves **care-cost stochasticity** as the last open Lane A backlog item.

## 2026-07-01 — Tax-efficient withdrawal sequencing: full-capability build approved (Lane C)
**Decision:** Build **tax-efficient withdrawal sequencing across wrappers** ("fill the band") — the top item from the
2026-06-30 full-market competitive scan (docs/RESEARCH-competitive-gap-analysis.md, Cluster A). Rob approved the **full
capability** (not a reduced slice): a new **`Forecast\DrawdownStrategy::FillBands`** (draw personal allowance → CGT
annual-exempt-amount → ISA → basic-rate pension → rest), **Pension-Credit-aware** (never draw pension income that claws
Guarantee Credit back £-for-£ — read `Benefits\PensionCreditCalculator`), stepping around the **60% PA-taper** band,
with **planner-timed PCLS**, a **lifetime-tax £-delta** surfaced in Compare (a neutral number always + an advice-gated
steer behind `personal_use`), and a **search-optimiser** sequenced last. Delivered additively on
`PathProjector::fundShortfall`, each slice green. Full build order + rationale: **docs/PLAN-withdrawal-sequencing.md**.
**Why:** RF already owns the penny-accurate HMRC engine, so sequencing is the highest-value, most on-brand net-new item
— no UK consumer tool optimises the ISA/SIPP/GIA draw order and quantifies the £ saved (RightCapital-style). The
now-live Pension Credit means-test makes the household-specific interaction a real correctness point: a naive
band-filler would silently claw the benefit back (the completeness class of bug the project guards against).
**Status:** core shipped. Built + committed (green): the engine core (`FillBands` fill-order in
`PathProjector::fundShortfall`, Pension-Credit-aware, + engine tests), the PA-taper (resolved by the ordering, no
code), the £-delta computation (`App\Forecast\WithdrawalStrategyComparison`, reconciliation-tested), and the
**results-page panel + advice-gated steer** (`Interpretation::withdrawalSequencingNarrative` behind `personal_use`).
**Handed off (not built):** #5 planner-timed PCLS + #6 the optimiser — a ready-to-execute plan is in
docs/PLAN-withdrawal-sequencing.md ("Implementation plan for a fresh agent"), gated on two small modelling calls from
Rob. Coordinate on the shared `PathProjector` (Lane A/B/C/D — see HANDOVER "Multi-agent coordination").

## 2026-07-01 — Stress-test panel: historical sequence-of-returns backtest (Lane A)
**Decision:** The stress-test panel is **historical sequence backtesting** — replay each past year's
*actual* UK returns + inflation over the current plan ("how would this plan have fared starting into 1929 /
1973-74 / 2000 / 2007?"). This is the sector standard (Timeline, the 4% rule) and directly tests
**sequence-of-returns risk**, which our Monte Carlo does not isolate. Engine: `HistoricalSequenceDraws` (a
third `PathDraws` alongside deterministic + Monte Carlo) overlays a start year's real path onto the existing
`PathProjector`, falling back to expected returns beyond the data tail; `HistoricalBacktester` runs every
eligible start year and reports the survival rate + worst start; a results-page panel shows the % of ~140
historical starts survived, the worst start, and named crises. `RepresentativeDeathAge` was extracted so the
forecast and backtest share one median-lifespan rule. See [[2026-07-01 — What-if sliders (explore the levers) on the results page]] for the sibling exploratory path.

**Data source (this was the whole gate — research in docs/RESEARCH-stress-test-and-official-sources.md):**
- Rob's steer was "official source (ONS/FCA)". Finding: **there is no ONS/FCA historical asset-return series**
  (ONS = inflation + demography; FCA = illustration/stress *methodology*, not data). I recommended the Bank of
  England millennium dataset (OGL, shippable) and Rob picked it, but on **downloading and inspecting the actual
  file** it holds only a share **price** index + bond **yields** — **no equity total return / dividends**, which
  are ~half of long-run equity return. BoE alone cannot back a credible backtest. Correction surfaced to Rob.
- **Chosen: Jordà–Schularick–Taylor Macrohistory database ("The Rate of Return on Everything", R6)** — measured
  UK equity/bond/bill **total returns** + dividend yield + CPI, 1871–2020, peer-reviewed (QJE 2019). Baked as the
  sourced `HistoricalReturns` engine data class (generated from the file, not hand-typed; real returns derived as
  (1+nominal)/(1+inflation)−1), cited with `verified_on: 2026-07-01`.
- **Licence is load-bearing:** JST is **CC BY-NC-SA 4.0 (non-commercial + ShareAlike)**. Fine for the current
  **private, personal-use** tool; it is a **flagged PUBLIC-RELEASE BLOCKER** (documented in `HistoricalReturns`)
  the same way `config('compliance.personal_use')` flags the regulatory line: before any public release the data
  must be swapped for an OGL/commercially-licensed source (BoE prices + a licensed dividend series, or DMS) or
  removed. This is why BoE (OGL, but no total returns) and DMS/Barclays (accurate, paid) were both set aside.

**v1 simplifications (flagged):** house-price and salary growth stay at the assumption's expected real rates in a
backtest (the stress is on market returns + inflation); a start year is eligible only with ≥10 years of real data
after it (so the early, sequence-risk-critical window is always historical), the deep tail reverting to expected
returns; the historical inflation path runs against the *current* frozen-to-2031 tax thresholds (realistic, but a
1970s-inflation overlay drags hard early). **Status:** built (engine + panel), green. Panel pending Rob's browser sign-off.

## 2026-07-01 — Care-cost + ONS-refresh data sources (Lane A; ONS-refresh built later)
**Decision:** For Rob's "pull from ONS" on both: **ONS-refresh is fully ONS** (national + past/projected cohort
life tables map onto `CohortLifeTable`; ready to build). **Care-cost is only partly ONS** — ONS gives self-funder
stats + health-state life expectancy (care *entry timing*) but **not** weekly fees or the probability/duration of
needing care. Rob agreed to source those from **LaingBuisson** (fees, ~£1,300/wk residential, £1,600/wk nursing)
and **PSSRU/LSE** (probability + duration), each cited with `verified_on`, with ONS health-state life expectancy
for timing. Neither is built yet; sources locked. See docs/RESEARCH-stress-test-and-official-sources.md.

## 2026-07-01 — Annuitisation: convert part of a DC pot into a lifetime income (Lane A)
**Decision:** A DC pension can buy a **lifetime annuity** with part of its pot at a chosen age: the pot falls by the
purchase amount and, from that age, pays a guaranteed income = **amount × rate** for life. A new `AnnuityPurchase`
DTO (`atAge`, `amount`, `rate`, `escalation`, optional `survivorFraction`) hangs off `DcPension` (null = keep in
drawdown); `PathProjector` buys it once (reducing the pot) and pays the income each year. **Level** (escalation
`None`) is a flat nominal income (falls in real terms); any other basis **escalates with inflation** from purchase —
the same proxy the engine already uses for DB escalation in payment. A **joint-life** annuity (`survivorFraction`
set) continues to the surviving partner at that fraction after the annuitant dies; **single-life** stops. The income
is taxable and counts as assessable income for the Pension Credit test, so it stacks correctly with the rest of the
forecast.
**Key choices:**
- **The rate is a user input** (builder default a sourced **~7.2%**, a rough level joint-life-at-65 guide), so **no
  fabricated age/rate/health table lives in the engine** — it only multiplies the pot by the rate. This is the same
  discipline as every other figure carrying a source; a real quote is age/health-specific and belongs to the user.
- **Income maps to the existing `other_taxable` source**, which the `YearResult` doc already names as covering
  annuity income — so **no change to `INCOME_SOURCES`** (avoiding churn on the source list Lane B had just grown to 9,
  and any mapper/UI change). Completeness is still guarded (a test shows an annuity demonstrably reaches the forecast).
- **The purchase amount is treated as nominal at the purchase age**, matching how planned withdrawals already work
  (a v1 simplification, flagged). **Buying is not a taxable event** (the income is taxed as it arrives). The pot loses
  drawable value on purchase — economically correct (capital exchanged for income), and consistent with DB (an income,
  not a pot); the annuity's longevity value is not capitalised into wealth (v1, flagged).
- **Builder storage is sparse** (annuity fields stored only when annuitising), mirroring the include-flag / selling-costs
  pattern, so a scenario predating the feature and a no-op what-if record no delta. See [[2026-06-30 — What-if sliders (explore the levers) on the results page]] for the sibling "explore levers" path; annuitisation is a saved plan input, not a throwaway lever.
**Why:** annuitisation is the one remaining decumulation policy the engine could not express — a household choosing
certainty (a guaranteed floor) over flexibility (drawdown) is a core retirement decision, and the buy-vs-drawdown
trade-off is exactly what this tool exists to show. Built engine-first (framework-free, tested to the penny), then
the builder, each committed green.
**Status:** built (engine + builder). Remaining Lane A backlog: the stress-test panel (gated on authoritative sourced
historical sequences), the ONS-refresh script, care-cost assumptions.

## 2026-07-01 — Surface investment (capital) growth separately from investment income
**Decision:** The cashflow ladder now shows the year's **capital growth** (share/fund appreciation left inside the
pots) as its own figure, beside the existing **investment income** (interest + dividends paid out and taxed each
year). New `YearResult::investmentGrowth` (nullable Money, real terms); `PathProjector::growState` returns the
year's nominal capital growth and the projection loop attaches it **deflated by NEXT year's price level**, so it is
the real purchasing-power gain that matches the real wealth line's progression (not the inflated nominal figure).
The ladder column shows only when growth occurs, with a note distinguishing the two, and the CSV carries it.
**Why (Rob):** "surface where the gains come from (interest / share growth)". The ladder already showed investment
income, but the larger part of a real return — capital growth in ISAs / pensions / GIA — was invisible: it silently
raised the wealth line, so a reader couldn't see why wealth grew (or held) in a drawdown year. Surfacing it closes
the explainability gap and lets income + growth reconcile to the wealth change. Engine-tested that it is honest: an
ISA shows real capital growth while the same cash shows ~none (cash's return is paid out as interest income, not
capital — no double count). Also this session (a feature, no separate decision): **how-to-claim Pension Credit**
guidance on the results page (`ResultPresenter::pensionCreditGuidance`, gov.uk-sourced, shown only when the forecast
credits Pension Credit), because it is means-tested (must be applied for) and heavily under-claimed.
**Status:** active.

## 2026-07-01 — A surviving partner inherits the deceased's assets (the stranded-wealth bug)
**Decision:** On a death, the surviving partner **inherits** the deceased's assets; the projection
transfers them so they stay drawable. **Model:** spouses inherit IHT-free and a DC pot passes to the
beneficiary, so `PathProjector::settleEstates()` moves the deceased's **cash / ISA / GIA** (with a
**CGT base-cost uplift** to the value at death — the heir is taxed only on later gains) and the
**remaining pension pot value** to the **first living person**, once each. The deceased's **scheduled
withdrawals and contributions do not carry** (they were the deceased's decisions — no schedule re-runs
on the heir), and **only the deceased's own assets move** (the survivor's own pot is never lost or
double-counted — Rob's ownership / no-double-dip constraint). On the **last** death there is no
recipient, so nothing transfers (terminal estate).
**Why (the bug it fixes):** `fundShortfall`'s drawdown skips a dead owner's accounts (`if (! $alive)
continue`), yet `liquidWealth` still **summed** them — so on the first death the deceased's savings,
investments and (for sell variants) the **entire** invested sale proceeds (which
{@see Housing\HousingComparison::withHousing} dumps into `persons[0]`) became **counted-but-undrawable**.
The survivor couldn't reach the money, `essentialsMet` went false, and the run read as "ran out" at the
first death with a full pot sitting idle. It hit **every couple forecast** — understating how long money
lasts and inflating "chance of running out" (the flagship number). Surfaced by the V2 review: the Monte
Carlo said "run out by 2032" while the deterministic Compare said 2044 (same variant, same projector) —
the gap was just the older partner's sampled-vs-median death year, each stranding the proceeds. With the
fix the two reconcile (both deplete 2039 on the repro household) and the proceeds are actually drawn
down. Pinned by `EstateInheritanceTest` (survivor spends the inherited cash; the pot is conserved and
ownership-respected). **v1 caveats (flagged):** inherited pension is drawn as taxable income (no
pre-/post-75 beneficiary split); no IHT is charged at the second death; the transfer is annual-granular
(the death year's partial income is not apportioned — the existing granularity).
**Status:** active.

## 2026-07-01 — V2 pressure-test: deferred-refinement resolutions (let-home capital, first-class tax-free-benefit type, income-ends-on-sale declined)
**Context:** working through the refinements deferred from the forced-housing-event workstream
([[2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)]]).
Three resolutions, each committed green:
- **A let home is assessable capital.** When the primary residence is **let** (the household lives elsewhere — the
  "let out & rent" strategy), its equity is no longer the exempt main residence, so `PathProjector` adds it to the
  Pension Credit **assessable capital**. Letting the flat therefore erodes benefit exactly as selling does (on V2:
  £0 Pension Credit let-out vs ~£41k kept when they occupy it). A new `Property::isLet` flag drives it.
- **The tax-free benefit is a first-class income type, not `type: other` + a flag.** The input-clarity plan
  ([[2026-06-30 — Input-expectation clarity: the input layer must catch a mis-entry, not model it away]], and
  DATA-MODEL's planned (D)) was to map a tax-free benefit to `IncomeStream{type: other, taxable: false}`. **Upgraded**
  to a dedicated `IncomeStreamType::DisabilityBenefit` whose tax-free-ness is **structural**: the assembler (the single
  conversion boundary) forces `taxable = false` regardless of the row's flag. Rationale: a mis-entered *taxable* DLA is
  a **double** error — income-taxed **and** counted as Pension Credit assessable income (docking benefit) — so making
  the type itself guarantee the disregard prevents both, where a mere default-untick could still be overridden. The PC
  assessment already counts only taxable income, so the disregard needed no calc change.
- **"Income ends when a named property is sold" (the planned `endsOnSale` flag) is declined.** DATA-MODEL's planned (D)
  last clause proposed linking an `IncomeStream` to a property so a sold flat's rent stops in the sell variants. **Not
  built:** the model has one property slot and income streams don't reference a property; rental income in a sell
  variant is, by construction, from a *different* (unmodelled) property, and the "let out & rent" rent is from the flat
  the household *keeps*. There is no "income tied to the sold home" case in the current single-property model, so the
  flag would add structure to fix a case that can't arise. Revisit only if multi-property (Lane D) lands.
**Still deferred (open):** stopping the bundled mortgage *payment* after a repay-from-capital redemption (the mortgage
payment is a `while_owning_home` cost that today keeps charging after the balance is cleared — a genuine gap needing a
`while_mortgaged` condition); the in-place forced-sale model. See HANDOVER "In progress".
**Status:** active.

## 2026-07-01 — What-ifs are the only way to express a variation; the individual report is single-strategy
**Decision:** An **individual forecast report = one scenario, one strategy**, read top to bottom. Every *variation*
— a different housing strategy (stay / buy / rent / let-out), a lever change (retire / spend / return / longevity)
— is a **specialised what-if scenario** (a delta-child of the base) that lives under the base and is compared on the
**Compare** page. The results page no longer bakes variations in:
- the **3-way headline cards** become a single card for the scenario's own strategy;
- the **"By housing strategy" comparison chart** + its table are **removed from the report** — the comparison is on
  Compare, across what-if scenarios, and the Compare burndown gains the same **milestone annotations** the
  single-scenario charts carry (so the comparison graph has the event context);
- the **cashflow-ladder strategy switcher** is gone — the ladder shows the scenario's own strategy, labelled;
- the **"Explore the levers" live sliders** (a throwaway, unsaved what-if baked into the page) become a
  **"Build a what-if"** control: set the levers, then **save** them as a delta-child (the QuickWhatIf pattern), to
  compare on Compare. The two charts were also moved to the **top** of the report and annotated with the milestones.
**Why (Rob):** "part of the reason the UI is so confusing is the conflation between partial what-ifs being built into
an individual forecast, where those should be specialised What-IF scenarios." A report doing double duty — *this
forecast* and *explore variations* — is the confusion; separating them (one clean report; variations as nameable,
saved, comparable scenarios) resolves it, and the delta-child + Compare machinery already existed to express it.
**Supersedes (for the report only):** the in-report 3-way comparison of
[[2026-06-30 — One-click "compare buy vs rent" (delta-child what-ifs + per-variant Compare)]] and the per-variant
ladder of [[2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav]] —
the per-variant *engine* projection still exists and now drives Compare; only the report stops showing the 3-way
comparison. The what-if sliders of [[2026-06-30 — What-if sliders (explore the levers) on the results page]] are
superseded by the save-as-a-what-if control (no throwaway live preview).
**Also (Rob's ask):** a **"Let out & rent elsewhere"** strategy is added as a generated what-if (keep the flat, let
it, rent somewhere cheaper) — see its own entry.
**Status:** report strip + Compare annotations + sliders→make-a-what-if built, suite green; pending Rob's browser
sign-off. The let-out what-if + the engine treatment of a let home as assessable capital follow.

## 2026-06-30 — Input-expectation / guided-entry clarity (surfaced by the V2 pressure-test)
**Decision:** The V2 data foot-guns the pressure-test exposed are less user error than **UI-communication gaps**
(Rob: "these flags show where the input hasn't matched the expectation of usage, and where the UI needs to
communicate how to use it"). Each mis-entry maps to a concrete builder improvement:
- **Income pay-frequency.** DLA was entered as a **monthly** figure when the DWP pays that benefit **per 4 weeks**
  (so the annualised amount was understated), and the rent reads as a yearly figure that is almost certainly monthly.
  → a **pay-frequency selector** (weekly / 4-weekly / monthly / annual) on every per-period money input, converting
  to the stored annual figure. **4-weekly matters** specifically because DWP (State Pension, DLA/AA/PIP) pays that way.
- **Income type vs taxability.** DLA was entered as a **taxable "rental"** stream (double-counting, and taxed). →
  offer a **"tax-free benefit (DLA / AA / PIP)"** income type that sets `taxable = false`, with examples, so a
  disability benefit can't be mis-typed as taxable rental.
- **Missing retirement age.** An employed person with a blank `plannedRetirementAge` is modelled **earning for
  life** (here, a full salary modelled forever → a large overstatement). → flag it in the builder / input-sanity notes.
- **One-off cost scope.** A one-off (a large convert-to-residential deposit) was charged across **all** housing
  variants, including sell/rent. → let a one-off declare **which path(s)** it applies to (pairs with the
  feasibility-flag + mortgage-redemption work).
These extend the existing input-sanity notes [[2026-06-29 — Adviser-legibility: input-sanity notes (explain a "wild numbers" result back to its input)]]
and the feasibility flags of [[2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)]].
**Why:** the engine is only as trustworthy as its inputs, so the project's single-definition / no-silent-failure
discipline (applied so far to *outputs*) must reach *inputs* — a mis-entry should be caught and explained **at
entry**, not silently produce a plausible-but-wrong forecast. The V2 case is the proof: four ordinary-looking
entries grossly inflated the result until corrected against the real DWP figures.
**Status:** recorded; build folds into the feature workstream (input-clarity track).

## 2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)
**Decision:** Pressure-testing the engine against a **real forced-housing case** (the "V2" couple Rob has been
building) set the next workstream. The case: both about to be retired (one retired on **DLA**, one in her final
working year); they **live in a flat that is on a buy-to-let mortgage** — the occupation is itself the breach, so
the BTL cannot continue, and a residential remortgage fails on age + income; the BTL is **due for redemption** with
no extension, and converting to residential needs a **large lump sum** they don't have, so the realistic
outcome is sell-or-repossession. Equity is the flat's value less a substantial outstanding BTL mortgage, net of a
**partial-PRR** CGT bill (a short-ish remaining lease with a modest extension cost; occupation secondary then
**primary ~4–5 yrs**, joint names).
Guaranteed income floor ≈ two **State Pensions** + a tax-free **DLA** disability benefit, plus one **small** DC pot. **Finding:** the engine already answers the *core* — buy-cheaper-outright vs sell-and-rent on
identical seeds, partial-PRR CGT (occupation-driven, joint owners — near purpose-built for this flat), the
income-floor + per-year surplus/shortfall + safety floor, and the longevity horizon. But the **lump-sum tax shock,
the flagship output, barely applies** (a small DC pot is inside the personal allowance), while the three things that
actually decide this couple's path are **not modelled in the forecast**:
1. **Means-tested benefits are a standalone snapshot, not in the cashflow.** {@see Benefits\CapitalAssessment}
   correctly models the pensioner capital tariff (£10k disregard, £1/wk per £500, the £16k Council Tax / Housing
   Support cliff) but is referenced **only inside `Benefits/` + an audit page** — never by `PathProjector` /
   `app/Forecast`. So the forecast does not **credit** Pension Credit Guarantee Credit / Council Tax Support as
   income, does not **erode** it year-by-year as capital or income change, does not fire the **£16k cliff**
   dynamically, and models no **disability addition** or **DLA/AA passporting**. For an asset-poor, low-income,
   disabled household this interaction is *the* decision: sell → hold ~£130k → lose Council Tax Support + most
   Pension Credit; keep / buy-cheaper → little assessable capital → keep them. **DLA income itself already reaches
   the forecast** as a tax-free `IncomeStream` (the completeness rule, {@see PathProjector} L250-251); the gap is
   the *award* + the capital cliff.
2. **No mortgage maturity / redemption / refinance concept.** A mortgage is a perpetual `outstandingMortgage` that
   surfaces only as a lump at sale, plus an ongoing `while_owning_home` cost charged **forever**. So the engine will
   happily project a **"stay put" path that is physically impossible** here (a BTL that must be redeemed in months),
   and never flag it — exactly the plausible-but-wrong failure the project guards against. Today the real choice can
   only be faked by hand-adding a one-off cost in a what-if (the £100k convert-to-repayment what-if Rob already hit
   in [[2026-06-30 — What-ifs can add and remove items (delta represents structural changes)]]).
3. **No feasibility flags.** {@see Housing\HousingComparison} silently **floors a buy price above net proceeds**
   ("downsizing is assumed") — but ~£130k may not buy a mortgage-free replacement, and "stay" needs £100k they
   don't have. These impossibilities should surface as **input-sanity notes**, not be modelled away.

**The workstream (Rob: "do all of it; I care about the final result, not the order"):**
- **(A) Means-tested benefits in the live forecast.** A sourced engine `PensionCreditCalculator` (Guarantee Credit
  tops assessable income up to the Standard Minimum Guarantee; + Severe Disability / Carer additions; tariff income
  from capital reuses `CapitalAssessment`), wired into `PathProjector` as a **household-level income source each
  year** (new `YearResult` income source `means_tested_benefit`), eroding as capital/income change and firing the
  £16k cliff in-projection. Per-source **completeness** test (the benefit demonstrably reaches the result) +
  **reconciliation** (award + tariff math). Council Tax Reduction is locally-set, so v1 models the **£16k cliff /
  Pension-Credit passport** rather than a precise CTR award (flagged). A **disability flag** is added to drive the
  Severe Disability addition + the DLA/AA passport.
- **(B) Mortgage-redemption event** as first-class state: a redemption/maturity **year** on the home + a
  **maturity action** {refinance at a rate · repay from capital · forced sale}, handled in `PathProjector`
  (track the mortgage balance; at maturity apply the action — inject capital, switch to a repayment cost, or
  transition to the sell transform). Generalises to interest-only maturities and fixed-term ends.
- **(C) Feasibility flags:** when buy price > net proceeds, or "stay" needs capital not held, raise an input-sanity
  note instead of silently flooring.
- **Validation:** a runnable forced-mortgage scenario exercises A–C. The committed test fixture is **synthetic**
  (the "no hardcoded client data" rule); the couple's real figures are run only locally (throwaway), never committed.

**Why:** the project exists for exactly this "older couple, forced housing decision" problem (PRD flagship), and
pressure-testing it on a real case is the intended way to find where it's thin. All three gaps are **general**
(the downsizing benefit-trap, interest-only maturities, infeasible-option flags), not one-off hacks. Recording the
direction now (Rob's ask to update the docs) so the multi-step build stays anchored; each feature lands green with
its own DECISIONS entry + PLAN/DATA-MODEL update.
**Sources (benefits figures — to verify against gov.uk on build, per the verified_on discipline):** gov.uk
**/pension-credit** (Standard Minimum Guarantee single/couple; Severe Disability & Carer additions),
**/council-tax-reduction**, **/disability-living-allowance-adults** & **/attendance-allowance** (tax-free, not
means-tested; the passport). Capital rules already verified 2026-06-27 ({@see TaxYear\BenefitsParameters}).
**Status:** direction recorded; build sequenced next (A → C → B, value-first), each green. **Supersedes nothing.**

## 2026-06-30 — What-if sliders (explore the levers) on the results page
**Decision:** An "Explore the levers" panel with live sliders — retire ± years, spend ± %, investment return ±
percentage points, live ± years — runs a **throwaway deterministic re-forecast** with the adjustment applied and shows
the outcome (money lasts / runs short, spendable wealth at end, spending met). Exploratory and **never saved** (build a
what-if to keep one). Applied via the same levers the quick what-ifs + editable assumptions use (on a transient
scenario through `ScenarioForecaster::deterministic`), so a slider and a saved what-if move the forecast identically.
**Why (Rob):** "err on the side of more flexibility to change values; rebuilds are okay." Sliders make sensitivity
tangible without committing a what-if.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Salary is prorated in the retirement year, not dropped
**Decision:** The engine paid full salary while `age < plannedRetirementAge` and **nothing** from the year the person
reached that age — dropping the whole final year's earnings. It now **prorates** the retirement year: the person stops
on their birthday (when they turn the age), so salary (and its NI) is **birth-month ÷ 12** of the year. Uses the DOB
already captured — no new input.
**Why (Rob):** "salary stops at the point of retirement, so would not be paid for a full calendar year if you leave in
July." The old whole-year drop was conservative but wrong; true month-level proration needs the retirement month, which
the birthday approximates from existing data. An explicit retirement-*month* override remains a possible refinement.
**Status:** built, suite green.

## 2026-06-30 — Per-year surplus/shortfall + a configurable usable-money safety floor
**Decision:** The cashflow ladder classifies each year as **surplus** (regular income covers spend), **drawing** (dipping
into savings to meet spend) or **shortfall** (spend not met) — on **usable money** — and flags any year usable funds fall
below a **safety buffer** (default **2 months of essentials**, user-configurable in the Spending step via
`Scenario::safetyBufferMonths()`, passed to `ResultPresenter::ladder`). A headline says whether usable money stays above
the buffer, dips below it (year), or runs out (year); rows are tinted by status. This **replaces** the academic "neutral
diagnostics" (withdrawal/critical-yield/replacement-rate) backlog idea, which Rob found unhelpful.
**Why (Rob):** "highlight years where they have a shortfall and years where they have a surplus … I'm more interested in
usable money than total net worth … they HAVE to pick a path that never drops below [a floor of] usable funds (2×
monthly essentials in my view)." The buffer is configurable because the right reserve is personal.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Source-freshness guardrail for the verified_on discipline
**Decision:** A **`figures:freshness`** command (over a pure, unit-tested `App\Finance\FigureFreshness`) reports each
supported tax year's gov.uk verification date and **flags any verified more than `--months` ago (default 12)**, exiting
non-zero so CI or a periodic run catches aging statutory figures. `TaxYearRegistry::SUPPORTED_TAX_YEARS` is the single
source of the year set.
**Why:** the project's trust spine is "every figure cites a source + verified_on"; this extends the one-off gov.uk
verification pass into an ongoing guardrail ("verified once" → "noticed when it ages"). Built as a **command, not a
phpunit test**, so the check is not date-dependent/flaky; the date arithmetic is unit-tested against a fixed reference.
**Status:** built, suite green.

## 2026-06-30 — Longevity distribution surfaced from the Monte Carlo (first post-v1 backlog item)
**Decision:** The Monte Carlo now surfaces a **longevity distribution** (a `LongevityDistribution` on `SimulationResult`),
read off the **same joint-life mortality sampler** the wealth paths already run, framed around the **last survivor** (how
long the money must last for a couple): last-survivor age p10/p50/p90, the planning horizon in years (p50 + p90, the
"plan to roughly here" figure), and the probability at least one of the household reaches **95 / 100**. Shown as a neutral
**"How long the money may need to last"** results-page panel (descriptive, not a recommendation). Nullable on
`SimulationResult` so runs persisted before it rehydrate as null (mapper back-compat).
**Why:** the engine already sampled per-path death ages but only used them for cashflow; surfacing the spread is the
cheapest high-value output (it answers "how long might we live / how long must the money stretch", and pairs with the
longevity lever + the deterministic modelled-death age). First of the post-v1 "outputs that exploit results we already
compute" backlog (docs/PLAN.md "External review triage").
**Status:** built, suite green, pending Rob's browser sign-off (needs a completed run — local DB has 0).

## 2026-06-30 — Partial Private Residence Relief CGT on selling a let former home
**Decision:** Capital Gains Tax on selling a former main home that was also let is now modelled (it was hard-coded to
£0). It is driven by **occupation, not the mortgage type** (gov.uk HS283): relief = gain × (main-residence months +
final 9 months) ÷ months owned; the remainder is chargeable, less **each owner's £3,000 annual allowance**, at **18%**
(basic band) / **24%** (higher). Lettings relief is **shared-occupancy only since 6 April 2020**, so a moved-out
whole-property BTL gets none. CGT is **per-individual**, so a jointly-owned home **splits the gain across the owners**
(two allowances + each their own rate). A `CgtHistory` on the engine `Property` (null = full PRR / £0, the common case)
carries it; the builder captures it via a **"Capital gains on sale" wizard** (purchase price, year bought, buying/
improvement costs, jointly-owned + higher-rate toggles, a lived-in vs let **period timeline**) with a live readout, and
the sale waterfall shows the working. Supersedes the "main-home CGT taken as £0" v1 simplification of
[[2026-06-24 — Modelling depth and scope (from approved plan)]].
**Why (Rob):** for a couple selling a former-BTL, £0 CGT is wrong and overstates the proceeds. What determines the
relief is whether they **lived in it as their main home** (occupation), not the mortgage — so living in a home on a BTL
mortgage still counts as main-residence for those months.
**Sources (links):** gov.uk **HS283** (Private Residence Relief); **/tax-sell-home** (+ /absence-from-home,
/let-out-part-of-home); **/capital-gains-tax/rates** (rates + £3,000 AEA, already in the engine, verified 2026-06-27).
**Caveats (flagged in code):** deemed-occupation absences are entered by hand (mark a qualifying absence as "main home"),
not auto-computed; one 18%/24% rate per owner (not a split of a single owner's gain across the band boundary from exact
income); shared-occupancy lettings relief not modelled; the timeline is year-granular (the final 9-month exemption is
still applied exactly).
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — What-ifs can add and remove items (delta represents structural changes)
**Decision:** A delta-child what-if may now **add or remove a list row** (a person, pension, account, income,
one-off cost or pension withdrawal), not only change existing values. The delta stores an **added row whole** at its id
path (`oneOffCosts.<id>` => the row map) and a **removed row** as a sentinel (`accounts.<id>` => `BuilderStateDelta::REMOVED`);
`merge()` appends the adds and drops the removals. An add is kept distinct from an **orphaned value override** (a leaf
whose row the base later deleted) because an add carries the whole row while a value override is a leaf path — so
orphan detection (`orphans()`) still works. The old `structurallyDiffers()` guard and the "A what-if only changes
values…" save refusal are **removed**. The builder's change-highlight now pairs base rows by **id** (not index), so
add/remove/reorder no longer mis-highlights. This **supersedes** the value-only constraint of
[[2026-06-25 — Phase C2 delta-child what-ifs]] (the storage limitation, not the single-source principle).
**Why (Rob):** the refusal "made no sense" — it blocked a legitimate what-if (add a one-off **mortgage deposit** to
model converting a buy-to-let to a repayment mortgage and stay). The block was a storage limitation leaking to the user
as a rule; a what-if is exactly where you explore "what if we also had / dropped this". The base stays the single
source (the child is still a sparse delta, edits flow through), so the C2 principle holds — only the artificial
value-only restriction is lifted.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Personal-use advice mode (the education/guidance line, flagged for later)
**Decision:** While RetireForecast remains a **private, local-first tool for the owner's own use** (not a public
release), the education/guidance-only posture is **relaxed** so it can give the best possible *direct* advice (Rob:
"flag the education line in the code so we can come back to it later; for now focus on giving the best possible
experience and advice for personal use, not public"). The single switch is **`config('compliance.personal_use')`**
(default **true**): when true the `interpret` Gate allows everyone (no admin grant) and the walled-off
`App\Compliance\Interpretation` layer's advice-style readouts show — including the new buy-vs-rent **"why" narrative**
({@see Interpretation::compareNarrative}) that ranks the compared plans and says which to lean towards. This
**supersedes, for personal use only**, the public guidance-only stance of [[2026-06-24 — Regulatory posture: guidance only]]
and [[2026-06-25 — banned-phrasing partition]] — it does not remove them.
**Why:** personal pension/drawdown advice is FCA-regulated, so the guidance-only posture is right for a public release;
but for the owner's own decision-support there is no regulatory bar, and a tool that won't say which option is stronger
is needlessly coy. Keeping it behind ONE documented config key (the "flagged line") makes the relaxation reversible and
auditable: flip `personal_use` to false and the full partition (banned-phrasing lint + per-user `can_interpret` grant)
re-applies. **The suite runs with the flag false** (the public posture stays the tested default); personal-use mode is
exercised by opt-in tests. **Before any public release: set `COMPLIANCE_PERSONAL_USE=false`.**
**Status:** active (personal-use mode on). Marked in code: config/compliance.php, the `interpret` Gate in
AppServiceProvider, CLAUDE.md.

## 2026-06-30 — One-click "compare buy vs rent" (delta-child what-ifs + per-variant Compare)
**Decision:** A **"Compare buy vs rent"** button generates the alternative housing strategies for a base as ordinary
**delta-child what-ifs** (variant-only overrides via `BuyVsRentCompare` + `BuilderStateDelta::diff`, the QuickWhatIf
pattern) and opens Compare. Only **meaningful** strategies are offered (buy needs a buy price, rent needs an annual
rent; the base's own strategy is skipped), and a strategy that already has its generated child is not recreated (no
duplicates on repeat clicks). **`ScenarioCompare` now projects each plan on its OWN variant** via the #6 single source
`deterministicVariants($plan)[$plan->variant->value]` (was the raw stay-put `deterministic()` basis), so the
buy / stay / rent columns actually differ instead of showing identical numbers under different labels.
**Why (Rob):** chose one-click compare over leaving the always-on 3-way comparison, or fully focusing the report on one
strategy. The results page already compared the three strategies, but baking them into every report is what the plan
moves away from; as deliberate what-ifs they are nameable, independently editable (e.g. a different rent assumption)
and read via the existing Compare infra. The Compare-basis fix was required for correctness — without it the
comparison was a mirage (identical figures under different labels).
**Status:** mechanism built, suite green, pending Rob's browser sign-off. **Next decision:** the per-option
plain-English **"why"** narrative (rule-based from the figures/milestones, lint-safe / guidance-only).

## 2026-06-30 — Per-line include/exclude toggle for spend lines (real-time cost toggles, #7)
**Decision:** Each spend line gains an **"Include this cost in the forecast"** checkbox. Switching it off keeps the
line in the form-state (so it can be switched back on) but excludes it from **every** forecast total — the assembler
drops excluded lines once in `household()`, so essential, discretionary, contingent costs and saved self-investment all
exclude them uniformly; the live preview moves as you toggle. An **absent flag means included** (back-compat); the flag
is **stored only when a line is off** (sparse), so a scenario predating the toggle and a what-if that changes nothing
record no spurious delta. Rob chose the **persisted** toggle (saved with the scenario) over an ephemeral preview-only
mode. This is workstream item #7; "real-time" was already delivered by the live preview, so the toggle is the
incremental affordance ("what if I drop this cost?" without deleting it).
**Why:** a quick on/off is friendlier than zeroing or deleting a line (and reversible), and pairs with the live
preview for instant feedback. Filtering once at the assembler boundary keeps the exclusion from leaking into one total
but not another (completeness — the sibling of reconciliation).
**Status:** built, suite green, pending Rob's browser sign-off. **Completes the editable-assumptions workstream
(slices a–e).** Next: buy-vs-rent as a deliberate what-if/Compare.

## 2026-06-30 — Per-line cost-condition override exposed in the builder (completes option b)
**Decision:** Each spend line in the builder gains an **"Applies"** control — *Auto* (classify by description),
*Always*, *Only while you own this home*, *Only while you are working* — the per-line override that option (b) of the
contingent-cost fix had specified but not yet surfaced (the engine already read `condition` from the form-state; only
the control was missing). On *Auto* a hint shows what the label infers, single-sourced from the same
`HouseholdAssembler::autoCondition()` the forecast uses. Hidden for saved self-investment (never a contingent cost).
**Why:** auto-classification handled the common labels, but a user must be able to pin an unusual line (e.g. a mortgage
they will keep, a cost that ends at retirement) without renaming it to trip the classifier.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Selling costs are a per-component breakdown, each on a %/£ basis
**Decision:** The single "selling cost %" is replaced by a breakdown of named components — **estate agent**,
**legal/conveyancing**, **EPC & removals** — each entered on the basis its real-world quote uses: a **% of the sale
price** or a **flat £**. The basis is the value's type in the engine (`SellingCostComponent` holds a `Percent|Money`,
resolved against the sale price); their sum is the total netted off the proceeds, and `HousingProceeds` carries a
**reconciled breakdown** (sum of components == total, asserted). No components → the engine's existing 2% default, so
untouched scenarios are unchanged; the legacy single `sellingCostRate` maps back-compat to one estate-agent component
(total preserved), and the two shapes never co-persist (one home per figure). Defaults: agent 1.25%, legal £1,500,
EPC & removals £800 — editable assumptions, not statutory figures.
**Why (Rob):** "does it have to be fixed as % or £? Some scenarios will be % and some will be flat fee — that's just
how the world works." Estate agents quote a percentage, conveyancing quotes a flat fee; forcing a single basis
misstates one of them and was a foot-gun in the browser walkthrough (a 20%-not-2% entry).
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Live in-builder preview (verdict + end wealth) and the modelled age at death
**Decision:** The builder gained a sticky **live preview** — one cheap deterministic forecast run on a transient
scenario assembled from the current form-state (never saved), recomputed each round-trip — headlining the
does-the-money-last **verdict** plus **spendable / total wealth at end** (Rob chose verdict + end wealth over either
alone). It invites completion while the inputs are too incomplete to forecast. The same forecast drives a per-person
**modelled age at death** shown beside each lifespan lever (from `ForecastResult::deathCalendarYears`), so a
"peer / +10 years" setting resolves to a visible age and year.
**Why:** the wizard gave no feedback before a full Monte Carlo run; ProjectionLab's "edit and watch it move" is the
free-tool pattern (docs/RESEARCH-editable-assumptions-ux.md). Single-sourced from `ScenarioForecaster::deterministic()`
so the preview can never drift from the full run; pure server render (no JS), so it is CSP-safe and progressive.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — The builder highlights a what-if's inputs that differ from the base (and shows the base value)
**Decision:** When editing a what-if in the builder, each input whose value differs from the base plan is **ringed in
amber** *and shows the base value it diverged from* ("was £18,000"), with a one-line "fields you change from the base
are highlighted" banner — so the difference is obvious *and* the original figure is visible while editing (Rob: "would
be good to see the original figure that we have diverged from"). The server computes the changed form-state leaves
mapped to their base value (`ScenarioBuilder::changedFromBase()`, a positional diff of the live `builderState()` vs the
base's `effectiveBuilderState()`, **index-based** so the keys match each input's `wire:model`; base values formatted by
the shared `WhatIfChanges::formatValue()` so the builder hint and the results-page changes format identically), renders
them on the form (`data-builder-diff` + a `data-changes` object), and a bundled script (`resources/js/builder-diff.js`)
rings each matching input (`.builder-diff-changed`) and shows its base value via the field wrapper's `::after`
(`.builder-diff-field[data-original]`) — **not an injected node**, so it never confuses Livewire's morph.
**Why (the load-bearing choice):** there are ~70 inputs across the wizard, so annotating each one server-side was a
non-starter (huge, fragile diff). Instead one server-computed set + one script that matches inputs by their existing
`wire:model` path covers **every** input uniformly, including ones added later, with four small files touched. It is
**pure progressive enhancement** (the form is fully usable without JS; the ring is visual-only), so JS-only is the
right tradeoff here — unlike a result figure, a highlight carries no data. CSP-safe (bundled, not inline; the paths
travel in a data attribute, not an inline script) and morph-aware (re-applied on the Livewire `commit` hook, like
`toc.js`, since a morph rewrites inputs from server HTML that has no ring). The diff is **positional** because a
what-if child cannot reorder/add/remove rows (the delta rule), so index positions align with the base; the
auto-generated name and the wizard step are excluded (not real input changes). On first load of an existing what-if
the changed fields highlight immediately; live-as-you-type refresh follows the deferred `wire:model` round-trips.
[[2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)]] [[2026-06-29 — One-click "quick what-ifs" (retire later / live longer) generated as ordinary delta-children]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — One-click "quick what-ifs" (retire later / live longer) generated as ordinary delta-children
**Decision:** Added **one-click what-if presets** for the two questions a reader most often asks of a forecast —
**"Retire 2 years later"** and **"Live 10 years longer"** — as buttons on the base's results page and on each
dashboard base row. Each posts to `QuickWhatIfController`, which uses `App\Forecast\QuickWhatIf` to edit the base's
people (retire-later bumps each *working* person's `plannedRetirementAge` by 2, clamped to the builder's 50–80;
live-longer moves each person onto a +10-year `offset_years` longevity lever, relative to whatever the base already
models) and stores the result as an **ordinary delta-child**, then opens its results.
**Why:** the what-if highlighting made the gap obvious — exploring "what if we retire/live longer" shouldn't need a
full rebuild. Generating the child through **`BuilderStateDelta::diff()`** against the base (not a hand-written
override map) is the load-bearing choice: the delta is automatically **minimal** (only changed leaves) and
**structurally identical** to the base (it only retunes existing people, never adds/removes a row), so a quick
what-if is byte-for-byte the same shape as a hand-built one — it shows its changes through `WhatIfChanges`, compares,
and edits like any other. A preset that would change nothing (e.g. a lone retiree for "retire later") **builds and
creates nothing** and says so (no empty what-if, no silent no-op); repeated presets get distinct names; the endpoint
is owner-scoped. The longevity preset is the first UI use of the existing per-person longevity lever (the
editable-assumptions plan will surface it directly too).
[[2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)]] [[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)
**Decision:** On Rob's ask ("what-ifs need to highlight what's changed from the base, and add these as tags in the
dashboard"), a delta-child what-if now **shows its `overrides` as readable changes** in three places: a "What this
what-if changes" **panel** at the top of the what-if's results page (each change as **base → new**, plus a "what-if
of <base>" line in the header), compact **change tags** on each what-if row in the **dashboard**, and per-plan
**change chips** in the **Compare** table. One presenter, `App\Forecast\WhatIfChanges`, turns the sparse override
map into `{label, from, to}`: the base value an override replaces is read back through a new
**`BuilderStateDelta::valueAt()`** (the read mirror of `setPath`, descending maps by key and row-lists by stable
id), and each dot-path is humanised — top-level fields, assumption/housing/property figures, and **list rows named
by their own label/identity** ("Essentials · amount", "DC pension · current value", "P1 · gross salary"). Money is
shown as £, rates with %, enums readably; meta fields (the auto-name, the wizard step) are excluded.
**Why:** trust-through-explanation (the workstream's governing principle) applies to what-ifs too — a what-if that
looks identical to its base except for buried numbers can't be reasoned about. Reusing the existing **`overrides`
delta** as the single source (not a separate "what changed" store) keeps one home per fact: the highlight is a pure
projection of the delta, so it can never drift from what the what-if actually overrides, and a base edit flows
through. Orphaned overrides (a base row the child still targets) are surfaced in the panel too (no silent drop).
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — Built the editable-assumptions layer (core): a user-derived custom set from a sourced preset
**Decision:** Built the first slice of the "everything user-editable" direction — the **economic assumptions** are
now editable in the builder. The six figures the read-only assumptions panel already surfaces (investment growth
blended-real, CPI, house growth, rent growth, salary growth, income yield) each get an optional input on step 1,
**defaulting to the chosen preset** (shown as the placeholder + named in the hint); a typed value derives a
**custom set**. Stored as a **sparse `assumptionOverrides` delta** in `builder_state` (only filled figures; an empty
box keeps following the preset, so a re-source still flows through — the same base ⊕ overrides discipline as a
delta-child, and it composes with one for free via `BuilderStateDelta`). The engine `AssumptionSet` gained pure,
immutable `with*` derivations; `App\Forecast\AssumptionOverrides::apply()` overlays the delta; and
**`ScenarioForecaster::assumptions()` is the ONE place it is applied**, so the deterministic forecast, the
per-variant ladder, the Monte Carlo and the **frozen run snapshot** all run the same customised set and cannot
drift. The results panel labels a tuned set **(customised)** and marks **which figures are the user's own**.
**Why (design choices):** (1) **Investment growth is a blended-real return over three asset classes**, not a single
field, so "growth = X%" is applied as a **uniform shift across the asset classes that lands the blend on X**
(`AssumptionSet::withRealReturnShift`) — because the weights sum to 1, the deterministic blend and the per-class
Monte Carlo draws move by the same amount, with **no divergence**; volatility/correlations (risk) are left alone
(the user edits return, not risk). (2) **Sparse delta, key omitted when empty** — never store the preset's value
back (one home per figure), and a what-if child records no spurious assumption delta. (3) **Loose validation bounds**
(e.g. inflation 0–30%, real growth −15–30%) keep an obvious typo out without second-guessing a deliberate stress
test. Reconciliation-tested: no overrides ⇒ the preset unchanged; an edit demonstrably reaches the forecast
(completeness — a lower growth leaves less terminal wealth); the blend lands on the target under any allocation.
**Still to build in this layer:** live in-builder preview; the longevity-lever UX (surface the existing per-person
lever + show the modelled death year); decomposed editable **cost components** (estate agent + legal + EPC/removals);
the **per-line cost-condition override UI** (#1's remaining piece); real-time cost toggles (#7).
**v1 gotcha (engine, recorded so it is not re-hit):** a Blade **block `@php … @endphp`** mis-compiles when the file
already contains an inline **`@php(...)`** form — Blade's non-greedy raw-block regex pairs the inline `@php` with the
block's `@endphp`, silently leaving the opening `@php` literal and emitting a stray `?>` (a parse error far away).
Fix: keep view metadata in the component (`render()` view data), not a `@php` block. Sibling of the earlier
"`@if` glued to a word never compiles" trap.
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]] [[2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav]]
**Status:** active (core built, suite green, pending Rob's browser sign-off). Next: the remaining editable items
above, then buy-vs-rent as a deliberate Compare.

## 2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav
**Decision:** Built the per-strategy cashflow ladder (the legibility item #6). `ScenarioForecaster::deterministicVariants()`
runs each housing strategy through `DeterministicForecaster` on the variant household + settings from
**`HousingComparison::variantInputs()`** — the *same single source* the Monte Carlo comparison runs, so the
deterministic ladder and the simulated comparison transform the household for a sale identically and cannot drift
(`stay_put` is byte-identical to the old `deterministic()`). The results page gained a **strategy selector** driving
the ladder + its milestones (default = the scenario's own variant); the deferred **house-sale milestone** now lands
(year 0, household-level, no per-person age) for a sell strategy; the **PDF** ladder follows the scenario's variant
too (it had the same stay-put-only bug). The displayed-figure provenance invariant (panel == CSV == PDF, one
source) still holds, now on the *selected* variant.
**Why (presentation choices):** (1) **Switch, not side-by-side** — the ladder table is very wide; three side by
side are unreadable, so a selector that swaps the single table is the legible choice. (2) **Only meaningful
strategies are offered** — stay-put always; buy-cheaper only with a buy price; rent only when a sale is configured
(the same gating the sale explainer / assumptions panel already use), so a £0-home or phantom-sale ladder never
shows. (3) **Income-floor / input-sanity notes stay on the raw (stay-put) projection** — they are separate
"household" readouts higher up the page; only the adjacent milestones+ladder block follows the selector, keeping
the blast radius small. Extendable to per-strategy later.
**Also:** the results page is long, so it gained a sticky **"On this page" side nav** (a 2-col grid on `lg+`,
hidden on mobile). It lists only the sections actually present this render (built from the same flags the sections
render under — one source) as **real anchor links that work without JS**; a bundled, CSP-safe `IntersectionObserver`
(`resources/js/toc.js`) highlights the section in view, with a defensive Livewire `commit`-hook re-init for
sections that appear/disappear (a no-op if the hook API differs). Browser-verified by Rob (desktop); mobile
deferred.
**Status:** active. Next in the workstream: the editable-assumptions layer (everything user-editable), then
buy-vs-rent as a deliberate Compare. Builds on [[2026-06-29 — Built #1: contingent-cost placement]] (the variant
households #6 projects are exactly where #1's cost rules bite).

## 2026-06-29 — Built #1: contingent-cost placement (option b) — engine + data-model + auto-classify
**Decision:** Built the correctness fix. An expense line now carries a **condition** (`always` /
`while_owning_home` / `while_working`), **auto-classified by label** (mortgage / service charge / ground rent →
while-owning; commute / season ticket → while-working; else always) with an **explicit per-line override** honoured
first (option b). The engine charges each cost only while its condition holds:
- **`ExpenseProfile`** gains `propertyCosts` + `employmentCosts` — the contingent portions, carried as a **marked
  subset** of essential/discretionary (not a second total, so no drift), with a `withoutPropertyCosts()` that removes
  the housing-linked costs from the essential floor.
- **`HousingComparison`**: the new public **`variantInputs()`** is the single source of the three variant households
  (`compare()` now runs them, and the per-variant ladder #6 will too); the **sell variants (buy/rent) build with
  `withoutPropertyCosts()`**, so the mortgage / service charge stop when the current home is sold — killing the
  phantom-cost bias the buy-vs-rent comparison had.
- **`PathProjector`**: employment-linked costs (commute) are **dropped in years no one earns** (`anyoneWorking()`
  mirrors the earnings condition), so they stop from the retirement year, in every variant.
- **`HouseholdAssembler`** does the label auto-classification (+ override) and aggregates the two markers; *saved*
  self-investment is never contingent (it is not spend).
- **PLSA** comparable spend now **excludes property costs** too (PLSA assumes outright ownership), so the benchmark
  and the variants treat the mortgage on one consistent basis.
**Why:** this is the data-integrity rule applied to contingent expenses — one home per cost, charged only while its
condition holds, with the spend each year equal to the sum of the lines *active* that year (no phantom charge, no
silent drop). Guarded by reconciliation tests: property costs appear **only** in stay-put (zeroed in the sell
variants); the commute **falls by its full amount at the retirement year**; the auto-classify + override + saved-
exclusion + PLSA-exclusion each pinned. **v1 simplifications (flagged):** employment costs stop when the *last*
earner retires (not tied to a specific commuter); contingent costs are treated as essential (removed from the
essential floor first). **Not yet built:** the **builder UI** for the per-line override (the condition is read from
`builder_state` but no control sets it yet — auto-classification gives the defaults); a lifelong-*single*
household's spend is scaled by the survivor factor every year (a pre-existing oddity, noted, left out of scope).
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]] [[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]]
**Status:** active (built, suite green). Next: the **per-variant deterministic cashflow ladder (#6)** (using
`variantInputs()`) to *show* the corrected per-strategy numbers + the house-sale milestone, then the builder
override UI as part of the editable-assumptions layer.

## 2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if
**Decision:** From Rob's browser review of the new explainer layer, four directional calls that reshape the rest of
the workstream:
1. **#1 contingent-cost placement → option (b).** Each expense line is **auto-classified by category/label**
   (mortgage, service charge, ground rent → *while owning the home*; commute → *while working*; everything else →
   *always*) with a **per-line override** in the builder. Rob picked this over a blank per-line dropdown (option a) or
   a fixed set of named contingent lines (option c).
2. **Make all thresholds/assumptions user-editable in the website — nothing hardcoded.** Investment growth, inflation,
   house/rent growth, the **age of death / longevity**, and the selling-cost components must be editable in the UI, not
   baked-in constants. Keep the sourced presets (FCA / DMS / OBR) as *starting points* that derive a user-tweakable
   **custom set**. The per-variant ladder and input-sanity thresholds are likewise user-set, not hardcoded.
3. **Move buy-vs-rent out of the always-baked-in single report into deliberate what-if scenarios** (reusing the
   existing delta-child + Compare infrastructure); the primary report focuses on one chosen strategy.
4. **Show costs as real figures with a breakdown** (estate agent + legal/conveyancing + EPC/removals, SDLT, CGT,
   moving), each editable — not a single opaque 2% rate.
**Why:** Rob's standing principle is trust-through-explanation *and* user control — "I can't trust a number I can't
see the basis of, or change." Research into how existing **free** tools handle this (Boldin, ProjectionLab, the NYT
rent-vs-buy calculator, Guiide, the Actuaries Longevity Illustrator, Honest Math) backs every call: the universal
pattern is **sensible sourced defaults + every assumption overridable + live update**, buy-vs-rent as its *own*
focused comparison, and costs as editable line items. Full findings + the free-tools shortlist:
[docs/RESEARCH-editable-assumptions-ux.md](research/RESEARCH-editable-assumptions-ux.md). Notably we are already *ahead*
on longevity (ONS cohort mortality + the per-person lever + the on-screen modelled death year) — the gap there is UX
(surface + edit), not modelling.
**Sequencing (proposed, to confirm):** (1) #1 contingent costs (option b) — the correctness fix that unblocks an
honest buy-vs-rent; (2) per-variant deterministic ladder (#6); (3) the editable-assumptions layer (custom set +
longevity + cost components, live preview); (4) buy-vs-rent as a deliberate Compare + the per-option narrative.
**Also fixed this pass:** a Blade `@if` glued to a word ("price@if") never compiled and leaked onto the page — the
selling-costs label is now built in the presenter (`saleExplainer` → `sellingCostsLabel`) and guarded by a test; the
ambiguous "Rent" is relabelled as the *projected cost of renting after selling* (not current rent).
[[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]] [[2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)]]
**Status:** agreed direction + research recorded; the build (option-b #1 first) not yet started — pending Rob's
confirmation of the sequencing.

## 2026-06-29 — Adviser-legibility: input-sanity notes (explain a "wild numbers" result back to its input)
**Decision:** Added **input-sanity notes** on the results page — a neutral "A note on your inputs" heads-up, placed
above the figures it affects, explaining when an entered value produced a drastic modelling consequence, so a
surprising result is understood rather than collapsing silently. Two cases, both live-edit foot-guns from Rob's
walkthrough: (a) an **employed** person whose **retirement age is at/below their current age** → no salary is
modelled (the note states both ages); (b) a person **modelled to die in the base year**, which a longevity/health
age below the current age produces (the engine floors a death age at the current age) — read from the new
single-source `ForecastResult::deathCalendarYears`. Factual and lint-safe; empty when nothing is amiss (no noise).
**Why:** the "wild numbers" that triggered this workstream were Rob's own live edits doing exactly these two things
with **no on-screen feedback** — the trust-killer. A note at the point of surprise closes that gap.
`ResultPresenter::inputNotes` + a presenter test (each case fires; a sensible household raises nothing). **Still
open (the rate/£ half of the plan's input-sanity item):** a live £-for-a-rate readout and out-of-range flagging in
the builder (the sale waterfall already shows the selling-cost rate beside its £, so the 20% case is at least
visible on the results page).
[[2026-06-29 — Adviser-legibility: life-event milestones ("when does each event happen")]]
**Status:** active (built, suite green; pending Rob's browser sign-off).

## 2026-06-29 — Adviser-legibility: life-event milestones ("when does each event happen")
**Decision:** Built the life-event **milestones** timeline on the results page — a dated, aged list of *when* the
major events happen across the projection: each person retires, takes their first planned pension withdrawal, their
State Pension starts, and their modelled death. It answers Rob's "what is the 2040 event?" by making the drivers of
the cashflow ladder's step changes legible. Read-only, factual, lint-safe. Every date traces to **one source** —
DOB + the relevant age (planned retirement age; SPA from the engine's `StatePensionAge`; the earliest DC withdrawal
age) — or the engine's new single-source death year: **`ForecastResult::$deathCalendarYears`** (personId → birthYear
+ death age), computed once in `PathProjector` from the draws' death age, so "when does each person die" is no longer
buried inside the projection. Only events within the projection window show (someone already past an event has no
upcoming milestone). The **house-sale milestone is deferred** to the per-variant ladder (it is a variant transform;
the raw-household ladder does not sell).
**Why:** continues the explainer / show-your-working layer — *trust comes from explanation*. Rob's confusion (the
2040 income/spend crossover; "P2 dies in 2027") was precisely a *when* gap: the events are modelled but never shown.
The death year needed the only engine change (additive field, default `[]`, one construction site); everything else
derives from existing inputs/helpers, so the engine stays the single source. Guarded by a presenter test (the
events, their order, the retired-person exclusion) and an assertion that the death milestones ARE the engine's
`deathCalendarYears`, not a re-derivation.
[[2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)]]
**Status:** active (built, suite green; pending Rob's browser sign-off). Next: the per-strategy cashflow ladder +
the contingent-cost placement correctness fix (#1) — which also lands the house-sale milestone.

## 2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)
**Decision:** Built the first slice of the adviser-legibility workstream — the **explainer / show-your-working
layer** (the option Rob chose over starting with the correctness fix), all deterministic so it renders before any
Monte Carlo run, all factual and lint-safe:
(1) **House-sale waterfall** (`ResultPresenter::saleExplainer`): the proceeds decomposition (sale − mortgage −
selling costs − CGT = net) and where the money goes per option (sell & rent: the full net invested; sell & buy
cheaper: net − buy − SDLT − moving = surplus invested). The selling-cost **rate** is shown beside the £ figure so
an out-of-range entry is visible on screen (the real couple's 20% = £70k vs the ~2% typical). It reads the engine's
single-source `HousingProceeds` plus a new reconciled **`HousingPurchase`** value object for the buy-side surplus;
`HousingComparison::buyVariant` now reads `buyOutcome()` so that figure has one home (behaviour-preserving — the
surplus is identical, the Monte Carlo tests are unchanged-green).
(2) **Assumptions panel** (`ResultPresenter::assumptionsPanel`): the economic assumptions every figure rests on —
the blended **real** investment return (the engine's own `PortfolioAllocation::blendedRealReturn`, with the asset
mix described from the weights so the figure can't become a black box), CPI inflation, house/rent/salary growth
(each **real**, above inflation) and the investment income yield (**nominal**) — each row labelled real-vs-nominal
so the two are never confused, plus the housing-decision inputs and the set's name + sourcing.
(3) **Itemised per-year spend**: the cashflow ladder now splits each year's spend into its essential floor and the
discretionary remainder (= `spendTarget − essentialSpend`), so the spend is traceable rather than one opaque
number; the CSV carries the two new columns.
**Why:** Rob's guiding principle — *trust comes from explanation*; every headline figure must trace on screen to
its inputs. The sale waterfall makes the three compounding trust-killers (the 20% selling cost, the phantom
housing costs, the under-stated rent) self-evident, and the assumptions panel states the basis so a figure is never
unexplained. Each new figure carries a **reconciliation guard** (sale parts sum to the total; ladder split sums to
the spend) and a **real-vs-nominal labelling guard**, per the data-layer integrity rule; the displayed-figure
provenance test was extended to the two new CSV columns (panel == CSV, one figure one home). This is the
low-risk, presentation-only slice that makes the current problems visible and every later fix verifiable.
[[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]] [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active (built, suite green, Pint clean; pending Rob's in-browser visual sign-off). Next in the
workstream: the **per-strategy cashflow ladder** (the ladder still runs the raw household, so it does not yet
reflect the sale/rent legs) paired with the **contingent-cost placement** correctness fix it acts on.

## 2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)
**Decision:** From Rob's browser walkthrough of a real couple, a cost belongs **wherever the thing it depends on
lives**, and is charged **only while that thing holds** — not as a flat lifelong line in shared `expenseProfile`:
- **Housing-linked costs** (ongoing mortgage payment, service charge / ground rent, owner maintenance) belong
  with the **property / housing decision**, so selling the home removes them. They must **not** be charged in the
  *sell & rent* or *buy outright* variants, where that property is gone.
- **Status-linked costs** (e.g. commute fuel) are tagged to the status that creates them (employment) and **stop
  when it ends** (P1 retires → no commute).
- **General living costs** (food, utilities, cars, leisure, insurance) stay in spending — they are the same
  whichever housing option is chosen.

**Why:** `expenseProfile` is shared across all three housing variants (`HousingComparison::withHousing` passes it
through unchanged) and `PathProjector` charges `targetAnnualSpend()` in every variant. With mortgage + service
charge (~£22.9k/yr for the test couple) sitting in essential spending, *sell & rent* was paying a **phantom
mortgage + service charge on a flat it no longer owns, plus rent**, and *buy outright* a phantom mortgage on a
home owned outright — silently **biasing the headline buy-vs-rent comparison against selling**. This is the
single-definition / completeness rule applied to **contingent expenses**: an expense line can carry a *condition*
(while-owning / while-working / age-bounded), and the spend charged in a year must equal the sum of the lines
**active** that year — no phantom charge, no silent drop. The idea was already foreseen for the parked import work
("the mortgage ends, commuting stops, the spending smile"); this **promotes it to a core data-model concept**.
Guarded by reconciliation tests (property costs in zero post-sale years; commute zero from the retirement year).

**Also recorded this session (not bugs — verified):** the engine is deterministic (repeated runs byte-identical)
and cohort mortality is correct (median death age is conditional on current age); the dramatic swings Rob saw mid-
session were his **live input edits** — a retirement age at/below current age zeroes the salary, and a longevity
*offset* below current age floors at current age ("dies within the year"). These motivate the **legibility
workstream** in docs/PLAN.md "Adviser-legibility workstream (2026-06-29)": life-event milestones (when retire /
SPA / sale / death happen), a house-sale explainer (proceeds decomposition + where the money is invested), input-
sanity notes, and a per-option plain-English "why". The 2040 "shortfall then rapid recovery" was confirmed a
**correct** income/spend crossover (triple-locked State Pension overtaking flat real spend as a thin cash buffer
empties), not a glitch.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** agreed direction; not yet built. Sequencing: the cost-placement fix (#1) lands before the legibility
layers, which should explain *correct* numbers.

## 2026-06-29 — Results charts: spendable (excl-home) money is the default basis; the strategy comparison is over-time, not a terminal bar
**Decision:** From live browser use, the two Monte Carlo charts on the results page were reworked:
(1) **Spendable money (excl. home) is the default basis**, with an **"Include home value" toggle** (off by
default) flipping both charts and their tables. The headline cards still show both figures as text.
(2) The buy-vs-rent **comparison is now a line chart over time** — each housing strategy's **median spendable
money by calendar year**, overlaid — replacing the single terminal-wealth **bar** chart, which is gone. The
per-strategy run-out stats stay in a table beside it (a high line must never hide a high shortfall risk).
(3) The **fan chart** plots the spendable series by default and gains a £0-anchored, `forceNiceScale` y-axis
plus a `£`-abbreviating axis/tooltip formatter (attached in `charts.js`, since a JS function can't travel
through the JSON options).
(4) **Engine support:** `MonteCarlo\SimulationResult` gained a **per-year usable fan** (`usableFanChart`)
beside the per-year total `fanChart` — same `liquid + pension` definition as the cashflow ladder/burndown,
guarded by a `usable ≤ total` per-year reconciliation test; it round-trips through `SimulationResultMapper`
(empty for runs persisted before this change).
(5) **End-of-life rise is explained, not hidden** (`partials/tail-note`): the over-time lines can climb sharply
at the far right for two real reasons, verified against the engine (per-year `paths` collapses from ~1,700 to
single digits over the last decade; the median drifts up, e.g. total £1.05M→£1.22M, usable £510k→£644k): the
**sample thins** to a handful of very-long-lived futures (so the median is noisy), and a long survivor's
**guaranteed income covers their reduced spending so the pot keeps compounding**. The note states the far tail is
indicative, not precise. **Why:** the charts plotted **total wealth including the home**,
so they read as flat and near-identical — a large, illiquid house value dominates and barely moves, squashing
the spendable variation into a thin band and making stay/buy/rent look the same even though their *spendable*
paths diverge sharply (≈£437k / £609k / £660k). For a couple **not planning to sell again**, the home can't
pay day-to-day bills, so excl-home is the honest "will it last" view. A terminal bar also dropped the time
dimension Rob actually needs ("if I live to 100, which strategy keeps the most usable money?"); a line per
strategy answers that directly. Median lines are paired with the run-out table so the level-vs-risk tension
(e.g. rent's high median beside its 55% shortfall chance) stays visible, not hidden.

**Follow-ups (same review loop):**
(6) **Person ages on the axis + tables.** The calendar-year axis and both chart tables now show each person's
age that year (age = `calendarYear − birthYear`, exactly the engine's `YearResult::ages` = baseAge + yearIndex,
reconciled to the cashflow ladder in a test — one age definition). Small, but it makes "when" legible. The axis
formatter lives in `charts.js` (a two-line label: year, then "age 82 / 84"), since a JS function can't travel
through the JSON options.
(7) **Stale-run prompt, not silent fallback.** A run computed *before* this change has no `usableFanChart`, so
the spendable view falls back to total. Rather than silently drawing total wealth as if it were spendable (which
read as "the toggle does nothing / the title is stuck on Total wealth"), the page now shows a neutral re-run
prompt driven by a `usableFanAvailable` flag — no silent failure. Existing runs must be re-run to get the
spendable view.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active (built, green, and signed off by Rob in the browser on 2026-06-29).

## 2026-06-28 — Statement-driven onboarding + document import: deterministic core, LLM only as a walled-off assist (PARKED)
**Decision:** A planned post-v1 feature is designed and recorded before building: the wizard will
**ingest uploaded documents** (bank statements, credit-card statements, payslips, benefit/State-Pension
statements), **pre-fill** every extractable field, and **ask only the remainder**, building the budget from
the household's **actual** spending rather than "average user" national figures. Design + sector evidence:
[docs/RESEARCH-document-import.md](research/RESEARCH-document-import.md); plan entry: docs/PLAN.md
"Statement-driven onboarding + document import". The load-bearing calls:
(1) **Transfer-matching is deterministic-only.** Rob's £1,258 case — a card-payment credit matched by an
equal-and-opposite current-account debit — is an **internal transfer** and must be **excluded from spend**,
matched by rules (opposite sign, equal pence, date window), **user-confirmed**, with a reconciliation
invariant + a real-file golden fixture carrying a known transfer pair. An LLM is the **wrong tool** here
(non-deterministic, unreliable arithmetic, non-auditable). Dedup uses a stable `imported_id`.
(2) **Categorisation is rules-first; an LLM is an optional, walled-off, LOCAL-only assist** for the long
tail of unknown merchants (a merchant-map + string rules cover 60–80% at perfect accuracy). A mis-tier
moves a pound between tiers but **never changes the grand total** (completeness holds); statement data
**never leaves the machine**.
(3) **Documents pre-fill different builder sections** (bank/CC → expense lines + recurring income +
transfers; payslip → gross salary / pension contributions / NI / tax code; benefit statement →
`IncomeStream` with **taxable vs tax-free** classified), extending the existing `PayAndExpenditures` mapping.
(4) **Actuals = the input baseline; PLSA stays the benchmark, not the input** — imported spend is *today's*
cost; the wizard marks which lines continue into retirement and the forecast adjusts.
(5) **Architecture:** an extension of `app/Import/` (a statement profile family →
`ImportResult::expenseLines` + `reconciliation`), app-layer only (engine stays dependency-free), writing
`builder_state.expenseLines` (the existing single source of truth). **Open Banking (regulated AISP, online)
is out of scope** for the local-first v1; file import (CSV/OFX/QIF; PDF+OCR a flagged sub-phase) is the path.
**Why:** this is the correct framing of Rob's "could a local Ollama AI do the forecasting?" question — the
answer being that a model has **no place in the trusted numeric path** (it would break HMRC-to-the-penny,
reproducibility, sourcing and no-silent-failure), but **wrangling and explaining** imported documents is a
genuine fit, and the **privacy** argument (sensitive bank data, local-only) is strong for *this* feature
specifically. The transfer-matcher is kept deterministic because the £1,258 double-count is precisely the
inconsistent-aggregation bug class the project was burned by — and the tax-free classification on benefit
statements is the completeness sibling (the DLA bug). Each phase delivers value alone; the model is the last,
optional layer, so the whole feature lands without any AI at all.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]] [[2026-06-25 — Forecast income completeness: count every source, no silent drop]] [[2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved]] [[2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook]]
**Status:** active (design decision recorded; the feature itself is **parked, post-v1** — not started).

## 2026-06-28 — Engine: income-tax thresholds un-freeze after freezeEndYear (homogeneity, not config rebuild)
**Decision:** The forecast now models UK income-tax thresholds **un-freezing** after
`ForecastSettings::$freezeEndYear` (April 2031): frozen until then, indexed with inflation afterwards. It is
implemented in `PathProjector` via the income-tax function's **degree-1 homogeneity** in (income, all of its
monetary thresholds): post-freeze, `indexedTotalPence()` taxes income **deflated** to the freeze-end price level
against the frozen base-year thresholds and **re-inflates** the result — mathematically equal to taxing under the
inflated thresholds, but without rebuilding the band config per year per path in the 10k-path hot loop. The
threshold factor is `1.0` during the freeze (and for any caller passing the default), so the HMRC worked-example
unit tests and all freeze-period years are the **exact identity** (unchanged). The factor is threaded through both
tax call sites — the main per-person pass and the drawdown grossing-up (`marginalTax`/`grossUpPension`) — so the
tax paid and the withdrawal sizing share one basis.
**Why:** previously the projector kept thresholds frozen for the *whole* projection, which **overstated post-2031
fiscal drag** on every retirement-length forecast, and `ForecastSettings::$freezeEndYear` was documented-but-dead
(its docblock contradicted the projector's). This was finding #2 of the 2026-06-28 re-review; Rob chose to
**implement** rather than just document the conservative bias. The homogeneity approach was chosen over rebuilding a
scaled `TaxYearConfig` per year because the latter would allocate config objects in the hot loop — the very cost the
recent `totalPence()` perf work removed — whereas homogeneity is pure arithmetic on the income before the existing
lean tax call, at a penny-level rounding cost that is immaterial in a multi-year forecast (and zero during the
freeze). **Verification:** `ThresholdFreezeTest` pins identical tax within the freeze window, strictly lower tax
after it, and lower cumulative drag overall; the Monte Carlo tests are a determinism check (no committed percentile
snapshot), so nothing needed regenerating. The deflate→tax→re-inflate path rounds income by the factor, so it is a
deliberate (tiny) approximation versus exact scaled thresholds — acceptable because the HMRC-exact paths use factor
1.0 and the projection is already nominal/real with rounding throughout.
**Status:** active

## 2026-06-28 — Phase D Tier-2: a11y verification sweep — 3 real contrast fixes + the toolchain reality
**Decision:** A local accessibility sweep was run (build + `php artisan serve` + axe). It **fixed three genuine
WCAG AA contrast failures**: `text-gray-400` on the builder *Discard* button and the dashboard *what-if* label
(≈2.9:1, below the 4.5:1 floor) → `text-gray-600`, and a `text-gray-300` Compare separator (≈1.6:1) →
`text-gray-500` + `aria-hidden`. On the **tooling**, the scaffolded Pa11y CI is downgraded to a coarse CI-only
regression smoke, and the **authoritative a11y check is in-browser axe DevTools / Lighthouse** (documented in
docs/A11Y.md). The `runners` were set to **axe only** (HTML CodeSniffer crashes — `checkControlGroups` — under
current Chrome), the diagnostic `@axe-core/cli` dep was removed, and the config was trimmed to the verified-working
URLs (public pages + `/welcome`).
**Why:** two hard environment facts surfaced. (1) npm here runs with **`ignore-scripts=true`**, so no headless
browser binary (puppeteer Chromium, chromedriver) ever downloads — no headless a11y runner can fetch a browser
locally (CI on Linux is unaffected). (2) Pa11y CI 3.1.0 pins pa11y 6 → **axe-core 4.2 (2021)**, which emitted a
**false positive** here (a contextless "color-contrast" error on the public pages, which carry no sub-AA text — a
real axe violation always names the offending node). A gate that cries wolf is worse than none, so the trustworthy
current-axe DevTools pass is made authoritative and Pa11y CI is kept only as a self-contained CI smoke. The three
contrast fixes were found by static review of the colour classes (trustworthy, tool-independent) and are real
regardless of tooling.
**Status:** active

## 2026-06-28 — Phase D Tier-2: accessibility CI — Pa11y CI (axe + HTMLCS), scaffolded
**Decision:** The a11y gate is automated with **Pa11y CI** running **axe-core + HTML CodeSniffer** against
**WCAG2AA** over the rendered pages. The page list + login scripting live in `.pa11yci.json` (public pages run
with no setup; the authed shell pages — `/welcome`, `/dashboard`, `/scenarios/create` — are reached by scripting a
login + disclaimer acknowledgement with the seeded **demo** account). `pa11y-ci` is a devDependency with an
`npm run a11y` script; `.github/workflows/a11y.yml` runs the same sweep on push/PR (dormant until the repo has a
GitHub remote, since it is local-first today); `docs/A11Y.md` documents the local run + how to extend coverage.
**Why:** accessibility is a hard project constraint (every figure also rendered as text + an accessible table, skip
link, landmarks, `aria-*` on forms), so it deserves a machine guard, not just discipline. Pa11y CI with both
engines is the standard headless WCAG checker and reuses the demo seeder for authed coverage. **Honesty caveat:**
this is **scaffolded, not yet run green** in this environment — it needs a headless Chrome + the served app, which
is the real-browser verification pass this work always required; the config/workflow are correct-by-construction
and documented as unrun. The rendered ApexCharts canvases stay a manual real-browser check (only the accessible
tables/text are machine-checkable). **Status:** active

## 2026-06-28 — Phase D Tier-2: PDF results export — dompdf reusing the on-screen presenter
**Decision:** A scenario's results are downloadable as a PDF via `App\Http\Controllers\ScenarioPdfController`
(`GET /scenarios/{scenario}/results/pdf`, owner-scoped, inside the disclaimer-acknowledged group; a draft 404s),
rendering `resources/views/pdf/results.blade.php` with **`barryvdh/laravel-dompdf`** (pure-PHP dompdf — no headless
browser or binary, app-layer only so the engine stays dependency-free). The report is built from the **same
`ResultPresenter`** the on-screen page uses (lump-sum tax shock, income floor, 3-tier budget, PLSA benchmark, the
cashflow ladder, and — if a completed run exists — the Monte Carlo headline summary), so the print **cannot drift**
from the screen (the displayed-figure provenance rule). The data assembly is a public `data()` method so the
view-render test exercises the exact data the controller produces. The PDF carries the guidance-only disclaimer +
signposting (and passes the banned-phrasing partition lint). The full per-year income-by-source split stays in the
CSV export; the PDF shows the wealth trajectory (tax/spend/usable/total), keeping the table portrait-friendly and
every column a presenter-provided string (no in-view derivation).
**Why:** a shareable/printable summary is a standard go-live want, and dompdf is the lightest way to get one that
is fully testable headlessly (the route streams a real `%PDF`, the view renders the figures + disclaimer). Reusing
the presenter rather than re-deriving figures is mandatory under the data-integrity rules. **Residual:** a
real-browser/PDF-viewer eyeball of layout fidelity (the figures + structure are tested; the visual rendering is
not). Suite 348 → 353 green. **Status:** active

## 2026-06-28 — Phase D Tier-2: two-factor enrolment UI — a Livewire page driving Fortify's actions
**Decision:** Two-factor authentication enrolment is delivered as a full-page Livewire component,
`App\Livewire\AccountSecurity` (at `/account/security`), that drives **Fortify's own actions**
(`EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`,
`DisableTwoFactorAuthentication`) directly rather than posting to Fortify's HTTP endpoints, so enrolment is
one fluid page (turn on → scan QR / type setup key → confirm a code → recovery codes shown; regenerate; turn
off). The `User` model gains Fortify's `TwoFactorAuthenticatable` trait (the 2FA columns were already
migrated); `two_factor_secret`/`two_factor_recovery_codes` are stored encrypted and read raw by the trait, so
they are **not** given an Eloquent `encrypted` cast (that would double-encrypt) and are added to the model's
`$hidden`. `FortifyServiceProvider` now wires the two previously-missing views: the login **two-factor
challenge** and the **password-confirmation** screen. Because the component calls the actions directly (not the
endpoints that carry Fortify's `confirmPassword` middleware), the page **route** is placed behind the
`password.confirm` middleware — a "sudo" step that is the equivalent protection for the direct-action approach.
The security page sits **outside** the guidance-only disclaimer gate (like the GDPR controls): account
management is not withheld pending acceptance of the forecast framing. A "Security" link is added to the authed
nav.
**Why:** Fortify's 2FA feature was enabled (`config/fortify.php`, with `confirm` + `confirmPassword`) and the
columns migrated, but no screens existed, so no user could enrol — a real shipped-surface security gap, and one
that matters for the possible public release. Calling the actions from Livewire (the Jetstream pattern) gives a
clean single-page UX and is cleanly testable headlessly; the QR/recovery-code/TOTP machinery is all provided by
the already-installed `pragmarx/google2fa` + `bacon/qr-code`. Chosen as a Tier-2 item because the whole flow is
verifiable without a browser: `TwoFactorAuthenticationTest` enables + confirms with a computed current TOTP,
rejects a wrong code, regenerates recovery codes, disables, drives the full login challenge to completion, and
asserts the security page demands a confirmed password. **Test gotcha recorded:** Fortify rejects reuse of a
TOTP within its window, so a test that both confirms enrolment and later completes a login challenge must not
spend the same current code twice — the enrol helper stamps `two_factor_confirmed_at` directly instead of
burning a code. **Residual:** a real-browser eyeball that the QR renders and an authenticator app round-trips
(the SVG + flow are headless-tested, the visual scan is not). Suite 340 → 348 green.
**Status:** active

## 2026-06-28 — Phase D Tier-2: security headers — a compatible-by-construction CSP on the web group
**Decision:** A new `App\Http\Middleware\SecurityHeaders` (appended to the `web` group in `bootstrap/app.php`)
sets a **Content-Security-Policy** plus a small set of static hardening headers on every response of the public
surface (landing, Fortify auth screens, the Livewire forecast UI). The policy and its toggles live in one home,
`config/security.php`, so the test asserts against the same definition the middleware reads. The CSP is
**compatible-by-construction** with the current self-hosted stack: `default-src 'self'`, self-hosted Vite bundle +
Bunny fonts (`font-src 'self'`, `img-src 'self' data:`, `connect-src 'self'`), with `script-src`/`style-src` keeping
`'unsafe-inline'`/`'unsafe-eval'` because Livewire injects an inline init script, Alpine evaluates expressions via the
Function constructor and ApexCharts injects inline styles. The high-value structural directives are enforced
regardless of inline handling: `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`.
The static headers are `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin` and a restrictive `Permissions-Policy`. Two env toggles:
`SECURITY_HEADERS_ENABLED` (master switch) and `SECURITY_CSP_REPORT_ONLY` (send `Content-Security-Policy-Report-Only`
to stage a rollout). The Filament `/admin` panel is **deliberately out of scope** — it runs its own middleware stack
(not the `web` group), manages its own asset loading (incl. its own font source) and is admin-only.
**Why:** a CSP + hardening headers are a standard go-live requirement. The policy is shipped enforcing (not
report-only) because it is permissive exactly where this self-hosted stack needs it, so breakage risk is low while
the structural protections are real. Tightening `script-src`/`style-src` to nonce-based (dropping `'unsafe-inline'`
and `'unsafe-eval'`) needs Alpine's CSP build and a real-browser verification pass, so it is left as the residual
go-live item rather than shipped untested. Applying only to the `web` group keeps Filament's own CSP/asset story
intact (a `web`-group CSP with `font-src 'self'` would otherwise break Filament's default Bunny-hosted font). Chosen
as the next Tier-2 item because the server-side policy + toggles are fully verifiable headlessly (the only
browser-dependent part — confirming ApexCharts/Livewire still render under the CSP — is the documented eyeball).
Tested by `SecurityHeadersTest` (header present on web + auth routes, structural + stack directives, exact match to
config, static headers, report-only swap, disabled-switch). Suite 332 → 340 green.
**Status:** active

## 2026-06-28 — Phase D Tier-2: Monte Carlo 10k-path perf — a lean integer tax twin + JIT for the worker
**Decision:** The 10k-path run is sped up two ways, both proven to leave results byte-identical. (1) **In-repo:**
profiling showed `PathProjector::project()` is **93%** of per-path time and within it the income-tax calculator
dominates, yet every projector tax call reads only `->total->pence`. So `IncomeTaxCalculator` now exposes a lean
`totalPence(TaxableIncome): int` that shares the **same private band core** (`bandedTax()`) as `compute()` but skips
the `Money`/`lines` decoration the hot loop discards — one computation, two presentations. The allowance taper also
moved to an integer home (`grantedAllowancePence()`) that the public `personalAllowance()` Money method now delegates
to. The projector's main per-person pass and `marginalTax()` route through `totalPence()`. (2) **Deployment:** the
queue worker that runs the full simulation should start PHP with **OPcache JIT enabled** (it is off by default on
this machine: `opcache.enable_cli=0`, `opcache.jit=disable`) — see How to pick up for the exact flags.
**Why:** the engine is the product and the 10k run is its slowest path; tuning it is the Tier-2 perf item. The
calculator is trust-critical, so the rule was *no behaviour change* — the integer per-slice rounding mirrors
`Money::applyRate` exactly, and a new `IncomeTaxTotalPenceTest` pins `totalPence($i) === compute($i)->total->pence`
across a 1,120-cell grid (every band crossing, taper window, PSA tier, dividend allowance, both tax years), so the
two presentations can never silently diverge — the one-definition-one-home rule applied to a perf split. The rich
`compute()` result is consumed only by the composite test (production reads only the total), so the blast radius is
small. **Measured** (the `comfortable` MC couple, 10k paths): interpreted **13.9 s → 8.9 s** (1.57×) from the
refactor alone; with function-mode JIT **→ 4.75 s** (2.9× vs the original), the leaner allocation profile compounding
with JIT. Memory unchanged (~16 MB). JIT is a *startup* setting, so it is surfaced as a documented worker invocation
rather than silently written into Rob's global Herd `php.ini`.
**Status:** active

## 2026-06-28 — Phase D Tier-2: demo preset is an opt-in, production-safe seeder over the canonical shape
**Decision:** The demo "preset" the plan owes at step 5 is delivered as a seeder, not a hardcoded record or a
separate sample format. `App\Demo\DemoScenario` is the one home for an obviously-fictional sample plan expressed
in the **canonical `builder_state` shape**, so it assembles to the engine DTOs and runs exactly like a user-built
scenario (no parallel representation that could drift). `Database\Seeders\DemoScenarioSeeder` persists it as a
**base plan + one delta-child what-if** ("retire two years earlier"), the child derived via `BuilderStateDelta::diff`
so it stores only the override and the base stays the single source. It is **opt-in** (not wired into
`DatabaseSeeder`, so it never fires in the normal dev/test seed), **idempotent** (matched by owner + name, drops
stale runs on re-seed), and **release-safe**: outside production it provisions a fictional `demo@example.com` /
`password` account; in production it **refuses** to mint a default-credential account unless `DEMO_USER_EMAIL`
names an existing user (loud `RuntimeException`).
**Why:** the locked decisions require any first-run sample to be obviously fictional and forbid client data in the
repo, and "do not design accounts out, just defer them" leaves possible public release on the table — so a demo
account must never ship default credentials by accident. Building the demo on the canonical shape makes it double
as a living end-to-end integration smoke (assemble → forecast → results), the highest-confidence way to keep the
sample honest. Chosen as the first Tier-2 item because it is fully verifiable headlessly (CSP + a11y CI both need
real-browser eyeballing).
**Status:** active

## 2026-06-28 — Phase D Tier-1: import reconciliation surfaced to the user (the panel, completing Tier-1)
**Decision:** The data-layer integrity rule's "surface every imported/aggregated total; a mismatch must be a
*visible* failure, not a silent one" is now enforced at the **user-facing** layer, not only in tests. A new
`App\Import\ReconciliationLine` value object pairs the figure that went **into the form** (`imported`) with the
sheet's **own independent figure** for the same quantity (`stated`, nullable) — a TOTAL row, or the sum of the
line items the importer did not take as primary. Equality is judged in **exact pence** (`reconciles()` /
`mismatch()`), so formatting can neither mask nor invent a divergence; `stated = null` means the layout offers no
second figure, so the value is surfaced for eyeball review and never reported as a mismatch. `ImportResult` carries
`reconciliation: list<ReconciliationLine>`, the three calibrated profiles emit it (`PayAndExpenditures` captures
the sheet's own Total row it previously discarded; `ConsciousSpendingPlan` reconciles each bucket's stated `… TOTAL`
against its line-item sum; `RetireForecastTemplate` surfaces each category with `stated = null`), and the Blade
import panel renders each pair, turning red + `role=alert` on any divergence.
**Why:** the importers already *resolve* discrepancies internally (CSP trusts the stated TOTAL; PayExp uses the
summed lines) but the user never saw that a second figure existed or whether the two agreed — exactly the
silent-aggregation blind spot that burned a past project. Showing both figures side by side makes the resolution
auditable before the user saves. The test layer enforced reconciliation; the UI did not, so this closes the gap.
**One latent correctness fix fell out:** to make the CSP line-item sum a *faithful* cross-check, the parser now
skips the `NET WORTH`/`INCOME` sections (their Investments/Savings rows shared the bucket keywords and inflated the
contributions sum). No imported figure changes — the stated TOTAL stays authoritative — but a CSP file lacking
bucket TOTAL rows would no longer mis-import balance-sheet assets as monthly contributions.
**Status:** done — this is the **last Tier-1 (trust) item**, so Tier-1 is COMPLETE. Suite 309 → **320 green / 1626
assertions** (app 172 → 183; engine 137); pint clean. Proof the failure is visible, not silent: a deliberately
-inconsistent golden fixture (`csp-inconsistent-bucket-total`, a £9,999/mo TOTAL vs £3,000/mo of line items) plus
its Livewire twin assert the panel flags it. [[2026-06-25 — Data-layer integrity: one definition, one home + reconciliation/completeness tests]]

## 2026-06-27 — Phase D: admin-panel access gated on an is_admin flag (go-live lockdown)
**Decision:** `User::canAccessPanel()` no longer returns `true` for every authenticated user; it is gated on a
new **`is_admin`** boolean (migration, default false, cast on the model). The first admin is bootstrapped from
the CLI (`php artisan user:make-admin {email}`, `--revoke` to undo); once one exists, admins toggle others via an
**Admin access** `ToggleColumn` on the Filament Users resource. A non-admin hitting `/admin` gets a 403.
**Why:** the advice-style `interpret` capability is admin-granted from inside the panel, so "any authenticated
user can reach the panel" was a privilege-escalation path: a public user could self-grant `can_interpret` and turn
on directive, advice-style output — exactly the regulatory line the compliance layer exists to hold. Admin access
must therefore be the *tighter* gate, set out-of-band (CLI), not self-serve. A flag beats an email allowlist
because the existing `UserResource` already manages per-user capabilities; `is_admin` sits beside `can_interpret`
as one more admin-managed boolean. The CLI command follows the no-silent-failure rule (unknown email fails loudly;
an already-correct state is a reported no-op). [[2026-06-25 — Compliance: directive-only lint + partition test + interpretation toggle]]
**Status:** done. Suite 298 green (app 164 → 169: a non-admin-403 test + a 4-case command test; the three existing
panel tests moved to an `admin()` factory state). **Local migration note:** existing DBs need `php artisan migrate`
then `php artisan user:make-admin {email}` to restore admin access.

## 2026-06-27 — Phase D: gov.uk figure-verification pass completed (Tier-1 trust gate)
**Decision:** Ran the build-time **figure-verification pass** the plan required before any figure is "shown as
real". Every statutory figure carrying a ⚠️ marker was re-confirmed against gov.uk on 2026-06-27 and its
`verified_on` / `VERIFIED_ON` stamp moved to 2026-06-27; the ⚠️ docblocks were rewritten to record the specific
confirmation + source. **No figure value changed — every one was already correct.** Confirmed:
- **Income tax / NI / dividends / savings** (PA £12,570, 20/40/45, taper £100k→£125,140 frozen to Apr 2031; NI
  8%/2% on £12,570–£50,270; **dividends 26/27 = 10.75/35.75/39.35** + £500 allowance; PSA + starting-rate band).
- **Pensions:** LSA £268,275, LSDBA £1,073,100, AA £60k, MPAA £10k, tapered-AA £200k/£260k + £10k floor; NMPA
  55 → **57 on 6 Apr 2028**.
- **State Pension** new SP £241.30/wk (26/27) + **SPA 66→67 over DOB 6 Apr 1960–5 Mar 1961** (Pensions Act 2014).
- **CGT** residential 18%/24% + **£3,000 AEA**; final 9 months always relieved + lettings relief shared-occupancy-only (HS283).
- **SDLT** bands 0/2/5/10/12 + **5% surcharge**; **benefits** £10k disregard / £1-per-£500/wk / £16k HB cut-off;
  **care** £23,250/£14,250 + £1-per-£250/wk.
- **IHT** £325k NRB (frozen to 5 Apr 2031), £175k RNRB (frozen to 5 Apr 2030), £2m taper, 40% — and the
  **April-2027 unused-pensions-in-estate change is now ENACTED** (Finance Act 2026, Royal Assent 18 Mar 2026,
  deaths on/after 6 Apr 2027), upgraded from "proposed"; stays behind the toggle.
- **PLSA Retirement Living Standards:** all 12 figures match the published 2026 table **exactly**.
- **`investmentIncomeYield` (2%):** reviewed and kept, but reclassified in the docblocks as a **modelling
  assumption, not a statutory figure** (anchored to the global-equity dividend yield ~1.3–2%); it is not
  gov.uk-verifiable, so it carries a "reviewed 2026-06-27" note rather than a verified-against-gov.uk claim.
**Out of v1 scope, deliberately NOT verified** (the region resolver throws rather than guessing): **Scottish
income-tax bands** and **LBTT/LTT** (Welsh/Scottish property taxes). The FCA/DMS/ONS *assumption-source*
sign-off (docs/ASSUMPTIONS.md, docs/MORTALITY.md) stays at its 2026-06-24 sign-off — it is a separate academic/
regulatory review, not part of this gov.uk statutory-figure pass.
**Coupled tests updated** (the pass changed provenance dates, not figures): the PLSA `VERIFIED_ON`, the
benchmark readout `verifiedOn`, the Filament audit "Verified …" string, and the `taxyear_config_version`
fixtures (it is the config's `verifiedOn`, SimulationRunner.php:39) all moved 2026-06-26/24 → 2026-06-27.
**Why:** Rob's hard rule is no figure shown as real without a sourced, dated confirmation. A re-verification
that finds everything already correct is the *expected good outcome* — it converts "believed right" into
"checked right on a known date", and catches the one thing that did move (pensions-in-IHT is now law, not a
proposal). Per the data-integrity rule, the stamp is the audit trail.
**Status:** done. Suite 293 green (no value changed, only provenance + 4 coupled date assertions). **Next: Phase
D go-live polish** (a11y CI, CSP header, `canAccessPanel()` lockdown, perf, PDF, 2FA UI).

## 2026-06-27 — A5: how GIA/cash tax is modelled (income paid out + taxed; capital → CGT on disposal)
**Decision:** Phase D started with **A5** (GIA/cash income tax + CGT-on-disposal), the modelling deferred from
the rebuild. Rob chose the **full** scope (annual income tax AND CGT on disposal). The modelling, decided to
avoid the double-count that caused the deferral:
(1) A GIA's/cash's **total return is split into income + capital growth**. The income (cash interest as savings,
GIA dividends as dividend income) is **paid out to net cash and taxed each year** via the existing combined
income-tax pass (PSA + dividend allowance stacking); the asset then **grows at capital only** (total return
minus the income yield). So income paid out + capital growth == total return, **never double-counted** — the
exact failure mode that made shipping this hastily a trust bug. ISA stays tax-free and reinvests at total
return. Conservation is asserted by a test (the taxed, capital-only GIA can never out-grow an equal tax-free
ISA).
(2) The income yield is a **new sourced figure** `AssumptionSet::$investmentIncomeYield` (nominal, **2.0%**,
uniform across the three sets for v1), anchored to the global-equity dividend yield (FTSE All-World ~1.3-2%).
⚠️ flagged for the go-live figure-verification pass (read 2026-06-27, like the PLSA figures). Per-account
`Account::$yield` overrides are reserved for a later refinement (balances are aggregated per person in the
projector, so honouring per-account yields needs de-aggregation).
(3) **Capital gains → CGT only on disposal** (the next A5 step): when a GIA is drawn to fund a shortfall the
pro-rata gain is realised and taxed (shared £3k AEA, 18/24% by band — reusing `CgtParameters`, whose residential
rates have equalled the share-gain rates since the Oct-2024 Budget). Basis is tracked through contributions and
disposals; losses are not relieved in v1.
[[2026-06-25 — Rebuild: keep the engine, rebuild storage to the new world; ratify LW4+SQLite; defer GIA/CGT]]
**Why:** This is the trust pass: an unwrapped holding must carry its real tax drag (income tax + CGT on
disposal), and the income/capital split is the only way to add it without taxing the same return twice. The new
yield is sourced + verified-flagged like every other external figure (no magic numbers); the conservation
invariant is guarded by a test (no silent double count), as is CGT incidence (a gainful GIA is taxed where a
no-gain one is not).
**Status:** done (income side committed at `937413b`; CGT-on-disposal + basis tracking complete — GIA gains
realised pro-rata on drawdown, shared £3k AEA, 18/24% by band, reusing `CgtParameters`; v1 omits loss relief
and judges the CGT band on non-savings income, both flagged). A5 fully closed; GIA/CGT no longer deferred.

## 2026-06-26 — C4: PLSA Retirement Living Standards benchmark (placement, basis) + engine-isolation guard
**Decision:** Built the **PLSA Retirement Living Standards benchmark** (the one remaining C1-list item, the
core of C4) and added an **engine-isolation guard test**. Calls made:
(1) **The sourced figures live in the engine** (`RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards`
+ `RetirementLivingStandardsResult`), framework-free and golden-master tested, alongside the other sourced
reference data (tax config, assumption sets, mortality) — they carry `SOURCE` + `EDITION` + `VERIFIED_ON`
(read 2026-06-26 from retirementlivingstandards.org.uk) per the "no magic numbers" rule. **⚠️ flagged for the
go-live figure-verification pass** because they were read via an automated WebFetch, not yet eyeballed against
the published table.
(2) **The comparison is put on the PLSA basis** (PLSA's own definition: excludes rent + mortgage — assumes the
home is owned outright — but *includes* everyday home running costs). So comparable spend = the household's
**lifestyle spend** (`ExpenseProfile::targetAnnualSpend()` = essential + discretionary, already excluding the
*saved* self-investment) **plus owned-home running costs**, with rent excluded by construction (rent lives in
`HousingAction`, not the `Household`). This reuses the *same* `ExpenseProfile` the forecast runs on, so the
benchmarked figure cannot drift from the projection (data-integrity reconciliation; tested in
`PlsaBenchmarkTest`). Presentation (composition single/couple, the housing-leg adjustment, wording) lives in
`ResultPresenter::plsaBenchmark()`; the engine stays neutral facts only.
(3) **London is not modelled as a region**, so the (lower) **outside-London** figures are used and the higher
London cut is flagged in the readout caveat. (4) **Wording stays neutral** ("reaches the Moderate standard",
"a general yardstick, not a recommendation") — passes the `OutputPhrasing` partition lint.
(5) **Added `EngineIsolationTest`** (engine test suite) that scans `packages/finance-engine/src` for any
`use App\…` / `use Illuminate\…` import. **Prompted by a real near-miss this session:** Pint's
`fully_qualified_strict_types` fixer turned a `{@see ResultPresenter::…}` docblock cross-reference into a real
`use App\Forecast\ResultPresenter;` import in the engine — a silent breach of the framework-free boundary that
no test would otherwise have caught. The cross-reference was removed; the guard now makes any future breach a
visible failure. [[2026-06-26 — C1 fast-follow: income-floor definition, importer line population, longevity lever scope]]
**Why:** A recognised external benchmark is exactly the kind of "no magic numbers" figure the project requires
sourced + verified, and exactly the kind of aggregation that must reconcile to the forecast it sits beside (not
a second, drifting definition of "spend"). The engine-isolation guard closes a hard-rule gap (CLAUDE.md: the
engine must never `use App\…`/`Illuminate\…`) that had no automated enforcement — the same "loud guard, no
silent failure" discipline applied to the trust boundary itself.
**Status:** active

## 2026-06-26 — C1 fast-follow: income-floor definition, importer line population, longevity lever scope
**Decision:** Three design calls while building the Phase C1 fast-follow (results 3-tier display + income-floor
readout + importer line-population + the per-person longevity builder lever). (1) **Income-floor "secure
income" = DB pension + State Pension + annuity/other + tax-free income**, measured at the **last year everyone
is still alive** (the mature floor, by when every guaranteed source is in payment and salary has ended). It is
deliberately the *complement* of the pot-dependent sources (salary, pension lump sum, pension drawdown, asset
drawdown), and **tax-free income (DLA-type) is included** — excluding it would repeat the exact completeness
bug the project was burned by. Essential spending it is compared against is the new **`YearResult::essentialSpend`**
(real terms, incl. rent / property running costs), surfaced from the figure the projector already computes, so
the readout and the cashflow ladder read one definition, not a re-derivation. The readout reports coverage as a
fact (a %, a surplus or a gap met from savings) and never says whether it is *enough* (no recommendation).
(2) **Importers emit per-line `expenseLines` where the source supports it, but CSP stays one line per bucket.**
RetireForecast (per-row) and PayAndExpenditures (per-outgoing) carry real line items with their labels; the IWT
CSP importer emits **one line per bucket** using the bucket's authoritative "… TOTAL" rather than re-expanding
the bucket into its items — re-expansion would re-risk the per-bucket-TOTAL double-count the reconciliation
guard exists to catch. The flat `expense` total is kept as the reconciliation anchor and the gotcha-A guard now
also asserts the line sums reconcile to it. (3) **The builder longevity lever exposes peer / fixed_age /
offset_years only** — the engine's `mortality_multiplier` mode stays engine-side (too technical for the form in
v1). The two form fields (`longevityMode`+`longevityValue`) ride the existing C2 delta, so a child what-if
overrides lifespan for free; an end-to-end completeness test proves the form lever reaches and moves the
forecast. [[2026-06-25 — Engine enrichments for the new world (contributions, longevity, usable wealth, income-by-source)]]
**Why:** Each respects the data-layer integrity rule (one definition per quantity; completeness — no input
silently dropped, no figure able to drift from its source) without over-reaching into modelling that needs the
trust pass (phased spend, GIA/CGT) or a C4 feature (PLSA benchmark).
**Status:** active

## 2026-06-25 — Rebuild: keep the engine, rebuild storage to the new world; ratify LW4+SQLite; defer GIA/CGT
**Decision:** Rob authorised a clean rebuild treating the existing code as a prototype, with a key
liberation: **no existing user data, DB layout or data shape must be preserved** — build storage to match
the new world directly. Concretely: (1) **Keep the framework-free engine** (penny-accurate, now 113 tests)
and the sound app code; **rebuild the data/storage layer freely** (no migration, no backward-compat — this
removes gotcha G). (2) **Ratify the real stack** — Livewire 4 + Filament 5 + SQLite + db/sync queue — over
the plan's stale Livewire 3 + Redis/Horizon (reverting LW4→3 would fight Filament 5 for no gain). (3)
**Interleave trust fixes with features** rather than sequencing (Rob: "not too worried about first focus…
build to a natural slightly beyond MVP"). (4) **Defer the GIA/cash income-tax + CGT-on-disposal modelling
(A5) to the trust pass (Phase D):** the projector grows GIA/cash at the assumption set's *total* real
return, so taxing a yield on top would **double-count returns** — it must be done by decomposing total
return into a taxed income yield + deferred capital growth (CGT on disposal with AEA + rates), alongside the
gov.uk figure verification. Shipping it hastily would itself be a trust bug.
**Why:** The engine is the trustworthy, costly-to-recreate asset; the prototype builder/storage was always
disposable (it existed to get a usable app for feedback). Freeing the rebuild from data migration lets the
new shape (builder_state source of truth, delta children, line items, account contributions, longevity) be
built cleanly instead of bolted on. The prototype is preserved at tag **`prototype-v1`** (commit a8f1f68)
for recovery (no remote). [[2026-06-25 — Scenario model: base plan + delta what-if children + compare]]
**Status:** active

## 2026-06-25 — Engine enrichments for the new world (contributions, longevity, usable wealth, income-by-source)
**Decision:** Built the engine capabilities the sector-informed rebuild needs (Phase A), each golden-master /
reconciliation tested, all additive and backward-compatible: (1) **ongoing contributions** on `Account` (new
field) and DC pensions (the DTO already carried `ongoingContribution`/`employerContribution` but the projector
ignored them) — funded from surplus only, so saving stops once the household is in net drawdown; (2) a
per-person **`LongevityAdjustment`** (peer / fixed age / ±years / mortality multiplier) feeding both the
deterministic representative death age and the Monte Carlo sampler (q(x) multiplier on the cohort table); (3)
**terminal usable wealth** (excl. home) reported alongside total on `ForecastResult`/`SimulationResult` (fixes
the asset-rich / cash-poor "wealth left" paradox, gotcha P) at the engine boundary; (4)
**`YearResult::incomeBySource`** — every year's inflows split across the canonical sources (salary, DB, State
Pension, annuity/other, tax-free, pension lump sum, pension drawdown, savings drawn), powering the cashflow
ladder and the per-source completeness guard (gotcha Q).
**Why:** These are the prerequisites the new-world features (line items, drill-down, lifespan/contribution
what-ifs, honest wealth reporting) consume; building them first keeps the engine the single source of truth
and lets the app layer be rebuilt against a stable, tested surface. v1 simplification flagged: pension
contributions are funded from net surplus with no tax relief modelled (slightly understates the pre-retirement
pot), to revisit in the trust pass. [[2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved]] [[2026-06-25 — Per-person longevity / health adjustment (new engine input)]]
**Status:** active

## 2026-06-25 — Forecast income completeness: count every source, no silent drop
**Decision:** The forecast must count **every** income source that should reach a household's spendable
cash, and a regression test guards each one. Found via live use: `PathProjector::incomeStreamsNominal`
summed only **taxable** streams and the tax-free branch was never added anywhere, so **DLA / any tax-free
income was silently dropped** — understating income and overstating the chance of running out. Fixed
(tax-free streams counted untaxed into net cash) + a regression test. The durable guard is a **per-source
completeness test** (salary, DB, State Pension, taxable + tax-free income streams, DC withdrawals, asset
drawdown each demonstrably contribute).
**Why:** This is the **completeness** sibling of the data-layer integrity discipline — reconciliation
catches double-counting (sum of parts == total); completeness catches the opposite (a part silently
dropped). Both are "no silent failure" applied to the maths, and both are exactly the class of bug Rob has
been burned by. The drill-down's **income-by-source** view is the visual guard that makes such gaps obvious
(docs/PLAN.md gotcha Q). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active

## 2026-06-25 — Scenario model: base plan + delta what-if children + compare
**Decision:** Adopt the cashflow-modelling sector's standard shape (Voyant): a **base plan** that spawns
**named "what-if" child scenarios** created from a plain **"Create child" button**, each **overriding
anything the user changes** (often just 1–2 — rent, council tax, a healthcare savings amount, a person's
expected lifespan; sell-vs-stay, buy-vs-rent), with a **side-by-side Compare**. A child is
stored as a **delta — only its overridden parameters — on top of the base**; the effective inputs are
the base's persisted form-state (`builder_state`) **overlaid with the child's overrides**, resolved by
**one merge function** (with a round-trip test). It is **not** a full copy of the base. The child editor
is the **full builder pre-filled from the base** — whatever the user changes becomes an override (curated
levers like "retire 2 years later" are just shortcuts, **not** a limit), and **list items (expense lines,
pensions, accounts) gain stable IDs** so an override targets the right row across base edits (people
already have ids). Editing a saved scenario, spawning a child, and comparing all build on the persisted
form-state: edit reloads it, a child stores overrides against it, compare runs base + child.
**Why:** Confirmed with Rob — lightweight "tweak 1–2 parameters" children are exactly the what-if UX he
wants, and it is the market-leader pattern (Voyant's copy-on-write — "changing an item breaks the link
for that item only"; see [docs/RESEARCH-cashflow-modelling.md](research/RESEARCH-cashflow-modelling.md) §1).
**Delta over full-copy specifically to avoid forking:** a full copy duplicates the whole base into every
child, so a later base fix leaves children stale — they **fork** — the exact "same quantity in two places
that drifts" the data-layer guardrails exist to prevent. A delta keeps the base as the single source; a
child holds only its tweaks and otherwise tracks the base. _(This **corrects an initial full-copy lean**
taken earlier the same day, recorded here so the plan does not fork — per Rob's "don't accidentally fork
ourselves".)_ The new bite to guard is **override resolution** (`effective = base ⊕ overrides`) and
**orphaned overrides** if the base shape changes — both covered by the merge function + tests. The
engine DTO stays a **derived** artifact regenerated from the resolved inputs, so inputs keep one source
of truth. The data-shape change this needs is **authorised** — Rob confirmed the clean rebuild even
though it reworks yesterday's prototype builder, which served to get a usable app for feedback; the UI
wins (person names, the State Pension shortcut) carry over and the draft mechanism folds into
`builder_state`.
Generalises [[2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand]]; full
build order in docs/PLAN.md "Sector-informed build plan (2026-06-25)". [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active — **Phase B BUILT (2026-06-25):** `scenarios.builder_state` is the single source of
truth, the engine DTOs are derived from it (no reverse-mapper), structural columns are a projection, and a
saved forecast is editable in place (owner-scoped update-or-create that invalidates stale runs); the
`households`/`scenario_drafts` tables + their models/mappers are dropped (the draft is a `draft`-status
scenario). **Phase C2 BUILT (2026-06-26):** a child holds `parent_scenario_id` + an encrypted `overrides`
delta (no `builder_state`); the one merge fn is `App\Forecast\BuilderStateDelta` (`diff`/`merge`/`orphans`/
`structurallyDiffers`, round-trip + id-stability tested), resolved by `Scenario::effectiveBuilderState()`.
**List rows gained stable ids** (people kept p1/p2). The builder's child mode pre-fills from the base and
saves only the delta; a **structural add/remove is refused** (a delta cannot fork the base — gotcha N), a
**base edit propagates** to children (refresh + drop their stale runs), and a base delete **cascades**.
**Compare** runs base + children on their deterministic projection, side by side, never ranked. **v1
boundary recorded:** a child overrides *values* only; adding/removing a person/pension/account belongs to
the base or a new forecast (keeps the delta honest, no fork). The per-person longevity lever is wired into
the engine already (Phase A2); surfacing it as a builder what-if field is a C1 fast-follow (the merge
handles it for free).

## 2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved
**Decision:** Replace the flat essential/discretionary totals with **line items as the source of truth**:
`{id, label, amount(annual), category, savedAsAsset}`, category ∈ **essential** (needs, the floor) /
**discretionary** (wants, can-drop) / **self-investment**. Essential/discretionary **totals become the sum
of the lines** (derived — reconciliation discipline). Self-investment is a **first-class tier** (learning,
tuition, books, savings plans, personal investments) — **not** derivable from contributions. Each
self-investment line carries a **`savedAsAsset` flag** (default false = *spent*): *spent* lines
(courses, books) are expenditure; *saved* lines (savings/investments) are a **contribution that builds net
worth**, which needs a small addition — **ongoing contributions on savings accounts** (as DC pensions
already have). **One home per pound:** a saved line **is** the contribution, never also entered as an
account balance.
**Why:** A budget that forces prioritisation (keep essentials / drop discretionary / invest in the future)
is the sector-standard income-&-expenditure model and feeds the income-floor ("essentials covered by
guaranteed income"). Self-investment can't be derived (Rob: it spans learning/tuition/books that never
touch an account), so it is a tagged tier; the spent/saved flag keeps the **forecast correct** (spent
reduces wealth, saved is retained + grows) and **double-count-safe**. The split is framed as **the goal,
not a fixed percentage** (50/30/20 vs 60/20/20 vary everywhere, and a prescribed target reads as advice →
trips the lint). Importers populate the lines (the IWT profile already routes Fixed→essential,
Guilt-Free→discretionary, Investments+Savings→saved). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active — **CORE BUILT (2026-06-26, Phase C1):** `builder_state.expenseLines` (`{id, label,
amount, category, savedAsAsset}`) is the source; the `HouseholdAssembler` derives essential (Σ essential) and
discretionary (Σ discretionary + *spent* self-investment), and a *saved* self-investment line becomes a
balance-zero contributing ISA (`ongoingContributions`, applied from surplus by the existing engine —
**no engine change needed**), counted once (one home per pound). Flat totals dropped when lines exist;
legacy/imported scenarios seed lines from their flat totals on load. Reconciliation + completeness tested
(`ExpenseLineReconciliationTest`). **Implementation note:** the saved line is a synthetic ISA (a designated
single home for the saved amount), not a user-named wrapper — revisit if a real wrapper choice is wanted.
**Deferred (C1 fast-follow):** the results 3-tier display, the income-floor readout, importers emitting real
lines (they still emit flat totals → seeded into 2 generic lines), and the PLSA benchmark.

## 2026-06-25 — Per-person longevity / health adjustment (new engine input)
**Decision:** Add a **per-person longevity adjustment** so a what-if can model someone not expected to
reach peer-average age (e.g. known health conditions). It feeds the cohort-table `JointLifeSampler` as one
of: a **fixed assumed death age**, a **±years offset** to life expectancy, or a **mortality multiplier /
rated age** (insurer-style). Exact mechanism chosen at build; ships with a golden-master test.
**Why:** Rob wants to tweak expected lifespan in a child what-if; mortality is currently derived only from
DOB + sex with no health lever. This is a genuine **engine** feature (not a form-state override of an
existing field), so it is planned as its own small piece, keeping the engine framework-free + tested.
[[2026-06-24 — Mortality model: embed ONS cohort life tables]]
**Status:** active

## 2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures
**Decision:** Treat data-layer consistency as a hard, **tested** requirement, not a hope.
Concretely: (1) **one definition, one home** for every quantity — totals are **derived from their
parts**, never stored alongside them (e.g. `ExpenseProfile::targetAnnualSpend()` sums
essential+discretionary; ages derive from DOB; the engine DTOs stay the single source of truth that
storage and UI map to/from). (2) **Reconciliation invariants** must be asserted in tests:
sum(imported monthly line items)×12 == reported essential spend; net sale proceeds == sale −
mortgage − costs − CGT; per-variant terminal wealth == liquid + property. (3) Every spreadsheet
profile must be verified against a **sanitised real-file golden fixture** — a structurally faithful
copy of the real workbook (same layout traps: decoy "take home" rows, merged headers,
total/remainder rows) with the figures replaced by fakes, committed to the suite — because the
synthetic happy-path test alone let **two real double-counting bugs through** (`PayAndExpenditures`),
caught only by running on the real file by hand. (4) Every figure a view shows must trace to **one
computed value**; the panel, the CSV export and the interpretation read the same field, pinned by a
test. (5) Aggregated/imported totals are **surfaced for review** (no-silent-failure applied to
*counting*), so a mismatch is visible, never absorbed.
**Why:** Rob was burned on a past project not by hallucinated numbers (those were verifiable) but by
the data layer **inconsistently counting the same information** — the same quantity aggregated
differently in different places. The integer-pence rule, the DTO single-source-of-truth and the
round-trip equality tests already defend the *transport* boundary (a stored value decrypts to an
identical DTO), but they do **not** defend the *aggregation* boundary, where this class of bug lives.
The two `PayAndExpenditures` double-counting bugs are direct evidence this project is not immune; the
only thing that caught them was a manual real-file run, which CI does not repeat. Making
reconciliation an explicit, tested invariant — plus a committed real-shaped fixture — turns "verified
once by hand" into "verified every build." **Implemented for the importers (2026-06-25):**
`tests/Fixtures/Import/GoldenWorkbooks.php` (sanitised real-file fixtures, layout-faithful, fake
figures) + `tests/Unit/Import/ImportReconciliationTest.php` reconcile each profile's output to the
sheet's own stated totals. On its first run the guardrail immediately caught — and we fixed — **two
live wrong-aggregation bugs** in the IWT `ConsciousSpendingPlan` importer that the synthetic tests
missed: a per-bucket "… TOTAL" row was summed on top of its line items (essential came out ~2×), and
the `NET WORTH` Investments/Savings rows were miscounted as monthly contributions. The fix makes a
bucket's own TOTAL authoritative. Still to do: the displayed-figure-provenance test (#4, panel == CSV
== interpretation) and the import reconciliation panel (#5). [[2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook]] [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook
**Decision:** Read `.xlsx` uploads with **`phpoffice/phpspreadsheet`** (an **app-layer** dependency —
the engine stays framework- and dependency-free). Profiles no longer take a raw string; they take a
sheet-aware **`Spreadsheet`** value object (sheetName → string rows) built by **`SpreadsheetReader`**
from CSV or XLSX. XLSX is read **data-only**, taking the values Excel last **cached** (no
recalculation), so unsupported formulas don't break the import. Multi-tab workbooks get a **tab
picker** (`updatedImportFile` lists sheet names; `Spreadsheet::select` narrows to the chosen one
before parsing). A bespoke **`PayAndExpenditures`** profile reads Rob's scenario tabs: the expenditure
block is anchored on **"% of Take Home Pay"** (the only heading unique to it — bare "take home" and
"Expenditure Item" both collide with rows/headers above it), summing monthly outgoings → essential;
the income block above the deductions header maps **State Pension → a state pension, DLA → tax-free
income, salary → gross, and a pension named in a later column → an annuity**. Imported income lands on
**Person 1 with no start age** — the sheet carries neither ages nor a person split — and that is
**flagged in the import summary**, not guessed. Everything imports as **essential** (no per-line split
yet). **Each mapping was verified by running the profile on Rob's real workbook**, not just synthetic
fixtures.
**Why:** Rob supplied real `.xlsx` files and wants to upload them directly. Reading cached values keeps
a formula-heavy personal workbook importable without a calc engine. Anchoring on the unique header and
verifying on the real file caught two double-counting bugs that synthetic tests alone missed (Rob
flagged the over-confidence) — so "trustworthy / no silent failure" is upheld by surfacing every
imported total and every unset field for review rather than fabricating ages or a discretionary split.
Refines [[2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry]]. The
**line-item expense categories** data model and **Nischa** (a 50/30/20 dashboard) stay deferred.
**Status:** active

## 2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry
**Decision:** (1) The builder is a **five-step, free-navigation wizard** (About & people; Pensions &
income; Your net worth = savings + the home; Spending; The decision). Stepping is **server-side**
(`@if($step===N)` + `wire:click` nav), not Alpine `x-show`, because the existing tests drive the
component by setting properties and calling `save()` (never the DOM), so server-side steps stay fully
unit-testable without a browser and the property/`save()` contract is unchanged. A failed `save()`
catches `ValidationException`, sets `$step` to the first step owning an errored field (a static
field→step map) and re-throws, so the user lands on the problem. Accessibility (`aria-current`,
focusable headings + error summary via dispatched events, `aria-invalid`/`aria-describedby`,
double-submit guard, a new `endAge ≥ startAge` rule) is built into the restructure rather than bolted
on. (2) **Spreadsheet import** is an `App\Import\ImportProfile` **registry**: each profile turns one
known layout's contents into a partial form state (`ImportResult`), money summed as **exact integer
pence** (`MoneyText`, mirroring the assembler's no-float rule), monthly figures ×12 to annual. The
**RetireForecast CSV** profile is the one calibrated reader; it pre-fills only spending + the main
salary and reports honestly what the household still needs by hand (budgets carry cashflow, not the
balance sheet). **IWT / Nischa ship as registered `UncalibratedProfile` stubs** that refuse with a
reason until a real sample export maps their cells — no guessing a layout we have not seen. XLSX
(needs phpoffice/phpspreadsheet) and line-item expense categories are deferred to Rob's call.
**Why:** A wizard was Rob's explicit UX ask and the single long form was the a11y pain point; keeping
it server-side preserves the test suite and avoids a browser dependency overnight. The profile
registry makes import extensible and honest — the popular third-party templates are first-class once
calibrated, and "no silent failure" holds (every refusal carries a reason). Reusing the integer-pence
rule keeps money lossless across the import boundary. [[2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement]] [[2026-06-25 — External-review triage: what we adopt, and three declines]]
**Status:** active

## 2026-06-25 — Compliance layer built: partition lint, acknowledgement gate, walled-off interpretation
**Decision:** Implemented the regulatory layer (Phase 2 step 4) with these concrete choices.
(1) The banned-phrasing guard is `App\Compliance\OutputPhrasing` holding **directive-only** regex
patterns ("you should", "the best option", "is better for you", …) — never the bare nouns — so neutral
disclaimers that use "recommend"/"advice" in negated form ("not a recommendation", "does not tell you
what to do") pass. (2) The build test is a **path/namespace partition**: it scans every Blade view plus
all app PHP and asserts zero violations, with exactly two exemptions — the `App\Compliance` namespace
(the lint patterns + the `Interpretation` service) and any view whose filename contains
`interpretation`. A separate assertion proves the partition is load-bearing (the `Interpretation`
service *does* contain directive phrasing and is the only thing exempt). (3) The first-run
acknowledgement is a **middleware gate** (`EnsureDisclaimerAcknowledged`) redirecting unacknowledged
users to a dedicated screen and storing `users.disclaimer_acknowledged_at` — **not** a JS modal (a
server-side gate is testable and cannot be skipped); GDPR/account routes sit **outside** the gate
(data-subject rights are not withheld pending acceptance). (4) Per-result disclaimer + signpost are
reusable Blade components (`<x-disclaimer.result>`, `<x-signpost>`); every CSV export is prefixed with
the guidance-only disclaimer. (5) The interpretation capability is a `users.can_interpret` boolean
behind an `interpret` Gate, set via a Filament `UserResource` `ToggleColumn`; the gated partial and the
`Interpretation` service are the sole homes of directive wording. (6) Deleted the stock Laravel
`welcome.blade.php` (unused — the landing is `home.blade.php`; it tripped the lint with marketing copy).
**Why:** Directive-only patterns + a path partition keep the lint precise (no false positives on
disclaimers, no escape hatch for real recommendations) and make the walled-off advice mode auditable
rather than ad hoc. A middleware gate honours "no silent failure" and is provable in tests. Implements
[[2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default]]
and [[2026-06-24 — Regulatory posture: guidance only]]. Also folded in the tagged "no silent failure"
hardening: GDPR `export()` now includes the user's runs+results, `RunScenarioSimulation::failed()`
lands a dead worker's run in a terminal Failed state, and `ScenarioResults::currentRun()` is
owner-scoped against a forged `$runId`.
**Status:** active

## 2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default
**Decision:** Add an optional capability ("interpretation mode" / "what this suggests") that, when
enabled for a specific user, renders directive plain-language readouts ("under these assumptions,
buying lasts longer; renting runs out in N% of paths") alongside the neutral figures. It is **off by
default, the public default stays neutral guidance**, and it is **granted only by an admin** (a per-user
boolean on `users` behind a Gate ability, set from Filament) — never self-serve. The directive
sentences are produced by a single walled-off `Interpretation` service **from the computed numbers,
not hard-coded into the result Blade templates**. The banned-phrasing build test is therefore reframed
from "no banned phrasing anywhere" to a **partition check**: the neutral result/warning templates,
default formatter and exports must stay clean, and directive phrasing may exist **only** inside the
gated interpretation layer. Every output/export is labelled with the mode that produced it.
**Why:** For Rob's own and family use the directive framing is genuinely clearer, and giving it
privately is outside the FCA perimeter (not by way of business). Walling it off + admin-gating +
neutral-by-default keeps a live public deployment on the guidance side of the line, so the planned
public release survives the feature rather than being blocked by it. The toggle must **not** be
grantable to arbitrary public users on a live deployment (self/family only); doing so would be a
deliberate, separate regulated-perimeter decision. Refines, does not supersede,
[[2026-06-24 — Regulatory posture: guidance only]]; raises the priority of tightening
`User::canAccessPanel()` and the run-ownership scoping before public release.
**Status:** active

## 2026-06-25 — External-review triage: what we adopt, and three declines
**Decision:** A second-opinion review (MS Copilot, from the doc set) was triaged into the post-v1
backlog in [docs/PLAN.md](build/PLAN.md) ("External review triage"). We **decline** three of its
suggestions as over-engineering or misaligned for a local-first single-user tool: (1) **per-row /
envelope encryption** — the blast-radius case assumes a multi-tenant server, but the whole SQLite DB
*and* the Laravel app key live on one personal machine, so app-key `encrypted:array` is right-sized;
revisit only on a public multi-user release; (2) a **native Monte Carlo accelerator** (Rust/WASM/SIMD)
— premature, and it breaks the framework-free pure-PHP ethos that makes the golden-master trustworthy;
10k PHP paths are already responsive; (3) **automated gov.uk scraping** of tax tables — fragile, and the
figure set is small, so manual sourcing with a `verified_on` date is *more* trustworthy, not less.
We also flag that the review's adviser-style metrics (implied withdrawal rate, critical yield,
replacement rate, narrative report, capacity-for-loss) may only be adopted **behind the
`OutputPhrasing` banned-phrase lint** and stated as neutral facts/definitions — never as targets or
benchmarks (e.g. no "safe 3–4% withdrawal range"), to stay on the guidance side of the line.
**Why:** Keeps the security/perf posture proportionate to an on-machine personal tool, protects the
engine's isolation, and holds the education-only constraint that is a hard project rule.
[[2026-06-24 — Regulatory posture: guidance only]] [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement
**Decision:** The scenario builder and result views are hand-rolled Livewire 4 components (Filament
stays admin-only). Form input becomes engine DTOs in a standalone `HouseholdAssembler` (not inside
the Livewire component), so the string→DTO conversion is unit-testable and reusable (the demo preset
later). Money the user types is parsed to exact integer pence by a string parser (split on `.`, pad
to 2dp), never `(float) * 100`, keeping "no float in money" true at the UI boundary. Every figure a
chart plots is also rendered as headline text and inside an accessible `<table>` (in a `<details>`)
with a CSV download; the ApexCharts canvas (bundled via npm, mounted by a small reduced-motion-aware
Alpine `chart` wrapper) is a progressive enhancement, never the source of truth.
**Why:** The plan mandates a hand-rolled Livewire builder and WCAG 2.1 AA charts where headline
numbers are text first. Separating the assembler keeps the lossless shape conversion provable in
isolation (it rebuilds the rich `HouseholdFixture` exactly). [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Full-page Livewire uses the Blade layout component, not `layouts::app`
**Decision:** Full-page Livewire components render into the app's Blade layout component
(`components.layouts.app`) via `#[Layout(...)]`, not Livewire 4's default `layouts::app`. The base
`TestCase` calls `withoutVite()` so view tests do not depend on the gitignored `public/build`. Region
selection is guarded by actually asking `TaxYearRegistry::for()` to build the config, so Scotland is
refused with a clear error until its band pack lands (and auto-enables when it does), mirroring the
engine's own refusal rather than duplicating the rule.
**Why:** Livewire 4 registers `layouts` only as a component namespace, not a view namespace, so its
default page layout has no hint path here; reusing the one Blade layout the auth/marketing pages use
keeps a single source of truth. Tying the region guard to the engine avoids a second place to update.
**Status:** active

## 2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand
**Decision:** A `SimulationRun` executes the engine's `HousingComparison` for the scenario's
household + housing action, producing **three `Result` rows** (stay_put, buy_outright, rent) on
one seed — the buy-vs-rent headline. The central deterministic "best estimate" forecast is
computed on demand by `ScenarioForecaster::deterministic()` and not (yet) persisted. The app
assembles all engine inputs in `ScenarioForecaster`; the base year is derived from the
scenario's tax year so runs stay clock-free.
**Why:** Buy-vs-rent is the point of the tool, and the engine already runs the three variants on
identical seeds, so one run → three comparable results is the natural unit. Keeping deterministic
output unpersisted avoids storing a figure the UI can recompute instantly. [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Engine gains an optional progress hook (non-breaking)
**Decision:** Add an optional `?callable $onProgress` to `Simulator::run` and
`HousingComparison::compare` (default null = unchanged behaviour). The hook carries no I/O, so
the engine stays clock- and I/O-free; the app updates `progress_pct` from it and **cancels a run
by throwing from the hook** (`RunCancelled`), which the engine lets propagate. Progress is
per-path within a variant, with each variant weighted into a third of the overall bar. Chosen
over the plan's "chunk 10×1000 with incremental aggregation", which would need the engine to
expose mergeable partial percentiles (a bigger change for little gain at these run times).
**Why:** "Nothing long-running may run silently" needs a live progress signal, but the engine
must stay framework-free. An optional callback is the minimal faithful touch; throwing for
cancellation keeps cancellation entirely an app concern the engine need not know about.
**Status:** active

## 2026-06-24 — Runs: preview synchronous, full queued; queue driver deferred
**Decision:** Preview runs (~1,000 paths) execute synchronously for responsiveness; the full run
(10,000 paths) is queued via the standard Laravel queue abstraction (`RunScenarioSimulation`
job, holding only the run id). The concrete queue driver is left to infra — database/sync
locally, Redis + Horizon if/when needed — since the mechanism (job + status + progress + cancel)
is driver-agnostic. The seed is generated and recorded when not supplied; the assumption set is
snapshotted (frozen) on the run so results survive later edits to the live set.
**Why:** Matches the plan's preview-vs-full split without committing the local-first app to a
Redis dependency it does not need yet. Recorded seed + frozen snapshot make every stored run
reproducible and auditable. [[2026-06-24 — Engine gains an optional progress hook (non-breaking)]]
**Status:** active

## 2026-06-24 — Persistence: one encrypted payload per row, mappers in the app
**Decision:** Store each Household and Scenario as clear structural columns (name, region,
variant, base tax year, status, owner, timestamps) plus **one `encrypted:array` payload**
holding all the sensitive detail, rather than ~30 encrypted columns. The DTO↔array mapping
lives in the **app** (`app/Finance/Mapping/`), not the engine, so the engine stays
framework- and serialization-agnostic; the readonly DTOs under `packages/finance-engine/src/Dto`
remain the single source of truth and Eloquent maps to/from them.
**Why:** Encrypted columns are unindexable anyway, so a single payload is simpler and keeps
listing/filtering on the clear columns. Keeping the mapper app-side preserves the engine's
isolation. A round-trip test asserts a saved row decrypts to an identical DTO. Follows the
plan's persistence section. [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — Withdrawals on the pension; SimulationRun/Result deferred
**Decision:** Planned pension withdrawals live on the DC pension inside the household payload
(faithful to `DcPension::$withdrawalPlan`), **not** duplicated as a scenario-level field, so
there is one source of truth for them. The `SimulationRun` and `Result` tables are deferred
to the forecast-services step (there are no results to persist until the engine is wired into
the app).
**Why:** The data-model sketch listed withdrawal_decisions on Scenario, but the canonical DTO
already carries them on the pension; honouring the DTO avoids a second, divergent home for the
same data. Deferring run/result storage keeps phase-2 step-1 focused on input persistence.
**Status:** active

## 2026-06-24 — Auth: Fortify headless, web-route guests redirect to login
**Decision:** Install Laravel Fortify but run it **headless** (`config/fortify.php` views
disabled) until the Livewire UI phase builds the login/register screens. GDPR/account routes
sit behind the `auth` middleware; an unauthenticated visitor is **redirected to login** (302),
which is the correct behaviour for a web app (not a 401 API response). A placeholder named
`login` route exists so the redirect target resolves until the real screen ships.
**Why:** The auth backend is needed now (ownership scoping, GDPR), but the screens belong with
the rest of the UI. Anonymous use writes nothing because every write path is auth-gated.
**Status:** active

## 2026-06-24 — Admin: Filament 5 (Livewire 4); assumption-set figures stay sourced
**Decision:** Use Filament 5 for the admin panel at `/admin` (it pulls **Livewire 4**, a bump
from the plan's stated Livewire 3). The AssumptionSet resource curates metadata (name, source
note, default) and a model hook keeps at most one default; the **sourced numeric figures are
seeded from the engine's signed-off `AssumptionSetLibrary` and are not casually editable in
the admin** (numeric editing is a deliberate, flagged follow-up). The tax-year audit page is
read-only over the registry. `User::canAccessPanel()` returns true for this local single-user
app (tighten before any public release).
**Why:** The figures are sourced and signed off; editing one means re-sourcing it with a new
verified-on date, which should be a deliberate act, not a stray form save. Keeps the
"no magic numbers, every figure sourced" posture intact. [[2026-06-24 — Tax figures versioned per tax year, sourced and dated]]
**Status:** active

## 2026-06-24 — Mortality model: embed ONS cohort life tables
**Decision:** Drive stochastic joint-life mortality from embedded ONS cohort life tables
(by single year of age and sex), sampling each partner's age of death per path and running
the household to the last survivor.
**Why:** Cohort tables account for future mortality improvements, so lifespans (and the
"will the money last" answer) are realistic. Rob chose this over a parametric fit (compact
but less precise at extreme ages) and over period tables (simpler but understate longevity).
Larger data ingest, but a one-off, and it carries a clear ONS source.
**Status:** active

## 2026-06-24 — Forecast mechanics: dual drawdown strategy + cautious default allocation
**Decision:** (1) Ship TWO drawdown strategies and compare them side by side rather than
picking one: "tax-efficient" (cash → GIA → ISA → DC pension last) and "pension-aware" (draw
DC pension income earlier, up to a sensible band, to reduce the post-April-2027 IHT estate).
Default display = tax-efficient. (2) Default invested-pot allocation (DC, ISA, GIA) =
cautious **40% equities / 60% bonds**, no cash within pots; cash accounts use the cash
assumption. Both are runtime-configurable per scenario.
**Why:** The drawdown order trades off income tax now vs sheltered growth and IHT later;
showing both keeps the tool neutral (illustrate consequences, not recommend) and surfaces the
April-2027 tension. A cautious 40/60 suits a retired household relying on the pot for income
(lower sequence-of-returns risk). [[2026-06-24 — Forecast mechanics: ... ]]
**Status:** active

## 2026-06-24 — Assumption figures signed off (adopted as proposed)
**Decision:** Rob signed off the researched figures in [docs/ASSUMPTIONS.md](spec/ASSUMPTIONS.md)
as proposed. Set A (FCA real returns + DMS vols) is the engine default; Sets B (DMS
historical) and C (OBR/BoE) ship as compare overlays. Includes the flagged judgement calls:
cash real vol overridden to 2% (inflation modelled separately), Eq–Cash/Bond–Cash
correlations as reasoned estimates, house growth +1% real. Figures stay runtime-overridable
and are re-verified at build time.
**Why:** Unblocks the forecast year-stepper and Monte Carlo with defensible, cited defaults.
**Status:** active

## 2026-06-24 — Default assumptions: FCA expected returns + DMS volatilities
**Decision:** The default AssumptionSet uses FCA-derived expected returns combined with
Barclays Equity Gilt Study / Dimson-Marsh-Staunton (DMS) historical volatilities and
correlations. DMS and OBR/BoE-inflation sets ship as compare overlays. Claude researches and
proposes the actual cited figures; **Rob signs off before any forecast is shown as real.**
**Why:** FCA projection rates are the familiar, defensible default but publish only nominal
growth brackets, not the volatility/correlation a Monte Carlo needs; DMS supplies those from
a coherent historical source. Pairing them gives a defensible default that the stochastic
engine can actually run. [[2026-06-24 — Modelling depth and scope (from approved plan)]]
**Status:** active

## 2026-06-24 — Savings + dividends computed in one combined income-tax pass
**Decision:** The plan listed separate `SavingsTax` and `DividendTax` calculators. Instead,
savings and dividend tax are computed inside `IncomeTaxCalculator::compute(TaxableIncome)`,
a single combined pass over the rate bands, rather than as three independent calculators.
`onNonSavingsIncome` is retained for the simple case (and the existing tests).
**Why:** UK income tax stacks the three categories in a fixed order (non-savings, savings,
dividends), and the savings starting-rate band, Personal Savings Allowance and dividend
allowance all consume rate-band space even though charged at 0%. Computing them separately
cannot get the band interactions right; a shared band cursor is the only faithful model.
**Status:** active

## 2026-06-24 — Scaffold the standard doc set
**Decision:** Added PRD.md, DATA-MODEL.md, DECISIONS.md and the root CLAUDE.md orient
tripwire, porting goal / data model / decisions out of docs/PLAN.md so they have a standard
home. docs/PLAN.md remains the exhaustive scope source of truth.
**Why:** The project adopted the documentation standard; the orient hook flagged these as
missing. Keeps a fresh session from re-reading the whole plan to find the shape.
**Status:** active

## 2026-06-24 — Local-first, personal use, no hardcoded client data
**Decision:** Build a local single-user site. Rob enters the couple via the UI himself; any
first-run sample must be obviously fictional. Possible free public release later, so do not
design accounts out — just defer them.
**Why:** The immediate need is Rob's own decision support for a known real couple. Hardcoding
their data would leak PII into the repo and bake in one scenario.
**Status:** active

## 2026-06-24 — Money is hand-rolled integer pence (brick/money dropped)
**Decision:** Use a hand-rolled `Money` value object over integer pence. Do not re-add
brick/money without re-checking the clash.
**Why:** `brick/money` could not resolve against `brick/math` 0.18 in the Laravel 13 lock.
The plan already listed integer pence as the primary option; zero dependencies strengthens
the engine's framework isolation.
**Status:** active

## 2026-06-24 — Engine is framework-free, in a path package
**Decision:** The calculation engine lives in `packages/finance-engine`
(`retireforecast/finance-engine`, path repo, required `"*"`), with zero Laravel
dependencies, no I/O and no clock. Tests run as pure PHPUnit, no Laravel bootstrap.
**Why:** Isolation is what makes the HMRC worked-example tests and the Monte Carlo
golden-master trustworthy. The Laravel app is a shell around the product.
**Status:** active

## 2026-06-24 — Tax figures versioned per tax year, sourced and dated
**Decision:** Every tax figure lives in a per-tax-year config carrying a `source` URL and a
`verified_on` date. Two stale-brief corrections baked in: income-tax threshold freeze runs
to **April 2031** (not 2028); **dividend rates rise in 2026/27** (ordinary 8.75→10.75,
upper 33.75→35.75).
**Why:** No magic numbers; figures must be defensible against gov.uk. 2025/26 and 2026/27
genuinely differ, so they are distinct config years.
**Status:** active

## 2026-06-24 — Modelling depth and scope (from approved plan)
**Decision:** HMRC-accurate deterministic engine PLUS Monte Carlo with stochastic joint-life
mortality. Pensions: DC, DB, State Pension. Housing: buy-cheaper-outright vs rent on
identical seeds. IHT/legacy in as a toggle (incl. pensions entering the estate from Apr
2027). Assumptions are a runtime/display choice across several sourced sets (FCA default),
not baked in. England/Wales/NI first; Scotland income tax + LBTT/LTT out of v1 (region
resolver throws rather than guessing).
**Why:** Matches the decision-support goal: the consequences only become visible if tax,
longevity and sequence risk are all modelled properly. Captured here from docs/PLAN.md.
**Status:** active

## 2026-06-24 — Regulatory posture: guidance only
**Decision:** Education/guidance only, never a personal recommendation. A build-time test
fails if any result template contains banned recommendation phrasing. Signpost Pension Wise
/ MoneyHelper / FCA-regulated advisers.
**Why:** Personal recommendations on pensions/drawdown are FCA-regulated activity. Staying on
the guidance side of the line is a hard design constraint, not a wording afterthought.
**Status:** active
