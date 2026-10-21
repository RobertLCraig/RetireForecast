# The spend levers drop the spending guardrail

## Why
Found building card 0028 on 2026-10-05. `EssentialSpendLever::apply()` and
`DiscretionarySpendLever::apply()` each rebuild `ExpenseProfile` by hand with `new ExpenseProfile(...)`
and do not pass `spendingGuardrail`. A reader who turned the guardrail on (card 0063) gets a base run
that cuts discretionary spend when the plan is underfunded, and a sustainable-spend answer
(`SustainableSpend`, `LeverThresholdService`) computed as if they never cut back. Both docblocks say
the rest of the profile is carried unchanged.

This is the same drift `ExpenseProfile::copy()` exists to stop. The levers are the last two hand-built
rebuild sites, so `ExpenseProfileWitherTest` does not see them.

## Links

**Relates to**
- `0028` - fixed the same drop for `propertyCostsRealGrowth` in these two levers.
- `0063` - the guardrail itself.

## Not this card
- Changing how the guardrail behaves.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL carry the household's spending guardrail through every `ExpenseProfile` the essential and discretionary spend levers rebuild. proves: `test_the_spend_levers_carry_the_spending_guardrail`
<!-- AC:END -->

## Plan
Route both levers through one public wither on `ExpenseProfile` that goes through `copy()`, so no
field can be dropped by a lever again. Expect the sustainable-spend answers for a guardrail plan to
move.

## Comments
