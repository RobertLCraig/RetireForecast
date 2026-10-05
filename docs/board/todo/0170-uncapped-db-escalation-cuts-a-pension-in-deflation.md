# An uncapped DB pension is cut in a deflation year

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
- [ ] #1 WHEN a year's inflation is negative, THE APP SHALL not pay a CPI- or RPI-escalated DB pension more or less than the sourced rule allows, and a 5%-capped pension SHALL never pay more than the same scheme on plain CPI. proves: `test_a_deflation_year_does_not_put_a_capped_db_pension_above_plain_cpi`
<!-- AC:END -->

## Plan
Source the rule, then apply the same `max(0.0, ...)` floor in `PensionEscalationBasis::increase()`
to `Cpi` and `Rpi` if it holds.

## Comments
