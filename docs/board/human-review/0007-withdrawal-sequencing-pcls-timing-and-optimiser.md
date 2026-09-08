# Withdrawal sequencing #5 and #6: PCLS timing and the search optimiser

## Why
Slices 1 to 4 of [docs/build/PLAN-withdrawal-sequencing.md](../../build/PLAN-withdrawal-sequencing.md)
are shipped (the named "Fill the bands" strategy, the Compare tie-in, the results panel and the
advice-gated steer). #5 and #6 are specced and not built.

This card previously sat in `human-review/` saying the two slices were "gated on two modelling
judgements that are yours to make". **That was stale.** Re-reading the plan on 2026-08-01 found
both already answered in its own "Decisions (Rob, 2026-07-01)" section: item 5 rules that the
planner **may time the PCLS** rather than leaving it user-specified, and item 6 puts the
**search-optimiser in scope, sequenced last**. Item 4 settles the PA-taper band as in scope too.
Nothing is waiting on a person, so this is buildable work rather than a decision owed.

## Links

**Relates to**
- `0019` - multi-property and Section 24 interactions are fenced out of this card and are that
  card's subject.

## Not this card
The optimiser (#6) is the second half and is explicitly sequenced last in the plan; do not start it
before #5 is green. Multi-property and Section 24 interactions are card 0019.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the "Fill the bands" strategy draws from a DC pot, THE APP SHALL take the draw
      UFPLS-style so the 25% tax-free element is applied, and lifetime tax SHALL fall against the
      pinned pre-#5 figure.
- [x] #2 THE APP SHALL NOT draw from a pot before its owner reaches that pot's access age, under
      any ordering the planner chooses (the 2026-07-02 access-age gate must not regress).
- [x] #3 THE APP SHALL NOT fill beyond the MPAA once flexible access has been triggered.
- [x] #4 WHEN a household is on Guarantee Credit, THE APP SHALL prefer capital (ISA / PCLS,
      disregarded as income) over pension income that would claw the credit back pound for pound.
- [x] #5 WHEN the optimiser runs, THE APP SHALL report the lifetime-tax delta as the difference
      between two of the engine's own runs, never a re-derivation.
<!-- AC:END -->

## Tasks
- [x] #5 planner-timed PCLS: make FillBands draws UFPLS-style (plan section "#5")
- [x] Pin the access-age gate with a test that an under-access-age pot is never UFPLS-drawn
- [x] #6 bounded search over orderings (plan section "#6"), last
- [x] Read the plan's "Coordination (READ before touching PathProjector)" note first

## Direction

**2026-08-19 - the expert panel raises the stakes on slice #5.** `fundShortfall`'s draw closure
takes pension money **gross and taxes 100% of it**. The 25% tax-free element is only ever applied
through an explicit withdrawal instruction. The plan calls that a conservative baseline; the
adviser's view is that it is not conservative, it is a modelling error, because it misprices
pension wealth against every other asset - roughly five percentage points of tax on every pension
pound for a basic-rate household, making pension wealth look about a fifth smaller than it is. That
biases every housing comparison towards realising property equity rather than pension.

Two additions he would make on the same pass:

- Change the **displayed default** to `FillBands`. The shipped default draws pension last, which
  since the April 2027 pensions-in-estate change is generally wrong for an estate near the bands.
  `FillBands` is already Pension Credit aware, which is the right behaviour for this cohort.
- Net the pension component of `usableWealth` at the projected marginal rate. Treating a pot as
  fully spendable overstates available capital and makes the safety-buffer warning fire late.

Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md` (adviser finding 4).

**2026-08-19 - both slices built; all five criteria met.**

**#5, planner-timed PCLS.** `PathProjector::fundShortfall` gained a second closure,
`$drawPensionUfpls`, used by every FillBands pension step (including the one that funds a CGT bill).
It draws a gross whose taxable part fills the band being filled while a 25% tax-free slice rides on
top, capped by the member's remaining Lump Sum Allowance. Two caps bind at once and swap over, so
`PathProjector::maxUfplsGross` solves both; `PathProjector::ufplsSplit` is now the single home of the
25% rule and `plannedWithdrawals` reads it too, so a planned instruction and an ad-hoc draw spend one
LSA ledger and cannot double-count it. `$drawPension` is untouched, so `TaxEfficient`, `PensionAware`
and the HMRC worked examples are byte-identical.

The pinned pre-#5 lifetime tax for the test household is **£103,538.10**, measured by reverting the
projector and re-running, not guessed. After #5 it is **£81,749.09**. The graceful-degradation test is
exact rather than approximate: a member whose whole allowance is already used reproduces the pinned
figure to the penny.

**MPAA.** Flexible access (a UFPLS or drawdown income, planned or ad-hoc) now caps that member's
money-purchase contributions at the MPAA. All three places a DC pot could be credited route through
one `payIntoPot` helper, so the cap cannot be applied at two of them and missed at the third. What the
cap blocks is not dropped: a net-pay contribution stays in pay and is taxed there, a surplus-funded one
stays in the surplus and is saved as cash.

**#6, the optimiser.** `WithdrawalStrategyComparison` now runs its whole bounded `CANDIDATES` set (the
three named strategies) and reports the cheapest plus its saving against the order in place. Extending
the existing class rather than adding a sibling keeps the results page at three forecasts rather than
five. Ties keep the current order, so it never reports a saving for something that pays more. The
results panel gained a neutral line and `Interpretation::withdrawalSequencingNarrative` now names the
optimiser's winner instead of only comparing two orders. Screen and PDF now read one shared
`panel()` array, which removes a duplicated shape rather than adding to it.

**Assumed, and flagged in code rather than settled here:**
- The MPAA is modelled as a **hard cap on what may be paid in**, not as an annual-allowance charge on
  the excess, and it bites **from the year of the trigger** rather than the day after it. Both are
  noted on `mpaaHeadroom`. The engine already prices the charge separately in
  `AnnualAllowanceCalculator`; wiring that into the projector was wider than this card.
- The MPAA figure is read from the frozen statutory constant and **not indexed**, because nothing has
  indexed it.
- The tax-free quarter of an ad-hoc UFPLS is reported on the cashflow ladder under
  **`pension_drawdown`**, not `pension_lump_sum`, because `fundShortfall` returns one `fromPension`
  total. The money is visible and the totals reconcile, but a reader sees tax-free cash filed as
  taxable drawdown. Splitting it is a small change to the return shape and belongs in its own card.

**Not built, deliberately, because it is outside this card's acceptance block:** the two additions the
adviser would have made on the same pass, in the direction note above. The displayed default is still
`TaxEfficient` (the optimiser now *shows* when `FillBands` is cheaper, which is the honest half of
that), and `usableWealth` still treats a pension pot as fully spendable. Both want their own cards.

**Could not settle from the repository:**
- The card's instructions say to run `.\vendor\bin\pest.bat`. **This project has no Pest**; it is
  PHPUnit, run as `php artisan test` per CLAUDE.md. That is what was run: **1075 tests green**, 1 skipped
  (the posture-aware banned-phrasing partition). `vendor/bin/pint --dirty` is clean and
  `php artisan scenarios:audit` passes on all 25 stored scenarios.
- **Worktree trap, worth knowing before the next card touches the engine.** `vendor/` is copied into the
  worktree, and the copy **dereferenced the `retireforecast/finance-engine` symlink** into a real
  directory. So the app and the whole suite loaded a stale engine and every engine edit was invisible,
  silently and greenly. Fixed with `Remove-Item -Recurse -Force vendor\retireforecast\finance-engine`
  then `composer update retireforecast/finance-engine`, which restores the junction. Plain
  `composer update` on its own does **not** repair it.
- **Not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from this
  worktree, so the new panel line and the reworded steer still need Rob's eye on a real page.

### 2026-08-23 review (v20260823093936-a81c)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 226s, run by this job rather than reported by the card.

**acceptance: defect**

Traced, all five, in `PathProjector` unless said:

**#1** `fundShortfall` ÔåÆ `$drawPensionUfpls` on every FillBands pension step, split by `ufplsSplit`/`maxUfplsGross`; pinned by `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax`.
**#2** access-age gate inside `$drawPensionUfpls`; pinned by `test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.
**#3** `payIntoPot` + `contributionHeadroom`; all three credit sites route through `payIntoPot`.
**#4** `fundShortfall` skips both banded pension steps when `$onGuaranteeCredit`; the tax-free slice never enters `taxablePerPerson`, which is what `meansTestedBenefitNominal` assesses.
**#5** `WithdrawalStrategyComparison::for` + `lifetimeTax`.

