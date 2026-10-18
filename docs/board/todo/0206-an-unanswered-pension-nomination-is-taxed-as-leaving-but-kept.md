# An unanswered pension nomination is taxed as leaving the household but kept by the survivor

## Why
Found while building card 0133. That card made `PathProjector::settleEstates` drop a pot whose
nomination is answered `SomeoneElse`, so the tax and the settlement now agree for that case.

An UNANSWERED nomination (`DcPension::$nominatedBeneficiary` null) still disagrees with itself.
`DcPension::nominatedToSpouse()` reads null as "not the spouse", so the first death charges the pot
as leaving the household: no spouse exemption, and the beneficiary's income tax. But card 0133 kept
null passing to the survivor, because dropping it moves every default couple plan at once (18 tests
went red when it was tried). So a default plan pays first-death tax on a pot it then keeps, draws
and counts again in the final estate. That is the same double count card 0133 removed, on the
default path.

One of the two halves has to move. Either the settlement drops an unanswered pot too (the adverse
answer, matching the tax), or the tax treats an unanswered pot as passing to the partner (matching
the settlement). Which is right turns on what a scheme actually does with no expression of wish,
and on how adverse Rob wants the default to be.

## Links

**Relates to**
- `0133` - fixed the answered case and deliberately left this one, because it changes every default plan.
- `0057` - chose the adverse reading of an unanswered nomination for the tax.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a DC pot's nomination is unanswered, THE APP SHALL treat it the same way in the first death's tax and in the settlement, so a pot is never both charged as leaving and kept. proves: `test_an_unanswered_nomination_is_taxed_and_settled_as_the_same_event`
<!-- AC:END -->

## Plan
Pick the half to move first: research what UK schemes do with no expression of wish (scheme
discretion, usually the spouse or dependants), and apply the adverse-default rule only if the
research leaves it open. `InheritedPensionForecastTest` has the couple and the
`aroundTheFirstDeath` helper; pass `null` as the nomination. Bump `ENGINE_VERSION`.
