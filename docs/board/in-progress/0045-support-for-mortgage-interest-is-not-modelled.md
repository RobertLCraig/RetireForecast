# The cheapest borrowing available to a pensioner is not in the engine

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Support for Mortgage Interest appears nowhere in the engine, the config or the board. It is the
first instrument a benefits caseworker reaches for when the problem is an unaffordable secured debt
in later life, which is precisely the problem the tool is modelling.

A Pension Credit Guarantee Credit claimant gets it with **no waiting period**, as a loan covering
interest on eligible capital up to a cap at the DWP standard rate, secured by a charge and repaid
on sale or death. For a pension-age claimant, eligible housing costs also include service charges
and ground rent, which matters on a leasehold flat.

The rate is roughly a third of a commercial lifetime-mortgage roll-up rate on the same security. It
is being compared against those products and it is not in the comparison.

The mechanic already exists: a rolled-up balance with a no-negative-equity cap, as
`Property::mortgageRollUpRate` and `growState` already do.

## Not this card
Whether the household qualifies. That depends on Pension Credit, which is a separate question.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a household receives Guarantee Credit and carries an eligible mortgage, THE APP SHALL meet the interest at the DWP standard rate up to the capital cap.
- [x] #2 THE APP SHALL accrue what is met as a separate charge against the property, repaid on sale or death.
- [x] #3 THE APP SHALL include eligible service charges and ground rent in the pension-age housing costs it covers.
<!-- AC:END -->

## Tasks
- [x] Model the loan as a second roll-up balance at the sourced DWP rate, capped
- [x] Gate it on a Guarantee Credit year
- [ ] Source the rate and the capital cap, with `source` and `verified_on`
- [x] Deduct the balance from the estate at death
- [x] Show it alongside the equity-release comparison

## Comments

**2026-09-06**
RESULT: done
TESTS: +9 new, all green
TOUCHED:
packages/finance-engine/src/Benefits/SupportForMortgageInterest.php (new)
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Forecast/YearResult.php
packages/finance-engine/tests/Forecast/SupportForMortgageInterestTest.php (new)
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
tests/Unit/Forecast/InputNotesTest.php
docs/board/in-progress/0045-support-for-mortgage-interest-is-not-modelled.md
docs/board/todo/0109-pin-the-smi-standard-rate-and-capital-cap.md (new)
docs/HANDOVER.md
docs/HANDOVER-ARCHIVE.md
docs/DECISIONS.md
docs/DATA-MODEL.md
docs/spec/ASSUMPTIONS.md
docs/spec/METHODOLOGY.md
OUT-OF-SCOPE: 0109

**What is built.** `Benefits\SupportForMortgageInterest` owns the two figures and the arithmetic:
interest met at the DWP standard rate on capital up to the pension-age cap, plus the pension-age
housing costs in full. `PathProjector` gates it on a year where the Guarantee Credit award is
actually positive and the household still owns the home, takes the amount met off both the spend
target and the essential floor, and adds the SAME figure to a new `state['smiBalance']`. That balance
rolls up each year, is redeemed out of the proceeds of a forced sale, and is passed to `EstateValuer`
beside the mortgage at both deaths. `YearResult::smiBalance()` reports it and `homeEquity()` nets it,
so the wealth line and the estate cannot flatter a household whose home is being spent.

**Three judgement calls, all erring the same way, all in DECISIONS 2026-09-06.** What is met is
never credited as income: it is a loan paid to the lender, and crediting it would have put it in the
cashflow ladder, in the secure-income floor and in the care means test, none of which is true of
money the household never touches. The interest met is capped at the interest ACTUALLY charged that
year, so a rolled-up lifetime mortgage is met nothing (there is no cash liability to meet), which is
also the right answer for the comparison this card exists to enable: SMI is the ALTERNATIVE to
equity release, not a subsidy of it. And the charge is not capped at the home's value the way a
lifetime mortgage is, because the debt is real even where the security cannot bear it; the write-off
happens in the zero floor on equity and on the estate.

**The task I could not do: sourcing.** This session had no web (WebSearch is refused in an unattended
card session), so the DWP standard rate and the capital cap are STATED, not verified, and unlike card
0044's figures **both of these reach a projection**. The rate is shipped at the LOW end of the range
the published rule has produced since 2018, by the standing adverse-default rule; the consequence is
that SMI is modelled as less attractive against equity release than it probably is, never more. Both
constants carry a SOURCING GAP block, ASSUMPTIONS §21 says the same, and card **0109** carries
pinning them. That is why the third Task above is left open while the acceptance is ticked: the
criteria are about the BEHAVIOUR, which is built and tested, and the criterion text names the DWP
standard rate rather than a value.

**Stored scenarios move.** `ENGINE_VERSION` is `finance-engine/support-for-mortgage-interest` and the
re-run is owed. `scenarios:audit` was run and reports no new problem class (every line is the
standing "run N carries no integrity stamp"). The Monte Carlo golden master did NOT redden: its
frozen household owns its home outright and its two full State Pensions put it above the guarantee,
so it never qualifies.

**What is not modelled, and is stated on the screen.** Whether the household would in fact claim.
The card scoped the gate to a Guarantee Credit year, so the model always takes the loan where the
credit is in payment; a reader who would not put a charge on their home is currently modelled as one
who would. The result note says so in as many words, along with the rate, the cap, the first year's
help and the balance it grows to. Also not modelled: the separate gilt-linked rate DWP charges on
the loan itself (the charge rolls up at the standard rate instead), and service-charge exclusions
beyond the utilities carve-out the bucket already has.

**Built in a worktree, so the new result note has not been seen in a browser.** No builder input was
added, so there is nothing new to type in; the note is the only new thing to look at.

Two blocks were folded out of `docs/HANDOVER.md` into the archive (cards 0033 and 0034) to keep the
live brief under the size a fresh session can load, as the orient hook asked.