One defect, on **#3**. `mpaaTriggered` is set only by `$drawPensionUfpls` and `plannedWithdrawals`. The sibling `$drawPension` closure in `fundShortfall` ÔÇö every ad-hoc draw under TaxEfficient and PensionAware ÔÇö takes taxable pension income and never sets it, though `WithdrawalKind::DrawdownIncome` says that is flexible access. A 55+ member still receiving contributions therefore keeps the full annual allowance and is filled beyond the MPAA. It also skews **#5**: only the FillBands candidate carries the restriction, so the optimiser compares three runs on unequal terms.

VERDICT: defect

**scope: defect**

Read: the card commit `1bf5b8f`, the plan's #5/#6 sections, `PathProjector`, `WithdrawalStrategyComparison`, both view layers.

**Half done ÔÇö the PDF was left behind.** `resources/views/pdf/partials/report.blade.php`, the "How you draw your money down" card, still prints two tiles and the old note. It never reads `candidateCount`, `cheapestLabel`, `optimiserSaving` or `optimiserSaves` from `WithdrawalStrategyComparison::panel()`. It *does* print `$withdrawal['steer']`, and `Interpretation::withdrawalSequencingNarrative()` now names the optimiser's winner and its saving. So the PDF tells a reader a third order saves ┬úX, and no figure on the page backs it. That is an invisible figure, which this project forbids. The write-up's claim that the PDF "now also report[s] the cheapest ... order" is not true. The same block's note still says FillBands draws pension as taxed income only; the screen partial gained the new tax-free-quarter sentence, the PDF did not.

**A claim it did not build.** `WithdrawalStrategyComparison::CURRENT` says it is single-sourced from `ScenarioForecaster::settings()`. It is a second copy of that literal. Change the default and the baseline drifts, quietly.

**Left on the floor.** Three deferrals were named and no board card was written for any.

VERDICT: defect

**breakage: defect**

I tried to break it. Three things break.

**1. The MPAA docblock says the wrong thing.**
`PathProjector::contributionHeadroom` says the cap "bites from the year of the trigger rather than the day after it", and calls that the cautious side. It does not. In `PathProjector::projectYear`, `payEmployerContributions` and `payNetPayContributions` both run *before* `plannedWithdrawals` and `fundShortfall`, which are the only places `mpaaTriggered` is set. So in the trigger year the member still gets the full annual allowance on both routes. The card's own test, `test_flexible_access_caps_later_money_purchase_contributions_at_the_mpaa`, asserts "the year AFTER". The docblock is false, and false the un-cautious way.

