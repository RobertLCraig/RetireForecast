# The triple lock is modelled as a double lock

## Why
The triple lock raises the State Pension by the **highest of three** things: average weekly
earnings growth, CPI inflation, and 2.5%. The engine models two of them. `StatePensionUprating`
takes the higher of the year's inflation and the 2.5% floor, and the earnings limb is absent.

Earnings have outrun prices in most non-crisis years, so the limb that is missing is often the one
that would have won. The State Pension is therefore modelled LOW, which errs the cautious way, but
it is still wrong in a place the tool cares about: the State Pension and the Pension Credit
guarantee are the whole secure-income floor for the household this tool exists to model, and the
capacity-for-loss and "essentials always met" readings are all measured against that floor.

Card 0038 named the gap rather than closing it, because the engine has no national earnings series
to close it with. The only earnings figure it carries is `AssumptionSet::$salaryGrowth`, which is
the household's OWN real salary growth: an assumption about one couple's pay, entered per person
in some scenarios and overridden per person in others. Using it as a national uprating basis would
model one thing with another, and would make a household that expects a big pay rise also expect a
bigger State Pension, which is not how the policy works.

So this needs a national average-weekly-earnings growth assumption of its own, sourced, on the
`AssumptionSet` beside the other economic series, and an unattended session has no web to source
it with.

## Links

**Relates to**
- `0038` - built the uprating rule and disclosed the omission on the results page. The `increase()`
  method is the single place a third limb would go.
- `0099` - what the default uprating basis should be. That decision is about the policy surviving;
  this one is about what it pays while it does, and they push opposite ways, so read both before
  changing either.
- `0084` - the general problem that a card needing a looked-up figure cannot be done by the
  unattended loop. This is an instance of it.

## Not this card
Whether the lock survives, and for how long. That is card 0099.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL uprate the State Pension by the highest of national earnings growth, inflation and the floor. proves: `test_the_earnings_limb_wins_when_it_beats_prices_and_the_floor`
- [ ] #2 THE APP SHALL carry the national earnings assumption as a sourced figure, separate from any person's own salary growth. proves: `test_a_persons_salary_growth_override_does_not_move_the_state_pension`
- [ ] #3 WHEN the earnings assumption is the engine's own, THE APP SHALL disclose it with its value. proves: `test_the_assumed_national_earnings_growth_is_disclosed_with_its_value`
<!-- AC:END -->

## Tasks
- [ ] Source a national average-weekly-earnings real-growth assumption (ONS AWE regular pay is the
      obvious series; the OBR's medium-term earnings forecast is the forward-looking one).
- [ ] Add it to `AssumptionSet` with its source and `verified_on`, and to
      `AssumptionOverrides::KEYS` so the reader can edit it like every other rate.
- [ ] Carry it to the projector. `PathDraws` is the seam the other set-level figures use
      (`careCostRealGrowth`, `investmentChargeRate`), and all three implementations take an
      `AssumptionSet` already.
- [ ] Widen `StatePensionUprating::increase()` to take the year's earnings growth as its third
      limb, keeping the `TripleLockUntil` and `Inflation` cases as they are.
- [ ] Rewrite the last sentence of the assumed-figure disclosure in
      `ResultPresenter::assumedFigures()`, which currently says the earnings limb is not modelled.
- [ ] Update `docs/spec/ASSUMPTIONS.md` §19 and delete the bullet naming this gap.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and re-run stored scenarios: every plan with a
      State Pension gains income, so its wealth and success odds rise.

## Plan
Work in `C:\Dev\RetireForecast` on `master`. The rule lives in one file,
`packages/finance-engine/src/StatePension/StatePensionUprating.php`, and its only caller is
`PathProjector::growState`. The engine fixture is
`packages/finance-engine/tests/Forecast/StatePensionUpratingTest.php`, which already runs each
choice at 1% and 5% inflation and can take an earnings rate the same way.

**This card needs a session with web access** to source the earnings series, which the unattended
loop does not have. Run it attended.

## Comments
