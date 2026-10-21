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
- [x] #1 THE APP SHALL carry the household's spending guardrail through every `ExpenseProfile` the essential and discretionary spend levers rebuild. proves: `test_the_spend_levers_carry_the_spending_guardrail`
<!-- AC:END -->

## Plan
Route both levers through one public wither on `ExpenseProfile` that goes through `copy()`, so no
field can be dropped by a lever again. Expect the sustainable-spend answers for a guardrail plan to
move.

## Comments

**2026-10-21** RESULT: done
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/Dto/ExpenseProfile.php
packages/finance-engine/src/Sweep/Lever/EssentialSpendLever.php
packages/finance-engine/src/Sweep/Lever/DiscretionarySpendLever.php
packages/finance-engine/tests/Sweep/SweepLeversTest.php
packages/finance-engine/tests/Dto/ExpenseProfileWitherTest.php
app/Forecast/ScenarioForecaster.php
docs/HANDOVER.md
OUT-OF-SCOPE: none

Watched test_the_spend_levers_carry_the_spending_guardrail fail first: the essential lever returned a null guardrail. Added the public wither ExpenseProfile::withFlatSpend(?essential, ?discretionary), which sets the swept tier flat and rebuilds through copy(); both levers now call it, so no field can be dropped by a lever again. This also carries tenantRent, which the levers had dropped too (it charges nothing, it only marks the referencing question). ExpenseProfileWitherTest now lists withFlatSpend and its fixture sets spendingGuardrail and tenantRent, so the reflection guard sees them. ENGINE_VERSION bumped to finance-engine/spend-levers-keep-the-spending-guardrail, following card 0028's precedent, so stored thresholds and sustainable-spend answers on guardrail plans are owed a re-run. Golden master unmoved (no guardrail in it). No screen changed, so no browser check is needed.
