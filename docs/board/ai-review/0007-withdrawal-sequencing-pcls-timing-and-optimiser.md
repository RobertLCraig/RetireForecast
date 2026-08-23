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

