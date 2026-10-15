# An undrawn pension pot is invisible to the Pension Credit means test

## Why
`PathProjector::meansTestAssessableCapital` assesses liquid wealth and a let property. A money
purchase pot the member has never touched is left out entirely.

Under State Pension age that is correct. Over it, it is not: an untaken pot is taken into account,
and the usual treatment is **notional income** (the income the member could have drawn from it)
rather than nothing. So the engine over-awards Guarantee Credit for the commonest case there is, a
pensioner sitting on a pot they have not started, and everything that passports off Guarantee Credit
is over-awarded with it: Council Tax Reduction, Housing Benefit and Support for Mortgage Interest.

Card 0051 listed it as a Known divergence (docs/DATA-MODEL.md) rather than fixing it, which is what
that card's acceptance allowed. This card is the fix.

The blocker is a figure, not the arithmetic. The notional income is computed at a published rate,
and card 0051's session had no web access, so it could not fetch one. This project does not put an
unsourced figure into a projection.

## Links

**Relates to**
- `0051` - listed the divergence and left the fix here.
- `0045` - Support for Mortgage Interest, one of the awards that rides on Guarantee Credit.

## Not this card
Money already drawn out of a pot. That is cash, it is already assessed as capital, and the two must
not double-count.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a claimant over State Pension age holds an undrawn money-purchase pot, THE APP SHALL add notional income from it to the Pension Credit assessable income. proves: `test_an_undrawn_pot_is_notional_income_for_a_claimant_over_state_pension_age`
- [ ] WHEN the claimant is under State Pension age, THE APP SHALL leave the pot out of the means test. proves: `test_an_undrawn_pot_is_ignored_below_state_pension_age`
- [ ] THE APP SHALL carry the notional income rate with its source URL and verified-on date. proves: none
<!-- AC:END -->

## Tasks
- [ ] Fetch the rate and its source. **Needs web, so not an unattended card.**
- [ ] Add the figure to `BenefitsParameters` with its source and verified-on date.
- [ ] Apply it in the Pension Credit assessable income, not the capital tariff, and prove the drawn
      part is not counted twice.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario: any plan holding an undrawn pot in a
      Pension Credit year moves, and moves DOWN.
- [ ] Close the Known divergences entry in docs/DATA-MODEL.md.

## Plan
The means test is `PathProjector::pensionCreditAward` and `meansTestAssessableCapital`; the figures
live on `packages/finance-engine/src/TaxYear/BenefitsParameters.php` and
`TaxYearRegistry::benefitsParameters()`. Pots are `DcPension` on the household. Run
`php artisan test` and `php artisan scenarios:audit`.

## Comments

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

The card's first task needs the notional income rate from the web, and its own Tasks line says so: "Needs web, so not an unattended card." This session tried WebSearch and the permission was refused, as in every unattended card session. The repository has no sourced figure for it: a grep for notional income, GAD rate and annuity rate finds only the 0051 divergence note, the DATA-MODEL Known divergences entry and the unrelated purchase-annuity table (cards 0065 / 0140).

I did not build the arithmetic around a null or placeholder rate. The project rule is no unsourced figure in a projection, and a test written against an invented rate would prove nothing about the real one. So #1 and #2 have no test yet, and #3 (rate with source URL and verified-on date) cannot be met.

To unblock: run this card in an attended session with web access, or have /research fetch the rate first. What it must settle: the State Pension Credit Regulations 2002 reg 18 notional income rule for an unused money-purchase fund, and the rate table DWP uses for it (believed to be GAD-based, NOT verified here), with the gov.uk or legislation.gov.uk URL. The rest of the card (BenefitsParameters field, applying it in PathProjector::pensionCreditAward assessable income, the no-double-count test against drawn cash, ENGINE_VERSION bump, closing the DATA-MODEL divergence) is ordinary engine work once that figure exists. Suggest marking the card not_for_the_loop: so the scheduler stops picking it.

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block as the first. The session asked to WebFetch legislation.gov.uk (State Pension Credit Regulations 2002 reg 18) and the permission was refused, so the notional income rate and its source still cannot be fetched here. The repository still has no sourced figure: 'notional income' appears only in the docs/DATA-MODEL.md Known divergences entry, and docs/research/ has nothing on it.

I did not build the arithmetic around a placeholder rate. A test against an invented rate proves nothing about the real one, and the project rule forbids an unsourced figure in a projection. So no criterion is met.

To unblock: run /research in an attended session for the reg 18 notional income rule for an unused money-purchase fund over qualifying age, and the DWP rate table it uses (believed GAD-based, not verified), with the legislation.gov.uk or gov.uk URL. Then the rest is ordinary engine work. This card should carry not_for_the_loop: so the scheduler stops picking it. That is a card edit, and I am not allowed to make it.

**2026-10-15** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-15 manager (m20261015192014-7bf6)

**outcome: rob**

The only thing blocking this card is a figure from the web, and only an attended session can get it.

**what the session said**

This card needs one fact from the web. That fact is the notional income rate from the State Pension Credit Regulations 2002, reg 18, with its source URL. ("Notional income" means the income a person could take from a pension pot they have not touched.) An unattended session cannot use the web, so both builder takes stopped at the same point.

I looked in the repository. No sourced rate exists yet: not in `docs/research/`, and not on `BenefitsParameters` in `packages/finance-engine/src/TaxYear/BenefitsParameters.php`. All three criteria are still open, and no review finding disproves any of them.

If you reopen the card, the loop will block again for the same reason. This is not a question about what you prefer. It is a job that needs a session with web access. Run `/research` in an attended session to get the reg 18 rule, the DWP rate table and the legislation.gov.uk URL. Then move the card back to `todo/`. The rest is ordinary engine work.

Also add `not_for_the_loop:` to the card until the research lands.

WHY: The only thing blocking this card is a figure from the web, and only an attended session can get it.

OUTCOME: rob

