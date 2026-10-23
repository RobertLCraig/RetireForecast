# An uncapped DB pension is cut in a deflation year

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 1 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Found by the 2026-09-05 breakage review of card 0035, repeated by the 2026-09-28 manager pass, and
still true on 2026-10-06. `PensionEscalationBasis::increase()` floors the two capped cases at zero,
because a scheme does not cut a pension when prices fall. `Cpi` and `Rpi` are not floored. Monte
Carlo inflation is an unclamped normal draw (`ReturnModel::generatePath()`), so deflation years
happen. In one, a CPI pension is cut and a 5%-capped one is not, so the capped pension pays more.
That inverts the order `DbEscalationTest::test_each_escalation_basis_reaches_a_different_twenty_year_income`
pins on a positive path.

Whether a CPI-linked scheme may reduce a pension in payment in deflation is a rule to confirm
against a primary source (scheme rules and the Pensions Act revaluation orders) before the floor
is added.

## Links

**Relates to**
- `0035` - added the floor on the capped cases only.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a year's inflation is negative, THE APP SHALL not pay a CPI- or RPI-escalated DB pension more or less than the sourced rule allows, and a 5%-capped pension SHALL never pay more than the same scheme on plain CPI. proves: `test_a_deflation_year_does_not_put_a_capped_db_pension_above_plain_cpi`
<!-- AC:END -->

## Plan
Source the rule, then apply the same `max(0.0, ...)` floor in `PensionEscalationBasis::increase()`
to `Cpi` and `Rpi` if it holds.

## Comments

**2026-10-22** RESULT: blocked
TESTS: +0 (proves: test written, watched red, not committed)
TOUCHED: none
OUT-OF-SCOPE: none
The fault is real. I wrote test_a_deflation_year_does_not_put_a_capped_db_pension_above_plain_cpi in packages/finance-engine/tests/Forecast/DbEscalationTest.php: dbIncomeByYear(Cpi, -2.0) vs dbIncomeByYear(CpiCappedAt5, -2.0), assertLessThanOrEqual on [2046]. It failed for the card's reason: the capped pension stays at 2000000p while plain CPI falls to 1335216p.
I did not apply the floor. The card's Plan says to source the rule first (scheme rules and Pensions Act 1995 s.51 / the revaluation and Pensions Increase orders) and floor Cpi and Rpi only if it holds. This unattended session has no web: WebFetch of legislation.gov.uk/ukpga/1995/26/section/51 was refused. The repository holds no source for whether a CPI- or RPI-linked pension in payment may be cut in deflation (docs/research and ASSUMPTIONS §18 cite s.51 for the capped cases only). The red test was reverted, so nothing is committed.
Next: an attended session with web sources the rule and writes it into ASSUMPTIONS §18, then re-adds the test above and the max(0.0, ...) floor on Cpi and Rpi in PensionEscalationBasis::increase(). The fix moves Monte Carlo figures, so expect an ENGINE_VERSION bump and a GoldenMasterTest re-pin.

**2026-10-22** RESULT: blocked
TESTS: +0, nothing changed
TOUCHED: none
OUT-OF-SCOPE: none
Second take, same block as the first. The Plan says to source the rule before adding the floor. This unattended session has no web again: WebFetch of legislation.gov.uk/ukpga/1995/26/section/51 was refused. A grep of docs/ (outside the board) still finds no source on whether a CPI- or RPI-linked pension in payment may be cut in deflation. I did not add the floor from memory, because the card asks for a sourced rule.
The first take's red test (dbIncomeByYear(Cpi, -2.0) vs CpiCappedAt5, assertLessThanOrEqual on [2046]) still describes the fault and can be re-added as-is.
Next: do this card in an attended session with web, or mark it not_for_the_loop. Source s.51 Pensions Act 1995 and the Pensions Increase (Review) Orders, write the rule into ASSUMPTIONS §18, then add the test and the max(0.0, ...) floor on Cpi and Rpi in PensionEscalationBasis::increase(). Expect an ENGINE_VERSION bump and a GoldenMasterTest re-pin.

**2026-10-22** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 1 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-23 manager (m20261023002228-49fc)

**outcome: reopen**

Both takes stopped only because they had no web to find the rule; the rule is now found and written above, so the builder needs no web.

**what the session said**

The criterion is still open, and the law it was waiting for is now sourced. The builder can finish it.

**The rule:** a UK CPI- or RPI-linked pension in payment is frozen when prices fall. It is never cut.

- In the 12 months to September 2015, CPI was −0.1%. Public service pensions in payment were frozen from April 2016. No Pensions Increase (Review) Order was issued that year ([NHS circular, Feb 2016](https://pensions.gov.scot/sites/default/files/2019-04/NHS%20-%20Circular%20Indexation%20CARE%20Feb%202016.pdf); [LGPS circular, Mar 2016](https://bsol.pensions.gov.scot/sites/default/files/2019-06/LGPS%20Circular%20-%20%20Public%20Service%20Pension%20Indexation%20and%20CARE%20Scheme%20revaluation%20Mar%202016.pdf)).
- [Pensions Act 1995 s.51(2)](https://www.legislation.gov.uk/ukpga/1995/26/section/51) only says a pension "must be increased annually by at least" a percentage. Nothing in it allows a cut.

**What the builder does:**
1. Write this rule into `docs/spec/ASSUMPTIONS.md` §18, with these URLs and verified_on 2026-10-23.
2. Put the red test back into `DbEscalationTest`, as the first take wrote it.
3. In `PensionEscalationBasis::increase()`, wrap the `Cpi` and `Rpi` cases in `max(0.0, ...)`.
4. Bump `ENGINE_VERSION`, re-pin `GoldenMasterTest`, and write a DECISIONS entry.

WHY: Both takes stopped only because they had no web to find the rule; the rule is now found and written above, so the builder needs no web.

OUTCOME: reopen

**2026-10-23** RESULT: done
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/Dto/PensionEscalationBasis.php
TOUCHED: packages/finance-engine/tests/Forecast/DbEscalationTest.php
TOUCHED: docs/spec/ASSUMPTIONS.md
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: none
Watched test_a_deflation_year_does_not_put_a_capped_db_pension_above_plain_cpi fail first: at -2% CPI the plain-CPI pension fell to 1335216p against an accrued 2000000p. It also asserts an RPI pension is frozen and the 5%-capped one is not above plain CPI.
Fix: max(0.0, ...) on Cpi and Rpi in PensionEscalationBasis::increase(). The rule and the manager's URLs are in ASSUMPTIONS §18, verified_on 2026-10-23.
The floor also applies to revaluation in deferment, because increase() serves both phases. I read the sourced freeze as covering that; noted in §18.
GoldenMasterTest stayed green (its frozen run has no CPI DB pension in a deflation year), so I did not re-pin. ENGINE_VERSION bumped to finance-engine/cpi-db-pension-frozen-in-deflation; DECISIONS 2026-10-23 entry added. The Monte Carlo re-run is owed for plans with such a pension.
No screen changed, so no browser check is needed.