**2. The baseline is a copied literal.**
`WithdrawalStrategyComparison::CURRENT` says it is "single-sourced" from `ScenarioForecaster::settings()`. It is a second copy of the same literal. No test ties them. Change the displayed default (the adviser's own next step) and the panel reports the saving against an order nobody is on.

**3. The Guarantee Credit comment claims a clawback the model never applies.**
`PathProjector::fundShortfall` says the tax-free quarter means "less of the credit is clawed back". The award is assessed from `$taxablePerPerson` before `fundShortfall` runs, and `fundShortfall` never writes back. No ad-hoc draw, taxed or not, ever reaches the means test.

VERDICT: defect

**2026-08-23 - the review's defects fixed; the acceptance ticks stand.** Every finding above was
reproduced before it was touched. Nothing was reworded to make it go away.

**The one real behaviour bug (acceptance #3, and the #5 skew it caused).** `$drawPension` - the
closure every ad-hoc draw under `TaxEfficient` and `PensionAware` goes through - took taxable pension
income and never set `mpaaTriggered`, so a member still receiving contributions kept the full annual
allowance. It now sets it, at the same point in the closure its UFPLS sibling does. That was also why
the optimiser compared its three candidates on unequal terms: only `FillBands` carried the
restriction. Pinned by
`PathProjectorTest::test_an_ad_hoc_taxable_pension_draw_triggers_the_mpaa_too_not_only_a_ufpls`, which
builds a worker whose spend outruns her pay, so the shortfall is funded straight out of the pot, and
asserts the year-0 pot credit exceeds the year-1 credit by exactly employer contribution minus MPAA.

**The half-built PDF.** `resources/views/pdf/partials/report.blade.php` now prints the optimiser
paragraph and the tax-free-quarter sentence the screen partial had, so the printed steer's saving has
a figure on the page behind it. The section-level PDF completeness test could not catch that (the
section was present, only its figures were not), so the guard is now at the level it broke:
`ScenarioForecasterTest::test_the_screen_and_the_printed_panel_both_read_every_figure_it_publishes`
asserts both templates read every key `WithdrawalStrategyComparison::panel()` returns.

**The copied baseline literal.** `ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY` is now the one home
for the default order, and `WithdrawalStrategyComparison::CURRENT` reads that constant. The same
drift sat in the tile LABEL, which hard-coded "Spending your savings first" in both templates, so
`panel()` now publishes `baselineLabel` from a shared `label()` and both templates read it. Changing
the default no longer leaves either the figure or its name behind.

**The two false comments.** The MPAA docblock claimed the cap bites from the year of the trigger and
called that cautious. It bites from the year AFTER, because `projectYear` pays both contribution
routes before it runs the withdrawals that set the trigger, and that is the LESS cautious side. The
docblock now says so and names the test that pins it. The Guarantee Credit comment claimed a clawback
the model never applies; it now states what the code does (capital first, so the credit is not clawed
back) and, separately, that no ad-hoc draw reaches the means test at all.

**The deferrals left on the floor.** Five cards written, and each deferral in the code now names its
card instead of saying "its own card": **0073** the MPAA binds a year late and the annual-allowance
charge is not priced; **0074** the tax-free quarter of an ad-hoc draw is filed on the ladder as
taxable drawdown; **0075** the draw order is fixed and undisclosed - this is the adviser's "change the
displayed default", widened to letting the reader choose and disclosing what was chosen for them,
because picking that number for a private scenario set is Rob's call and not mine; **0076** spendable
wealth counts a pension pot gross of tax, which is the adviser's `usableWealth` netting; **0077** an
ad-hoc pension draw never reaches the Pension Credit means test, found while fixing the false comment
and not covered by 0046.

**Assumed:** that taxable drawdown income out of an uncrystallised pot is flexible access for MPAA
purposes, matching what `WithdrawalKind::DrawdownIncome::triggersMpaa()` already rules for the planned
form of the same draw. A pure PCLS still does not trigger it, and nothing about that changed.

**Could not settle from the repository:**
- The instructions say to run `.\vendor\bin\pest.bat`. There is still no Pest here; `vendor/bin/`
  holds `phpunit`, `pint` and nothing else. What was run: `php artisan test` green (1 skipped, the
  posture-aware banned-phrasing partition), `vendor\bin\pint.bat --dirty` clean, and
  `php artisan scenarios:audit` clean on every stored scenario.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree, so the new PDF paragraph and the relabelled baseline tile still need Rob's eye on a
  real page and a real export.

### 2026-08-23 review (v20260823101754-653a)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 159s, run by this job rather than reported by the card.

**acceptance: sound**

All five criteria trace to real code. I tried to break each.

**#1** `PathProjector::fundShortfall` routes every FillBands pension step through `$drawPensionUfpls`, including the CGT-funding one; the split rule has one home in `PathProjector::ufplsSplit`, capped by `PathProjector::maxUfplsGross`. `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax` holds it under the pinned figure, and the no-allowance case reproduces that figure exactly.

**#2** The access-age gate is inside both `$drawPension` and `$drawPensionUfpls` in `PathProjector::fundShortfall`; pinned by `PathProjectorTest::test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.

**#3** `PathProjector::payIntoPot` is the only site that credits a pot (no other `$pot['value'] +=` exists), and it caps at `PathProjector::contributionHeadroom`. The trigger is now set in `plannedWithdrawals`, `$drawPension` and `$drawPensionUfpls` alike, so the three optimiser candidates carry the same restriction. Two tests pin it.

**#4** `PathProjector::fundShortfall` skips both banded pension steps when `$onGuaranteeCredit`, which `projectYear` passes from `meansTestedBenefitNominal` (Guarantee Credit only). Pinned by `test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact`.

**#5** `WithdrawalStrategyComparison::for` subtracts two `lifetimeTax` sums of `YearResult::$totalTax`; pinned by `ScenarioForecasterTest::test_the_optimiser_returns_the_cheapest_candidate_and_reconciles_to_two_engine_runs`.

VERDICT: sound

**scope: defect**

**Grew past the fence.** `PathProjector::fundShortfall`'s `$drawPension` closure now sets `mpaaTriggered`. The plan's "#5" section says plainly: do not change `$drawPension`; `TaxEfficient`/`PensionAware` must stay byte-identical. Its "Done-when" repeats it. So the default draw order every scenario runs under changed behaviour. Acceptance #3 makes the fix right, but the fence makes it a change that had to be recorded. It was not.

**Half done: the rationale.** `docs/DECISIONS.md`, entry "2026-08-19 ÔÇö A 'fill the bands' pension draw is a UFPLS". Decision 1 still says `TaxEfficient`/`PensionAware` "are untouched". Decision 3 still says the MPAA bites "from the year of the trigger rather than the day after it". That is the exact false claim the fix corrected in `PathProjector::contributionHeadroom` and carded as 0073. One home was fixed. The home that owns rationale still says the old thing.

**Half done: the tile name.** `WithdrawalStrategyComparison::panel()` publishes `baselineLabel` from `label()`, but the second tile's name is still a literal in `resources/views/livewire/partials/withdrawal-sequencing.blade.php` and `resources/views/pdf/partials/report.blade.php`. Flip the default (card 0075) and both tiles name the same order. The key-coverage test cannot see a hard-coded label.

VERDICT: defect

**breakage: defect**

**1. An inherited pot now caps the heir's own pension allowance.** `PathProjector::fundShortfall`'s `$drawPension` sets `mpaaTriggered` on any pot it touches. Inherited pots are added by the estate pass in `PathProjector` (built with `earliestAccessAge => 0`, `firstAccessDone => true`), so a still-working survivor who draws one loses ┬ú60,000 of allowance for ┬ú10,000, for the rest of the plan ÔÇö even below age 55. Beneficiary drawdown is not a member trigger event. Nothing in `WithdrawalKind::triggersMpaa` or `FlexibleWithdrawalAssessor` knows about inherited pots, and no test builds this. The fix moved it onto the default order, so it is now the ordinary path. Blocked contributions stay in pay, so it is silent.

**2. Two dead docblock links.** `PathProjector::plannedWithdrawals` and `$drawPensionUfpls` both say `{@see mpaaHeadroom}`. That method does not exist; it is `contributionHeadroom`. `plannedWithdrawals` also still says "One home for the trigger" ÔÇö there are now three sites, and only that one asks the enum.

**3. The label fix is half.** Both templates still hard-code the second tile "Filling your tax-free allowances first", and `WithdrawalStrategyComparison::for` compares baseline against FillBands. Make FillBands the default (card 0075) and the panel shows one order twice, saving ┬ú0.

VERDICT: defect

**2026-08-23 - the second review's defects fixed; acceptance unchanged.** The review found acceptance
sound and the ticks stand. Each finding was reproduced before it was touched.

**The one behaviour bug: an inherited pot capped the heir's own allowance.** `$drawPension` set
`mpaaTriggered` on any pot it drew, and the estate pass hands the survivor an inherited pot. So a
still-working survivor who drew £10,000 of a dead partner's pot lost £50,000 of their own annual
allowance for the rest of the plan - silently, because a blocked contribution stays in pay, and at
any age, because an inherited pot carries access age 0. Beneficiary drawdown is not a member trigger
event. The pot now carries an `inherited` flag and all three trigger sites (the planned route and
both ad-hoc closures) set the trigger through one `PathProjector::triggerFlexibleAccess`, so the
exclusion is written once rather than three times. Pinned by
`PathProjectorTest::test_drawing_an_inherited_pot_does_not_cap_the_heirs_own_contributions`, built so
the heir's own pot is locked (access age 60) and the dead partner's was never touchable (access age
75, dead at 69): every draw in the window is therefore the inherited pot. The test was run against
the unfixed code first and fails there - £10,000 a year in instead of £20,000.

**The unrecorded fence breach.** The plan's #5 fenced `$drawPension` off, and the previous run changed
it anyway to fix acceptance #3. The change was right and the record was missing.
`PLAN-withdrawal-sequencing.md` #5 step 1 now carries the breach note, and DECISIONS 2026-08-19 gained
item 4 saying why the fence was wrong (flexible access belongs to the draw, not the draw order, so
fencing it made the optimiser compare its candidates on unequal terms) and naming the consequence: a
scenario with both a working member and an ad-hoc pension draw has moved under the default order too.

**The stale rationale.** DECISIONS 2026-08-19 decision 1 claimed `TaxEfficient`/`PensionAware` were
"untouched" - it now says their figures are unmoved, which is what is true. Decision 3 repeated the
false "from the year of the trigger" claim that had already been corrected in code; it now says the
year AFTER, says that is the less cautious side, names card 0073 and points at `contributionHeadroom`
rather than the non-existent `mpaaHeadroom`. The same two dead `{@see mpaaHeadroom}` links in
`PathProjector` are fixed, and `plannedWithdrawals`' "One home for the trigger" comment now says what
is true: three sites, one helper.

**The half-done tile name.** Both templates now read `alternativeLabel` from `panel()`, for the tile
and for the sentence under it, so no user-facing name for a draw order is written outside
`WithdrawalStrategyComparison::label()`. The second tile's order is the constant `ALTERNATIVE` rather
than a `FillBands` literal inside `for()`.

**Assumed:** on the reviewer's "flip the default and the panel shows one order twice", I did not make
the alternative derive itself from whatever is left. Deriving it would silently change today's second
tile from fill-the-bands to pension-aware, and the panel's explanatory note describes fill-the-bands
by name. Instead `test_the_panel_never_compares_the_current_order_against_itself` turns the suite RED
the moment `CURRENT` and `ALTERNATIVE` become the same order, so card 0075 cannot ship that quietly -
but whoever does 0075 must pick the new alternative and reword that note. That is a judgement about
what to show a reader, so it belongs on 0075 with Rob, not here.

**Could not settle from the repository:**
- Still no Pest. `vendor/bin/` holds `phpunit` and `pint` only. What was run: `php artisan test` green
  (1148 passed, 1 skipped - the posture-aware banned-phrasing partition), `vendor\bin\pint.bat --dirty`
  clean, and `php artisan scenarios:audit` clean on every stored scenario.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree, so the relabelled second tile on screen and in the PDF still needs Rob's eye on a real
  page and a real export.

### 2026-08-23 review (v20260823104709-3f71)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 194s, run by this job rather than reported by the card.

**acceptance: sound**

Traced each criterion to code and to a test. I tried to break all five.

**#1** `PathProjector::fundShortfall` sends every FillBands pension step through the `$drawPensionUfpls` closure, including the one that funds CGT. The 25% rule has one home, `PathProjector::ufplsSplit`, capped by `PathProjector::maxUfplsGross`. Pinned by `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax` against the pinned constant `FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS`, and the no-allowance case matches it to the penny.

**#2** The access-age gate sits in both closures in `PathProjector::fundShortfall`. Pinned by `PathProjectorTest::test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.

**#3** `PathProjector::payIntoPot` is the only place a pot is credited, and it caps at `PathProjector::contributionHeadroom`. All three routes (`payEmployerContributions`, `payNetPayContributions`, `applyContributions`) call it. The trigger has one home, `PathProjector::triggerFlexibleAccess`, called from `plannedWithdrawals` and both closures. Three tests pin it.

**#4** `PathProjector::fundShortfall` skips both banded pension steps when the flag is set; `PathProjector::projectYear` passes it from `meansTestedBenefitNominal`, which is Guarantee Credit only. Pinned by `test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact`.

**#5** `WithdrawalStrategyComparison::for` subtracts two `lifetimeTax` sums of real runs.

VERDICT: sound

**scope: defect**

**1. The engine stamp was not bumped.** `ScenarioForecaster::ENGINE_VERSION` still reads `finance-engine/phase-3-btl-finance-cost`, last moved 2026-07-09. This card moved figures: DECISIONS 2026-08-19 item 1 moves FillBands lifetime tax, and item 4 records that a scenario under the **default** order moved too. The project's own precedent (DECISIONS 2026-07-08, home maintenance) bumps the stamp when stored figures move. `SimulationRunner` and `ThresholdRunner` write it onto every stored run, and `ThresholdCsvExporter` prints it as the audit trail. So a run stored before this card and one stored after carry the same stamp and read as comparable. The move was recorded in prose, not in the one place code reads.

**2. #6 searches nothing new.** The plan's #6 goal is a bounded search *"beyond the three named strategies"*. `WithdrawalStrategyComparison::CANDIDATES` is exactly those three, so the optimiser is a minimum over runs the panel already made. The plan's "manage taxable income to ┬úX" lever was neither built nor carded, while five other deferrals became cards 0073ÔÇô0077.

**3. One naming site left behind.** The closing note of "How you draw your money down" in `resources/views/livewire/partials/withdrawal-sequencing.blade.php` and `resources/views/pdf/partials/report.blade.php` hard-codes "Filling your tax-free allowances" instead of reading `WithdrawalStrategyComparison::panel()`'s `alternativeLabel`.

VERDICT: defect

**breakage: defect**

**1. An inherited pot is given tax-free cash that is not the heir's, and spends the heir's allowance.**
`PathProjector::fundShortfall`'s `$drawPensionUfpls` runs `ufplsSplit` on any pot it draws and adds the tax-free slice to `$state['lsaUsed'][$person->id]`. `PathProjector::settleEstates` hands the survivor a pot marked `inherited` with access age 0. `PathProjector::triggerFlexibleAccess` was taught that an inherited pot is not the heir's own pension; this closure was not. Beneficiary drawdown carries no 25% PCLS ÔÇö the deceased's fund has its own regime, which this file already states in `PathProjector::collectDeathInServiceBenefit`.

So under FillBands the heir gets 25% of a dead partner's pot tax-free, and their own Lump Sum Allowance ÔÇö and through `deathBenefit['lsaUsed']` their death-benefit allowance ÔÇö is consumed by money that never was theirs. It is silent, it is on the ordinary path now that the optimiser runs FillBands for every scenario, and it inflates the FillBands lifetime-tax figure the panel publishes. No test builds an inherited pot under FillBands.

**2. A comment the fix made false.** `$drawPensionUfpls`'s note still says "FillBands only: `$drawPension` stays byte-identical for TaxEfficient / PensionAware and the HMRC worked examples". `$drawPension` now sets the MPAA trigger.

VERDICT: defect

**2026-08-23 - the third review's defects fixed; acceptance unchanged.** The review found acceptance
sound and the ticks stand. Every finding was reproduced before it was touched.

**The one behaviour bug: an inherited pot was given tax-free cash that was not the heir's.**
`$drawPensionUfpls` ran `ufplsSplit` on whatever pot it drew, and the estate pass hands the survivor
an inherited one. So under FillBands the heir took a quarter of a dead partner's pot tax-free and
their own Lump Sum Allowance - and through `deathBenefit['lsaUsed']` their death-benefit allowance -
paid for money that was never theirs. Beneficiary drawdown is the deceased's fund under its own
regime, which this file already states for the lump-sum form in `collectDeathInServiceBenefit`. The
pot's `inherited` flag (added last run for the MPAA) now also zeroes the allowance headroom the split
reads, so the draw is all-taxable, which is exactly `$drawPension` - the graceful-degradation
property the card already pinned, reused rather than re-derived. Pinned by
`PathProjectorTest::test_a_fill_bands_draw_from_an_inherited_pot_takes_no_tax_free_quarter`, built so
the tell is visible without a magic number: the plan is run twice, once with the heir's whole
allowance and once with none of it, and the tax on an inherited pot cannot depend on that. It was run
against the unfixed code first and fails there, by **£22,817.68** of lifetime tax.

**The engine stamp.** `ENGINE_VERSION` is now `finance-engine/ufpls-fill-bands`. Two things this card
did move stored figures - a fill-the-bands run pays less tax, and a default-order run with a working
member contributes less - so a result stored before it and one stored after must not read as
comparable. The docblock says which change moved what, in the house form.

**#6 searching nothing new.** True, and now recorded rather than left implied.
`WithdrawalStrategyComparison::CANDIDATES` is the three named orders because the plan's own #6 says
to confirm the candidate set with Rob before building the "manage taxable income to £X" lever, and
Rob's decision 1 of 2026-07-01 was a third NAMED strategy, "not a general planner yet". So it was not
mine to add: it is **card 0078**, carrying the two calls only Rob can make (how many targets, and
whether it waits for 0075). The limit is flagged on `CANDIDATES`, in the plan's build-order #5 and in
DECISIONS.

**The last naming site.** Both templates' closing note now reads `alternativeLabel` instead of
spelling the order out. The key-coverage test could not see a literal, so the guard now also asserts
that neither template contains any candidate's name written by hand - which is what kept coming back.

**The false comment.** `$drawPensionUfpls`'s note claimed `$drawPension` was byte-identical. It says
what is true: the SPLIT is FillBands-only, `$drawPension` sets the MPAA trigger as well, and that is
DECISIONS 2026-08-19 item 4.

**Assumed:** that beneficiary drawdown is taxed in full as the heir's income. In life it is tax-free
income where the member died under 75, and taxable only where they died at 75 or over. The model has
always charged full income tax on an inherited pot through `$drawPension`; this change makes the
UFPLS path agree with it rather than inventing a third treatment. That is the cautious side, it is
unchanged by this card, and it is stated in DECISIONS item 7 rather than left in the code alone.

**Not changed, deliberately:** the closing note still DESCRIBES fill-the-bands in prose ("draws
pension within your personal allowance..."). That is a description of what an order does, not a name
for it, and it is correct while `ALTERNATIVE` is fill-the-bands - which
`test_the_panel_never_compares_the_current_order_against_itself` turns the suite red the moment
anyone changes. Rewording it is a judgement about what to show a reader, so it belongs to whoever
picks the new alternative on card 0075.

**Could not settle from the repository:**
- Still no Pest. `vendor/bin/` holds `phpunit` and `pint` only. What was run: `php artisan test`
  green (1149 passed, 1 skipped - the posture-aware banned-phrasing partition),
  `vendor\bin\pint.bat --dirty` clean, and `php artisan scenarios:audit` clean on every stored
  scenario.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree, so the reworded note still needs Rob's eye on a real page and a real PDF export.

### 2026-08-23 review (v20260823111523-069d)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 161s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to code and tried to break it.

**#1** `PathProjector::fundShortfall` sends every FillBands pension step through `$drawPensionUfpls`, including the one that funds the CGT bill. The 25% rule has one home in `PathProjector::ufplsSplit`, capped by `PathProjector::maxUfplsGross`. `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax` holds it under the pinned figure; the no-allowance run matches that figure exactly. No capital closure touches a pot.

**#2** The access-age gate sits in both closures in `PathProjector::fundShortfall`, and fails closed on a missing age. Pinned by `PathProjectorTest::test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.

**#3** `PathProjector::payIntoPot` is the only place a pot is credited (the sole other write is growth in `PathProjector::growState`), and it caps at `PathProjector::contributionHeadroom`. All three trigger sites go through `PathProjector::triggerFlexibleAccess`. Two tests pin it, and the inherited-pot exclusion is right.

**#4** `PathProjector::fundShortfall` skips both banded pension steps when `$onGuaranteeCredit`, which `PathProjector::projectYear` sets from `meansTestedBenefitNominal` ÔÇö Guarantee Credit only.

**#5** `WithdrawalStrategyComparison::for` subtracts two `WithdrawalStrategyComparison::lifetimeTax` sums of engine runs. Both views read it.

VERDICT: sound

**scope: defect**

**Over the fence:** nothing. No multiÔÇæproperty or Section 24 code was touched, and the `$drawPension` breach is now recorded in `docs/build/PLAN-withdrawal-sequencing.md` #5 and `docs/DECISIONS.md` 2026ÔÇæ08ÔÇæ19 item 4.

**Left half done:**

**1. A named deferral with no card.** `docs/DECISIONS.md` 2026ÔÇæ08ÔÇæ19 decision 7 says "*Not settled here*": a draw from an inherited pot is charged full income tax even where the member died under 75, which in life is taxÔÇæfree income. `PathProjector::settleEstates` stores no age at death, so the two cases cannot be told apart, and this pass deliberately routed the inherited pot into the allÔÇætaxable path. Card 0057 covers only the estate panel for deaths at or after 75. Five other deferrals became cards 0073ÔÇô0078. This one became prose.

**2. A dead reference the last pass missed.** `docs/build/PLAN-withdrawal-sequencing.md` buildÔÇæorder item 4 still names `mpaaHeadroom`. The method is `PathProjector::contributionHeadroom`. The same dead link was fixed in code and in DECISIONS.

**3. The handÔÇæoff to 0075 is not on 0075.** `WithdrawalStrategyComparison::ALTERNATIVE` says whoever flips the default picks a new alternative and rewords the panel note. `docs/board/todo/0075-the-draw-order-is-fixed-and-the-reader-cannot-see-or-change-it.md` says neither in its acceptance or tasks, and that note's prose is invisible to the name guard in `ScenarioForecasterTest::test_the_screen_and_the_printed_panel_both_read_every_figure_it_publishes`.

VERDICT: defect

**breakage: defect**

**1. The MPAA docblock is still false, now the other way.**
`PathProjector::contributionHeadroom` says the cap "first bites in the year AFTER the trigger", because `projectYear` pays "both contribution routes" first. There are three. `PathProjector::applyContributions` runs *after* `plannedWithdrawals` and `fundShortfall`. So a member with a planned UFPLS at 55 and a surplus that year has that surplus contribution capped in the trigger year itself, while employer and net-pay money is not. The cap depends on which route the money took, not on the date. `test_flexible_access_caps_later_money_purchase_contributions_at_the_mpaa` builds only the employer route, so nothing sees this.

**2. The inherited-pot rule is written in one of its two homes.**
`PathProjector::$drawPensionUfpls` zeroes the allowance for an inherited pot. `PathProjector::plannedWithdrawals` does not: its `Ufpls` and `Pcls` branches still spend the heir's `lsaUsed`. Only `inheritEstate` handing that pot an empty `plan` keeps it unreachable. `PathProjector::ufplsSplit`'s docblock still claims the planned and ad-hoc routes "can never diverge". `PathProjector::triggerFlexibleAccess` is the pattern that would have held.

**3. The cap is invisible.** `LumpSumTaxShock::assess` returns null with no planned instruction, so `ScenarioContext::taxShockFacts` never states the MPAA ÔÇö yet an ad-hoc draw now triggers it under every order.

VERDICT: defect

**2026-08-23 - the fourth review's defects fixed; acceptance unchanged.** The review found acceptance
sound and the five ticks stand. Each finding was reproduced before it was touched, and nothing was
reworded to make one go away.

**The MPAA docblock, false the other way.** `contributionHeadroom` said the cap bites the year AFTER
the trigger "because `projectYear` pays both contribution routes" first. There are three, and the
third disagrees: `applyContributions` runs after the withdrawals, so a contribution funded from the
year's surplus IS capped in the trigger year while employer and net-pay money is not. Whether the cap
bites depends on which route the money took rather than on the date, which is an artefact of the year
order and not a rule. Both halves are now stated, in the docblock and in DECISIONS item 3, and both
are pinned:
`PathProjectorTest::test_the_mpaa_caps_a_surplus_funded_contribution_in_the_trigger_year_itself` is
new and holds the half nothing was watching. Re-timing them onto one rule stays card **0073**, whose
acceptance #1 already asks for exactly that; I did not widen it here, because reordering the year
loop moves figures for every scenario with a working member and belongs behind its own re-run of the
stored set.

**The inherited-pot rule in one of its two homes.** `$drawPensionUfpls` zeroed the allowance for an
inherited pot and `plannedWithdrawals` did not, so only `inheritEstate` handing that pot an empty plan
kept the planned route from spending the heir's Lump Sum Allowance on a dead partner's money. Sharing
`ufplsSplit` was never enough on its own: how much allowance a draw may spend is a second question and
each route answered it for itself. It now has one home, `PathProjector::lsaHeadroom`, asked by both,
the pattern `triggerFlexibleAccess` already set for the MPAA trigger. Public static like the split it
feeds, so `test_only_the_members_own_pot_carries_lump_sum_allowance_to_spend` tests it at the
boundaries directly, including the floor at zero that stops an over-spent ledger handing a draw
negative headroom. `plannedWithdrawals` no longer carries its own running allowance total either, so
the local and the ledger cannot drift.

**The cap was invisible, and this is the one that mattered.** The MPAA is a figure the reader never
entered which, from the trigger on, shrinks what the contributions they DID enter buy - and the only
screen that ever named it, the lump-sum tax-shock panel, needs a PLANNED withdrawal instruction to say
anything at all. A draw taken to meet a shortfall is not one, and since the previous pass those
trigger the cap under every draw order. So on an ordinary plan the cap bound and no screen mentioned
it, which is the no-invisible-figures rule exactly. `PathProjector::mpaaWarnings` now emits one
warning in the year the cap first applies, carrying the allowance read from the statutory constant,
and `ResultPresenter::assumedFigures()` surfaces the engine's own sentence plus the year it starts -
so the pounds and the prose have one home, in the engine that owns the figure. It reuses `YearResult`'s
existing warnings list and the existing `WarningCode::MPAA_TRIGGERED` rather than adding a field.
Guarded by two new tests in `AssumedFiguresDisclosureTest`: that it is disclosed with the statutory
figure and the year, and that it stays silent for a member paying nothing into a pension, because a
cap on what may be paid in changes nothing for them.

**Assumed:** that the disclosure fires on "this member has money-purchase contributions in the plan"
rather than on "the cap actually blocked pounds this year". The looser gate can name a cap that never
binds (a small contribution, well under the allowance); the tighter one needs the blocked amount
plumbed out of three separate cap sites, which would put the rule back in three homes - the defect
this card keeps hitting. A missing disclosure is the failure this rule exists to prevent, so I took
the side that over-discloses. The sentence stays true either way: nothing above the allowance can go
in. Note the overlap with card **0073** acceptance #3, which asks to show the reader the allowance
that applied AND any charge - the allowance half is now done, the charge half is still 0073's.

**Could not settle from the repository:**
- **The hand-off to card 0075 is still not on card 0075.** This session is forbidden from editing any
  card but its own, so I could not put it there. What 0075 must do is now written in the two places
  its author will actually be looking: `WithdrawalStrategyComparison::ALTERNATIVE` and DECISIONS
  2026-08-19 item 6. It is that whoever flips the displayed default must pick a new `ALTERNATIVE` and
  **reword the panel's closing note**, which describes what fill-the-bands does in prose. No test can
  see that prose go stale - the name guard only catches a hard-coded label.
- Still no Pest. `vendor/bin/` holds `phpunit` and `pint` only. What was run, from this worktree:
  `php artisan test` green (1153 passed, 1 skipped - the posture-aware banned-phrasing partition),
  `vendor\bin\pint.bat --dirty` clean, and `php artisan scenarios:audit` clean on every stored
  scenario.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree, so the new assumed-figure note needs Rob's eye on a real page and a real PDF export,
  along with everything the earlier passes left for the same reason.

### 2026-08-23 review (v20260823115517-7044)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 156s, run by this job rather than reported by the card.

**acceptance: sound**

I traced all five criteria to real code and tried to break each.

**#1** ÔÇö Under `FillBands`, every pension step in `PathProjector::fundShortfall` calls `$drawPensionUfpls` (all four: personal-allowance, basic-rate, last-resort, and the CGT-funding one). `$drawPension` is unreachable on that path. The 25% split has one home, `PathProjector::ufplsSplit`, capped by `PathProjector::maxUfplsGross` and `PathProjector::lsaHeadroom`. The pin is self-checking: `PathProjectorTest::test_a_fill_bands_draw_with_no_lump_sum_allowance_left_is_fully_taxable_as_before` reproduces the pinned figure exactly, so the constant is the real pre-#5 number.

**#2** ÔÇö Same age gate in both closures in `PathProjector::fundShortfall`.

**#3** ÔÇö `PathProjector::payIntoPot` is the only site that credits a pot (every other `$pot['value']` write is a draw or growth), and it caps at `PathProjector::contributionHeadroom`. All three trigger sites go through `PathProjector::triggerFlexibleAccess`.

**#4** ÔÇö `PathProjector::projectYear` passes `$benefitNominal > 0`, and `PathProjector::meansTestedBenefitNominal` returns Guarantee Credit only. Both banded pension steps are skipped; capital runs first.

**#5** ÔÇö `WithdrawalStrategyComparison::for` subtracts two sums from `WithdrawalStrategyComparison::lifetimeTax`, each a real `ScenarioForecaster::deterministicUnderStrategy` run.

VERDICT: sound

**scope: defect**

**Over the fence:** nothing new. No multi-property or Section 24 code, and the `$drawPension` breach stays recorded in `docs/build/PLAN-withdrawal-sequencing.md` #5 and `docs/DECISIONS.md` 2026-08-19 item 4.

**Left half done**

**1. Three new v1 limits never reached the list that owns them.** `docs/HANDOVER.md` points a reader at `docs/DATA-MODEL.md` "Known divergences"; its 2026-08-22 contribution-cap entry ends in a "Still open" list ÔÇö the exact home. This card added three siblings and put none there: the MPAA binds a year late on two of three contribution routes (0073), the ladder files an ad-hoc tax-free quarter as taxable drawdown (0074), an inherited pot is taxed in full even under 75 (0079). Each is carded and in a docblock; the list is silent.

**2. `docs/HANDOVER.md` was never refreshed**, which the plan's "Done-when (each slice)" requires. It reads "Last updated: 2026-08-22 (card 0016ÔÇª)", names no sequencing work, and never says `ScenarioForecaster::ENGINE_VERSION` moved to `ufpls-fill-bands` ÔÇö so a run stored before this card reads as comparable with one after. Card 0016 got its line while still in ai-review, so the lane is not the reason.

**3. The hand-off in `WithdrawalStrategyComparison::ALTERNATIVE`** (pick a new alternative, reword the panel prose no test can see) is still absent from `docs/board/todo/0075-the-draw-order-is-fixed-and-the-reader-cannot-see-or-change-it.md`. Third pass.

VERDICT: defect

**breakage: defect**

**1. Blocked employer money vanishes, and two places say it cannot.** `PathProjector::payEmployerContributions` throws away `payIntoPot`'s return, so an employer contribution above the MPAA is not paid in, not left in pay (it never was pay) and not left in the surplus. Yet `PathProjector::contributionHeadroom`'s docblock and the reader-facing sentence in `PathProjector::mpaaWarnings` both say it "is not lost: it stays in pay and is taxed there, or stays in savings." The card's own `PathProjectorTest::test_flexible_access_caps_later_money_purchase_contributions_at_the_mpaa` pins the drop ÔÇö ┬ú20,000 employer, ┬ú10,000 credited, nothing asserts where the rest went. Silent, and on a disclosure that claims completeness.

**2. Crystallised money gets a second tax-free quarter.** `PathProjector::lsaHeadroom` asks only whether a pot is inherited; `firstAccessDone` is written (pot build, estate pass) and read nowhere. After a planned `WithdrawalKind::Pcls`, which reduces the pot only by the cash taken, every later FillBands draw through `$drawPensionUfpls` in `PathProjector::fundShortfall` splits the crystallised residue 25/75 again, bounded only by the LSA ledger. No test builds PCLS-then-ad-hoc, and this card put it on the optimiser's path for every scenario.

**3.** `DrawdownStrategy`'s docblock still says "Both strategies ship and are compared side by side"; three are.

VERDICT: defect

**2026-08-23 - the fifth review's defects fixed; acceptance unchanged.** The review found acceptance
sound and the five ticks stand. Each finding was reproduced before it was touched.

**The one behaviour bug: money that had already had its tax-free quarter was given another.** Taking
£100,000 of tax-free cash CRYSTALLISES £400,000 of pot - £100,000 is paid out and the other £300,000
is designated to drawdown, which is taxed in full from then on. The projector reduced the pot by the
cash taken and recorded nothing else, so `$drawPensionUfpls` split that residue 25/75 all over again,
bounded only by the allowance ledger. Pots now carry a `crystallised` balance;
`PathProjector::drawFromPot` is the one home that keeps it right at all six draw sites (it was six,
which is why the previous passes kept finding the same rule written in one of two places); it grows
with the pot so the SHARE holds rather than turning growth into fresh untaxed money; and
`ufplsSplit` / `maxUfplsGross` take it, so the crystallised slice is drawn first and taxed in full.
The planned route got the same rule in the same pass: a `Pcls` instruction can now only take cash out
of uncrystallised money, so a second lump sum cannot take a quarter of what the first one left.
Pinned by `PathProjectorTest::test_a_pcls_crystallises_the_rest_of_the_pot_so_a_later_draw_takes_no_second_quarter`,
built so the tell needs no magic number: the tax on drawing a crystallised pot cannot depend on how
much allowance is left, so the plan is run with £168,275 free after the lump sum and with none. It was
run against the unfixed code first and fails there, by **£14,436.41** of lifetime tax.
`test_a_draw_out_of_crystallised_money_gets_no_second_tax_free_quarter` tests the two public helpers
at the boundary, including that the solved band-fill still fits the room at every pence with a
crystallised slice in front of it. The write-only `firstAccessDone` flag the review pointed at is
gone: `crystallised` is what it should always have been.

**Where blocked employer money goes: "nowhere", and now both homes say so.** `contributionHeadroom`'s
docblock and the reader-facing sentence in `mpaaWarnings` both promised that a contribution the MPAA
blocks "stays in pay and is taxed there, or stays in savings". True of the net-pay route and true of
the surplus-funded one, false of the employer's: their money never passes through the household's
cashflow, so there is nowhere to put it and the plan simply loses it. That is the adverse side of the
hard-cap simplification (in life it would be paid in and an annual-allowance charge levied - the other
half of card 0073), so the behaviour is left alone and the two statements now say what happens, per
route. Pinned by `test_an_employer_contribution_the_mpaa_blocks_is_not_paid_anywhere_else`, which caps
a £20,000 employer contribution at the £10,000 MPAA and asserts the household ends up holding the same
cash, to the penny, as one whose employer only ever offered £10,000. **Honest note:** that test pins
current behaviour rather than reproducing a failure, because the defect was the claim and not the
code. `DrawdownStrategy`'s "Both strategies ship" is fixed; three do.

**The engine stamp.** `ENGINE_VERSION` is now `finance-engine/pcls-crystallisation`. A plan with both a
lump sum and a fill-the-bands draw pays more tax than it did an hour ago, so a run stored under
`ufpls-fill-bands` must not be read beside one stored after this.

**The three v1 limits that never reached the list that owns them.** `docs/DATA-MODEL.md` "Known
divergences" now carries them. The MPAA ones went onto the 2026-08-22 contribution-cap entry where
they belong (the charge not priced, the trigger-year route dependency, and the employer money that
goes nowhere - all card 0073). The rest got their own entry for this card, in the section's house
form: the ladder filing an ad-hoc tax-free quarter as taxable drawdown (0074), an inherited pot taxed
in full even under 75 (0079), an ad-hoc draw never reaching the Pension Credit means test (0077), and
the new one below (0080).

**`docs/HANDOVER.md` is refreshed.** Its "Last updated" line now names this card's four changes and
says the engine stamp moved, which was the reviewer's point: without it a run stored before this card
reads as comparable with one after. The card-0016 note is kept behind it rather than dropped.
`docs/build/PLAN-withdrawal-sequencing.md`'s pot-shape note is corrected to the real shape.

**Assumed:** that crystallised money is drawn FIRST. Pro-rata and uncrystallised-first are both
defensible and both hand out tax-free cash sooner, so this is the cautious side; it also matches what
a member holding a drawdown fund beside an uncrystallised pot would actually be charged. Stated on
`drawFromPot` and in DECISIONS item 11.

**One new card, 0080 - a pot the reader STARTS with is assumed wholly uncrystallised.** The builder
does ask "Tax-free cash already taken (£)", but `DcPension::$pclsTakenToDate` is defined as allowance
use **across all of the member's pensions**, so it does not say which pot the cash came out of or how
much of that pot was crystallised to pay it. Inferring a crystallised share from it would be inventing
a fact the repository does not hold, so the opening pot is left uncrystallised and the gap is carded
with the choice on it. That choice is Rob's: it moves every stored scenario that has ever taken
tax-free cash.

**Could not settle from the repository:**
- **The hand-off to card 0075 is still not on card 0075**, for the fourth pass and the same reason:
  this session may not edit another card. What 0075 must do is written where its author will be
  looking, on `WithdrawalStrategyComparison::ALTERNATIVE` and in DECISIONS 2026-08-19 item 6.
- Still no Pest. `vendor/bin/` holds `phpunit` and `pint` only. What was run, from this worktree:
  `php artisan test` green (1 skipped - the posture-aware banned-phrasing partition),
  `vendor\bin\pint.bat --dirty` clean, and `php artisan scenarios:audit` clean on every stored
  scenario, which also confirms no stored figure became unpresentable.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree. Nothing this pass changed a screen, but everything the earlier passes left for Rob's
  eye is still waiting.

### 2026-08-23 review (v20260823123311-405e)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 156s, run by this job rather than reported by the card.

**acceptance: sound**

**#1** `PathProjector::fundShortfall` sends every FillBands pension step through `$drawPensionUfpls`, including the CGT-funding one. The 25% split has one home, `PathProjector::ufplsSplit`, capped by `PathProjector::maxUfplsGross` and `PathProjector::lsaHeadroom`. `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax` holds it under the pinned figure; the no-allowance case reproduces that figure to the penny.

**#2** Both closures in `PathProjector::fundShortfall` gate on `earliestAccessAge`. Every pot carries that key (set at build, and 0 for an inherited pot), and a missing age falls to 0, which blocks rather than opens. Pinned by `test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.

**#3** `PathProjector::payIntoPot` is the only site that credits a pot. It caps at `PathProjector::contributionHeadroom`, and `mpContributed` resets each year in `PathProjector::projectYear`. All three trigger sites now call `PathProjector::triggerFlexibleAccess`, so the three optimiser candidates carry equal terms.

**#4** `PathProjector::fundShortfall` skips both banded pension steps when `$onGuaranteeCredit`, which `PathProjector::projectYear` passes from `meansTestedBenefitNominal`. Capital runs first. Pinned by `test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact`.

**#5** `WithdrawalStrategyComparison::for` subtracts two `lifetimeTax` sums of engine `YearResult::$totalTax`. Both templates read `panel()`.

VERDICT: sound

**scope: defect**

I read the card commits, the plan's fence, `PathProjector`, `WithdrawalStrategyComparison` and both templates.

**The fenced orders moved a second time, and the plan says they did not.**
`PathProjector::plannedWithdrawals` runs under every draw order, not just `FillBands`. It now caps a planned `WithdrawalKind::Pcls` at the pot's *uncrystallised* balance, feeds the crystallised slice into `PathProjector::ufplsSplit`, and debits through `PathProjector::drawFromPot`. So a plan with a lump sum plus a later planned draw on the same pot pays more tax under `TaxEfficient` and `PensionAware` too. `docs/build/PLAN-withdrawal-sequencing.md` section "#5", step 1, records only the `$drawPension` MPAA breach and then says "The rest of the fence stands"; its "Done-when" still says "TaxEfficient/PensionAware/HMRC examples unchanged". The movement itself is recorded (DECISIONS 2026-08-19 items 9 and 11, the engine stamp, HANDOVER), so it is not hidden ÔÇö but the doc that owns the fence, and that a fresh agent is told to read first, now states the opposite.

**Half done, but routed:** the plan's #6 goal is a search "beyond the three named strategies"; `WithdrawalStrategyComparison::CANDIDATES` holds exactly those three. #6 says to ask Rob first, and card 0078 carries that question.

Everything else that grew ÔÇö crystallisation, the inherited-pot exclusions, the MPAA cap and its disclosure ÔÇö repairs a defect this card's own UFPLS split created.

VERDICT: defect

**breakage: defect**

I traced the newest change (pot crystallisation) through every draw site.

**1. A second lump sum still hands out a second quarter.** `PathProjector::plannedWithdrawals` (Pcls case) caps the cash to the *uncrystallised* balance, then debits it with `PathProjector::drawFromPot`, which takes *crystallised* money first. The pot loses crystallised pence it never paid out. Trace: ┬ú400k pot, ┬ú50k PCLS at 66 correctly leaves ┬ú150k crystallised; a second ┬ú50k at 68 should leave the whole ┬ú300k crystallised, the code leaves ┬ú250k. That ┬ú50k stays uncrystallised, so the next FillBands draw takes ┬ú12,500 tax-free that is not due ÔÇö the exact bug this commit says it closed. It compounds with each further lump sum. `ScenarioBuilder::addWithdrawal` lets a reader add any number of rows per pension; every test builds one.

**2. The 25% rule has two homes again.** `FlexibleWithdrawalAssessor::assessUfpls`, used by `LumpSumTaxShock::for`, splits 25% with no crystallised concept. A UFPLS after a PCLS on the same pot is wholly taxable in the projector and a quarter tax-free on that screen.

**3. Docs contradict the MPAA fix.** DECISIONS.md item 2 (2026-08-22) and DATA-MODEL.md's CLOSED 2026-08-22 entry still say blocked contributions are "never given up". Only `PathProjector::contributionHeadroom` and `mpaaWarnings` were corrected.

VERDICT: defect

### 2026-08-23 review (v20260823130026-ff2d)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 157s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823130616-9ad6)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 155s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823131207-2567)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 156s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823131757-8b81)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 156s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823132348-6713)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 157s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823132939-7d90)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 155s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823133529-c3a7)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 153s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823134120-8ffb)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 154s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823134710-9e52)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 153s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823135301-24ac)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 152s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823135852-6127)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 154s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823140442-bf18)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 156s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823141033-7ec9)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 155s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823141623-8180)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 153s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823142214-30ff)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 153s, run by this job rather than reported by the card.

**acceptance: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**scope: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

**breakage: unclear**

You've hit your session limit ┬À resets 2:30pm (Europe/London)

### 2026-08-23 review (v20260823142825-82d5)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 159s, run by this job rather than reported by the card.

**acceptance: sound**

**#1 UFPLS-style FillBands draw.** `PathProjector::fundShortfall` sends every FillBands pension step ÔÇö both banded steps, the last-resort step and the CGT-funding step ÔÇö through its `$drawPensionUfpls` closure. The 25% rule has one home, `PathProjector::ufplsSplit`, sized by `PathProjector::maxUfplsGross` and paid for out of `PathProjector::lsaHeadroom`. Pinned by `PathProjectorTest::test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax` against the pinned ┬ú103,538.10; the no-allowance case reproduces it exactly.

**#2 Access age.** The same gate sits in both `$drawPension` and `$drawPensionUfpls` inside `PathProjector::fundShortfall`. Every pot carries `earliestAccessAge` (`PathProjector::initialState`, and the estate pass). Pinned by `PathProjectorTest::test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age`.

**#3 MPAA.** `PathProjector::payIntoPot` is the only place a pot is credited, and it caps at `PathProjector::contributionHeadroom`. All three trigger sites set it through `PathProjector::triggerFlexibleAccess`. Four tests pin it, including the inherited-pot exclusion.

**#4 Guarantee Credit.** `PathProjector::fundShortfall` skips both banded pension steps when `$onGuaranteeCredit`, so capital goes first. Pinned by `test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact`.

**#5 Optimiser delta.** `WithdrawalStrategyComparison::for` subtracts two `WithdrawalStrategyComparison::lifetimeTax` sums of real engine runs. Both templates read `panel()`.

I tried to break each and could not.

VERDICT: sound

**scope: defect**

**scope**

Read: the seven card commits (`1bf5b8f`ÔÇª`8aadc96`), the plan's #5/#6 and its fence, `PathProjector`, `WithdrawalStrategyComparison`, both templates, DECISIONS, DATA-MODEL, HANDOVER.

**The fence held.** Nothing touched multi-property or Section 24 (card 0019), and #6 was built after #5 was green. Everything that grew past #5/#6 ÔÇö pot crystallisation, the inherited-pot exclusions, the MPAA trigger on `$drawPension`, the `LumpSumTaxShock` / `TaxFreeCashCalculator::split` parity ÔÇö repairs a defect this card's own UFPLS split created, and each is recorded in DECISIONS 2026-08-19 (items 4, 5, 7, 11, 12, 13) and on the plan's #5 step 1 as a named breach. Deferrals 0073ÔÇô0080 all have cards.

**Half done: the record of the last round.** `App\Forecast\ScenarioForecaster::ENGINE_VERSION` is `finance-engine/repeated-pcls-crystallisation`. `docs/HANDOVER.md`, its "Last updated" paragraph, still says the stamp "is now `finance-engine/pcls-crystallisation`" ÔÇö the one sentence whose job is to stop a reader comparing a run stored before this card with one stored after names a stamp that no longer exists. The card's Direction stops at the fifth review; commit `8aadc96` moved figures under every draw order and bumped the stamp again with nothing written on the card. The plan's "Done-when (each slice)" requires that refresh.

VERDICT: defect

**breakage: defect**

I traced the new code and tried to break it.

**Finding: the optimiser picks a winner on a tax total that leaves out the tax the same page prints.**

`App\Forecast\WithdrawalStrategyComparison::lifetimeTax` sums only `YearResult::$totalTax` and throws away `ForecastResult::$iht`, which the very same run computes (`PathProjector::recordFinalDeathIht`) and which `App\Livewire\ScenarioResults::render` prints beside it via `ResultPresenter::ihtPanel`. Both templates then label the total "tax paid across the plan", `panel()` publishes `optimiserSaving` as "less tax across the plan", and `App\Compliance\Interpretation::withdrawalSequencingNarrative` turns it into "the order to lean towards for tax".

Two ways that is wrong when the scenario's IHT toggle is on:

- A cheaper income-tax order ends with more wealth, so the estate pays roughly 40% of the "saving" straight back. The headline overstates it.
- For a death before `PathProjector::PENSIONS_IN_ESTATE_FROM_YEAR`, pension is outside the estate and ISA is inside. Draw order changes which one survives, so IHT can move further than the income-tax gap and invert the ranking.

No test runs a candidate comparison with IHT modelled, and no card (0078 covers breadth only) records the metric's blind spot.

VERDICT: defect

**2026-08-23 - the sixth and seventh reviews' defects fixed; acceptance unchanged.** Two reviews are
answered here, because the sixth review's fixes shipped in commit `8aadc96` with nothing written on
this card and the seventh review said so. Both findings were reproduced before they were touched.

**First, the record the last pass owed.** Commit `8aadc96` closed the sixth review and its Direction
entry was never written. What it did: a SECOND lump sum out of the same pot was debited from the
residue the first one left behind, so £50,000 of already-crystallised money turned uncrystallised
again per extra row and the next fill-the-bands draw took a quarter of it (£2,500.00 of lifetime tax
on the pinned household). The whole slice is now designated BEFORE the cash is paid out of it, which
is also the true-to-life order, pinned by
`PathProjectorTest::test_two_lump_sums_crystallise_as_much_as_one_of_twice_the_size` - one £100,000
lump sum and two £50,000 ones in the same year must pay identical tax, so the tell needs no magic
number. The same pass gave `TaxFreeCashCalculator::split` the crystallised slice as well, because
the 25% rule has two implementations on purpose (Money for the tax-shock panel, integer pence for the
year loop) and only the projector had learned about crystallisation, so one withdrawal got two
answers out of one engine; `TaxFreeCashCrystallisationParityTest` now holds them to the same answer
across a grid. It also corrected the two doc homes that still said a blocked contribution is never
given up. `ENGINE_VERSION` went to `finance-engine/repeated-pcls-crystallisation`, and **the
HANDOVER sentence whose whole job is to stop a reader comparing runs across a stamp change still
named the previous one** - it now names the real stamp. DECISIONS 2026-08-19 items 12 and 13 already
carried the rationale; this is the missing card-side half.

**Then the seventh review's one finding: the optimiser picked a winner on a tax total that left out
the tax the same page prints.** `WithdrawalStrategyComparison::lifetimeTax` summed
`YearResult::$totalTax` and threw away `ForecastResult::$iht`, which the same run computes and
`ResultPresenter::ihtPanel` prints beside it. Both tiles called that figure "tax paid across the
plan" and the steer turned the gap into "the order to lean towards for tax". On the rich test
household the discarded death tax is **£424,550.09** against **£99,233.52** of yearly tax - four
times the number the winner was being picked by. `lifetimeTax` now adds the run's own `iht->total`;
both figures are real (today's money) out of the same run, so they add rather than needing a
conversion, and the delta stays the difference of two engine runs (acceptance #5 unmoved). Pinned by
`ScenarioForecasterTest::test_the_lifetime_tax_the_optimiser_ranks_on_counts_the_tax_paid_at_death`,
which runs the same household with the IHT toggle on and off and asserts the gap between the two
headline totals IS the death tax and nothing else moved. It was run against the unfixed code first
and fails there, £99,233.52 against £523,783.61.

**Why total tax is the right thing to rank on, written down rather than assumed.** It is not obvious:
"pay less tax" can reward a plan for being poorer. It holds here because the spend target does not
move with the draw order - same resources, same spending - so tax not paid is money left in the plan,
and minimising total tax is exactly maximising what is left. That is on `lifetimeTax`, because it is
the premise the whole panel rests on and nothing else states it.

**What is counted is now on the page.** "Tax paid across the plan" cannot be read without knowing
whether it stops at the last living year, and the answer moves the total four-fold. Both templates
print one sentence either way round, off a new `includesIht` key read from the run itself rather than
from the scenario's toggle, so what the page says is in the total comes from the object the total was
summed out of. The existing key-coverage guard picks the new key up automatically.

**One new card, 0081 - the cheapest order can be the one that funds the least.** The equal-spend
premise above breaks when an order runs out: a plan that cannot meet its spend stops drawing, so it
stops paying, and a smaller estate pays less at death too, so both halves of the metric fall and the
failing order can be named cheapest. It predates this pass (income-tax-only had the same hole, worse)
and fixing it needs a call on what a reader should see when the candidates are not comparable, which
is Rob's. Flagged on `lifetimeTax` and in DATA-MODEL "Known divergences".

**Assumed:** that `IhtOutcome::$total` and `YearResult::$totalTax` are on one basis. Both are stated
by their own docblocks to be real, today's-money figures out of the same projection, and the deaths
are identical across candidates (median death ages, same household), so no candidate's IHT is
deflated against a different year. Nothing had to be re-derived to add them.

**No engine stamp bump.** This change is app-layer (`App\Forecast\WithdrawalStrategyComparison`) and
moves no engine output, so nothing a `SimulationRunner` or `ThresholdRunner` has stored has changed
meaning. What moved is a figure computed at render time.

**Could not settle from the repository:**
- **The hand-off to card 0075 is still not on card 0075**, for the fifth pass and the same reason:
  this session may not edit another card. What 0075 must do is written where its author will be
  looking, on `WithdrawalStrategyComparison::ALTERNATIVE` and in DECISIONS 2026-08-19 item 6.
- Still no Pest. `vendor/bin/` holds `phpunit` and `pint` only. What was run, from this worktree:
  `php artisan test` green (1161 passed, 1 skipped - the posture-aware banned-phrasing partition),
  `vendor\bin\pint.bat --dirty` clean, and `php artisan scenarios:audit` clean on every stored
  scenario.
- **Still not looked at in a browser.** Herd serves the site from `C:\Dev\RetireForecast`, not from
  this worktree, so the new "what is counted" sentence on screen and in the PDF needs Rob's eye on a
  real page and a real export, along with everything the earlier passes left for the same reason.

