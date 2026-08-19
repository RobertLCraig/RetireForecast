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
