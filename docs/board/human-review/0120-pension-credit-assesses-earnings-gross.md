# Pension Credit assesses earnings gross, with no earnings disregard

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

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

**2026-10-15** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: none
OUT-OF-SCOPE: none

Second take, no change. WebFetch and WebSearch were both refused again in this unattended session, so nothing could move.

Criteria 2 and 3 still need the single, couple and higher weekly earnings disregards with a source URL and verified-on date (SI 2002/1792 Schedule VI is the likely source). The repository holds no copy of them, and no unsourced figure was added.

Criterion 1 is still not claimed for the reason the first take gave: a relief-at-source contribution is paid out of the year's surplus after the award, so it is not yet halved and deducted. Closing it also needs the regulation's wording on whether the sum halved is the net payment or the grossed-up contribution, which I could not check without web, so I did not build it.

What stands from the first take (commit 3f4e04a): earnings are assessed net of income tax, NI and half a net-pay contribution; ENGINE_VERSION finance-engine/pension-credit-assesses-earnings-net; the scenario re-run is owed. Engine suite re-run here and green.

This card cannot advance unattended. It needs a session with web access, or Rob pasting the disregard figures and the contribution wording with their source.

**2026-10-15** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-16 manager (m20261016031034-e9a3)

**outcome: rob**

The rest of the card waits on sourced figures from the web, and an unattended session cannot fetch them.

**what the session said**

The loop cannot finish this card by itself. It needs a person.

**What I checked:** `BenefitsParameters` has no earnings disregard yet. It has only the capital disregard. So criteria 2 and 3 are still open, as the two takes said.

**Why not `reopen`:** the builder runs unattended, and an unattended session cannot use the web. Both takes stopped at the same place, because the web search was refused. If I send the card back, it returns to this queue again.

**Why not `question`:** nothing here is a choice that only you can make. The missing part is three published figures and a source for each.

**What you do now (pick one):**
1. Run this card in a session that has web access. I recommend this one.
2. Paste the single, couple and higher weekly earnings disregards into the card, with the source URL. Also paste the regulation's wording on the pension contribution: is half of the net payment taken off, or half of the grossed-up amount? Then move the card to `todo/`.

The likely source is SI 2002/1792, Schedule VI.

WHY: The rest of the card waits on sourced figures from the web, and an unattended session cannot fetch them.

OUTCOME: rob

