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
- [x] #1 THE APP SHALL uprate the State Pension by the highest of national earnings growth, inflation and the floor. proves: `test_the_earnings_limb_wins_when_it_beats_prices_and_the_floor`
- [x] #2 THE APP SHALL carry the national earnings assumption as a sourced figure, separate from any person's own salary growth. proves: `test_a_persons_salary_growth_override_does_not_move_the_state_pension`
- [x] #3 WHEN the earnings assumption is the engine's own, THE APP SHALL disclose it with its value. proves: `test_the_assumed_national_earnings_growth_is_disclosed_with_its_value`
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

### 2026-10-11 manager (m20261011002627-495d)

**outcome: rob**

Every box on the card needs one earnings figure that someone looks up on the web, and the unattended loop cannot do that, so only an attended session can move this card.

**what the session said**

The card is not done. It needs a session that can use the web, and the unattended loop cannot use the web.

I checked the code. `StatePensionUprating::increase()` still only looks at inflation and the 2.5% floor. Its own comment still says the earnings part is missing. All 3 boxes on the card are still empty.

Sending it back to the loop does not help. Both loop runs stopped because they could not look up a national earnings growth figure, which the card needs with a source and a "checked on" date. Nobody needs to make a choice first. The project rule already says: use the most cautious figure, and let the user change it.

**What you do:** Start an attended session (you are there to approve it) in `C:\Dev\RetireForecast`, with web search allowed. Give it this card. It can look up the earnings figure and then build the change.

WHY: Every box on the card needs one earnings figure that someone looks up on the web, and the unattended loop cannot do that, so only an attended session can move this card.

OUTCOME: rob


**2026-10-13** Research done in an attended session with web access: `docs/research/RESEARCH-0100-national-earnings-growth.md` holds the sourced national earnings real-growth default, its URL and verified_on date, and the property, override key and label to add. Build from that note; it needs no web. Committed with this card as its evidence. Moved to todo/ for the loop. (agent, after Rob's 2026-10-13 clear-down)

**2026-10-14** RESULT: done
TESTS: +3 new, all green
TOUCHED: app/Finance/Mapping/AssumptionSetMapper.php, app/Forecast/AssumptionOverrides.php, app/Forecast/ResultPresenter.php, app/Forecast/ScenarioForecaster.php, app/Livewire/ScenarioBuilder.php, docs/DECISIONS.md, docs/HANDOVER.md, docs/spec/ASSUMPTIONS.md, packages/finance-engine/src/Assumptions/AssumptionSetLibrary.php, packages/finance-engine/src/Dto/AssumptionSet.php, packages/finance-engine/src/Forecast/DeterministicPathDraws.php, packages/finance-engine/src/Forecast/HistoricalSequenceDraws.php, packages/finance-engine/src/Forecast/PathDraws.php, packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/src/MonteCarlo/SampledPathDraws.php, packages/finance-engine/src/StatePension/StatePensionUprating.php, packages/finance-engine/tests/Forecast/CareCostInflationTest.php, packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php, packages/finance-engine/tests/Forecast/CouncilTaxTest.php, packages/finance-engine/tests/Forecast/OwnerAttributionTest.php, packages/finance-engine/tests/Forecast/StatePensionUpratingTest.php, packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php, resources/views/livewire/scenario-builder.blade.php, tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
OUT-OF-SCOPE: none

Built from docs/research/RESEARCH-0100-national-earnings-growth.md, no web needed. AssumptionSet::$nationalEarningsRealGrowth (0.25% on the default and OBR sets, cited to OBR EFO March 2026; 0.75% on the DMS set, cited to ONS AWE KAI7/D7BT 2000-2025; verified_on 2026-10-11) with a FigureSource row, mapper round-trip, override key nationalEarningsGrowth (KEYS, apply, presetFigures, builder field + validation, results assumptions row). PathDraws::nationalEarningsRealGrowth() in all three draws (not sampled) and the four engine test doubles. StatePensionUprating::increase() takes the earnings rate as its third limb under the lock; Inflation and after-TripleLockUntil stay prices alone (card 0099's question).

Watched fail: #1 red at 2.5% where 3.02% was due. #3 red on the old 'we do not model the earnings part' note. #2 was green before the change, because nothing wired salary to the State Pension; I watched it fail by temporarily wiring the limb to salaryGrowthReal, then restored it.

The Monte Carlo golden master moved (essentials success 0.4500 to 0.4550, terminal wealth up at p25/p75/p90), as the research predicted for paths with inflation above about 2.24%. Re-pinned with PIN_REVISION 2026-10-14 and a DECISIONS entry. ENGINE_VERSION is finance-engine/triple-lock-earnings-limb.

Still owed by a person: re-seed the assumption_sets table (AssumptionSetSeeder), or the stored rows lack the key, hydrate it as null, and the limb adds nothing; scenarios:audit reports it until then. Then re-run stored scenarios. The new builder field, results assumptions row, triple lock note and builder help text are NOT seen in a browser: this worktree is not what Herd serves.
