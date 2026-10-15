# Pension Credit assesses earnings gross, with no earnings disregard

## Why
`PathProjector::pensionCreditAward` builds its assessable income out of `$taxablePerPerson`, which
is the GROSS figure. Pension Credit does not work that way. Earnings are assessed **net** of income
tax, National Insurance and half of any occupational or personal pension contribution, and then a
small weekly **earnings disregard** comes off what is left.

The engine applies neither, so a household with any earnings at all is assessed on more income than
the DWP would assess it on, and is therefore **under-awarded**. That is the safe direction, but it
is still wrong, and it bites exactly the household this tool is about: one partner still working
while the other has retired.

It also weakens card 0046's near-miss warning, whose message already says the engine "applies no
income disregards" and so the real gap can be smaller. That sentence is an apology for this defect.

## Links

**Relates to**
- `0051` - found it in the expert-panel review and left it here; it needs sourced figures.
- `0046` - the near-miss warning that exists partly because of this gap.

## Not this card
Non-earned income. This is the earnings rule only.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a member has earnings, THE APP SHALL assess them net of income tax, National Insurance and half of any pension contribution. proves: `test_pension_credit_assesses_earnings_net`
- [ ] THE APP SHALL apply the weekly earnings disregard before the guarantee is compared. proves: `test_the_earnings_disregard_comes_off_assessable_earnings`
- [ ] THE APP SHALL carry each disregard figure with its source URL and verified-on date. proves: none
<!-- AC:END -->

## Tasks
- [ ] Fetch the single, couple and higher earnings disregards with a source. **Needs web, so not an
      unattended card.**
- [ ] Add them to `BenefitsParameters` beside the guarantee figures.
- [ ] Assess earnings net in `pensionCreditAward`, taking the tax and NI the year already computed
      rather than recomputing them, so one quantity keeps one definition.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario: any plan with earnings in a Pension
      Credit year moves, and moves UP.
- [ ] Revisit the near-miss warning copy, which currently apologises for this.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php` (`pensionCreditAward`) and
`packages/finance-engine/src/TaxYear/BenefitsParameters.php`. The projector already holds the year's
per-person tax and NI, so the netting should read those. Run `php artisan test` and
`php artisan scenarios:audit`.

## Comments

**2026-10-15** RESULT: partial
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/PathProjectorTest.php
app/Forecast/ScenarioForecaster.php
docs/DECISIONS.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

Built: Pension Credit now assesses earnings net of income tax, NI and HALF a net-pay pension contribution (it used to take earnings gross of tax and NI and net of the WHOLE contribution). test_pension_credit_assesses_earnings_net was watched failing at the gross figure (80912 vs 149500, i.e. GBP 15.56/wk vs 28.75/wk) before the fix. ENGINE_VERSION is finance-engine/pension-credit-assesses-earnings-net; the re-run is owed for plans with earnings in a Pension Credit year.

Why criterion 1 is NOT claimed: a contribution paid out of the year's surplus (relief-at-source and non-earner routes, applyContributions) is settled AFTER the award, so it is not yet halved and deducted. Only net-pay is. That is the under-award (adverse) side. Closing it needs the award re-asked once the surplus contribution is known, like the pension-draw re-ask.

Assumed (DECISIONS 2026-10-15): the tax pass has no per-source split, so the earnings' share of tax is the tax on the earnings alone through the same band core, capped at the year's tax. This gives earnings the personal allowance first (as PAYE does for the main job), the smaller deduction and so the adverse side. NI is read straight from the year's figure. The card asked to read the year's tax rather than recompute it; for an earner with no other non-savings income the two are the same, but with a pension beside the earnings there is nothing per-source to read. Rob may prefer another split.

Blocked: criteria 2 and 3 need the single, couple and higher earnings disregard figures with a source URL and verified-on date. WebFetch was refused in this unattended session, so nothing was fetched and no unsourced figure was added. A session with web access must fetch them (SI 2002/1792 Schedule VI is the likely source) and add them to BenefitsParameters.

Not done: php artisan scenarios:audit was not run (it reads Rob's live database and the re-run is owed anyway). The near-miss warning copy (card 0046) still apologises for 'no income disregards', which stays true until the disregard lands. No UI changed, so no browser check is owed. Suite run with php artisan test (no pest.bat exists here); pint clean.
