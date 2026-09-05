---
not_for_the_loop: the answer is a judgement about what to assume, and it moves every stored plan
---
# What should the tool assume about the triple lock when nobody has said?

## What I need from you

**One answer.** Which of these should the tool assume when the reader has not chosen?

1. **The full triple lock for the whole plan.** What ships today, and what every stored plan has
   always been run on.
2. **The lock to a year, then prices alone.** Name the year.
3. **Prices alone.**

---

**Pass:** you name one, and if it is 2 you name the year. **Fail:** if you would rather not decide,
say so and it stays on option 1, which is where it is now and which is disclosed on every results
page as the cheerful choice.

**Why it needs you.** This is not a figure to look up. It is a guess about whether a government
policy survives forty years, the answer moves every plan the tool has ever produced, and the two
plausible answers point opposite ways. Nobody can settle it by reading.

## Why
Card 0038 gave the reader the choice and disclosed the default. It deliberately did not change the
default, because the acceptance did not ask for it and moving it silently would have moved every
stored figure with nobody having decided.

The default that remains is the **optimistic** one. The triple lock lifts the State Pension by at
least 2.5% a year, inflation is modelled near 2%, so the floor binds in most years and the State
Pension grows in **real** terms for the whole projection. The Pension Credit guarantee, uprated by
the same running factor, rises with it, so the benefit floor moves too.

That is the reverse of the standing rule on this project: where several figures are plausible, take
the most adverse and let the reader raise it. The 2.5% floor is government policy rather than law,
and no government has committed to it beyond the current Parliament, so the plan here runs for
decades on a promise that covers a few years of them.

Working the other way, and worth knowing before you answer: the tool models only two of the lock's
three limbs. The real lock is the highest of earnings, prices and 2.5%, and the earnings limb is
not modelled at all (card 0100). So the current default is optimistic about the policy surviving
and pessimistic about what the policy pays while it does.

## Links

**Relates to**
- `0038` - built the three-way choice, the engine rule and the disclosure. Everything needed to act
  on your answer is already there; only the default is open.
- `0100` - the earnings limb of the lock is not modelled, which pushes the State Pension the other
  way. Read the two together before choosing an adverse default.

## Not this card
The choice itself, the engine rule, the builder control and the disclosure. All built and tested
under 0038.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL assume the uprating basis Rob names when the reader has chosen none. proves: `test_the_default_state_pension_uprating_is_the_one_that_was_decided`
- [ ] #2 WHEN the default changes, THE APP SHALL bump `ENGINE_VERSION` and every stored scenario SHALL be re-run. proves: none, because a re-run is an operation and not a behaviour
<!-- AC:END -->

## Tasks
- [ ] Set the default in `ForecastSettings::$statePensionUprating` (and `$tripleLockUntilYear`),
      which is the one place it is decided.
- [ ] `ForecastSettings::statePensionUpratingIsAssumed()` says whether the default is in play; it
      currently tests for `TripleLock`, so it moves with the default.
- [ ] Rewrite the disclosure in `ResultPresenter::assumedFigures()`: it says the assumption is the
      cheerful one, which stops being true under options 2 and 3.
- [ ] Update `docs/spec/ASSUMPTIONS.md` §19.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and re-run every stored scenario: every plan with a
      State Pension moves, and so does every Pension Credit award.

## Plan
Work in `C:\Dev\RetireForecast` on `master`. The rule is
`packages/finance-engine/src/StatePension/StatePensionUprating.php` and nothing about it needs
changing; only which case is the constructor default of `ForecastSettings`. The engine tests are
`packages/finance-engine/tests/Forecast/StatePensionUpratingTest.php`, and the disclosure tests are
in `tests/Unit/Forecast/AssumedFiguresDisclosureTest.php`. Run `php artisan test` first; it must be
green before you start.

## Comments
