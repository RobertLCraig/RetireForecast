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

**2026-10-10** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

The card's Plan says it needs a session with web access, and this unattended session has none: WebSearch was refused ("requested permissions... you haven't granted it yet"), as the project memory unattended-sessions-have-no-web predicts. All three criteria rest on one sourced figure, a national average-weekly-earnings real-growth default with a source and verified_on, so none can be met honestly here. I did not build the plumbing with a placeholder or null default: that would change no result and would put an unsourced number (or an empty control) in front of the reader, against the no-invisible-figures and no-magic-numbers rules.

What the repo already has, checked so the attended run need not re-read it:
- No national earnings series exists. AssumptionSetLibrary's set-level salaryGrowth (1.0% Base and Low, 1.5% Historical) is cited to OBR_MACRO_SOURCE (OBR EFO March 2026) and labelled 'Real salary growth'; the card rules it out as the household's own pay. Copying its value under a new key would restate an unverified figure, so I did not.
- CARE_COST_SOURCE (PSSRU/LSE) escalates care on earnings at about 2% real. That is a care-cost assumption, not an earnings series.
- Under the adverse-default rule, the cautious default for this limb is the LOWEST plausible real earnings growth, because a higher figure raises the State Pension. The attended run should state the sourced alternatives (ONS AWE regular-pay history, the OBR medium-term earnings forecast) and pick the most adverse one as the editable default.

Where the change goes once the figure exists:
- packages/finance-engine/src/StatePension/StatePensionUprating.php:65 increase(float $inflation, int $calendarYear, ?int $untilYear): add the earnings argument; the docblock at lines 12-27 names the gap.
- packages/finance-engine/src/Forecast/PathProjector.php:5318 (growState) is the only caller; line 790 also describes the uprating.
- PathDraws seam: packages/finance-engine/src/Forecast/PathDraws.php:85 careCostRealGrowth() is the pattern to copy, implemented in DeterministicPathDraws.php:133, HistoricalSequenceDraws.php:138 and MonteCarlo/SampledPathDraws.php:111.
- AssumptionSet.php:217 careCostRealGrowth() is the accessor pattern; AssumptionSetLibrary::economicSourcing() (around line 345) needs a new FigureSource row; AssumptionOverrides::KEYS needs the key.
- ResultPresenter::assumedFigures() disclosure sentence, docs/spec/ASSUMPTIONS.md around line 360-369, and ScenarioForecaster::ENGINE_VERSION, as the card's Tasks list.

This card wants Rob or an attended session with web access. Handing it to the unattended loop again will hit the same wall.

**2026-10-10** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take, same wall. WebSearch and WebFetch were both refused again ("requested permissions... you haven't granted it yet"). All three criteria rest on one sourced national average-weekly-earnings real-growth default with a source and verified_on, so none can be met honestly here. I did not build the plumbing behind a placeholder or null default, for the reasons the first take gave.

One new check: the repo holds no sourced national earnings MEAN either. docs/spec/ASSUMPTIONS.md cites the ONS AWE bulletin (line 826) only to sanity-check the 2.0% real-earnings VOLATILITY of the salary-growth shock (item 7, around line 89), not a growth rate. So the figure cannot be borrowed from the existing docs.

The first take's map of where the change goes is still accurate. This card needs an attended session with web access, or Rob supplying the figure and its source. Re-queuing it to the unattended loop will fail a third time; mark it not_for_the_loop.

**2026-10-10** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
