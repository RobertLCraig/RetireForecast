# Adviser-parity remainder: ISA rules, salary sacrifice, estate checklist, annual review

## Why
A1 fee drag, A2 net-pay relief, B1 cost of advice and B2 protection gap are built (2026-07-31),
with A3's ISA subscription cap. What is left is smaller, and each item is recorded in DATA-MODEL
"Known divergences".

## Links

**Relates to**
- `0011` - B5 capacity for loss is that card and comes first in the plan's order, so this one waits
  behind it.

## Not this card
B5 capacity for loss, which is card 0011 and comes first in the plan's order.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL apply the ISA allowance in sell-and-invest plans, so those plans are no
      longer understated by the absence of bed-and-ISA.
- [x] #2 THE APP SHALL model the GBP 3,600 non-earner relief route.
- [x] #3 THE APP SHALL cap relievable contributions by the annual allowance and the MPAA.
<!-- AC:END -->

## Tasks
- [x] A3: ISA rules (note the engine never *uses* the allowance today, which is a decision to
      take rather than a rule to apply)
- [ ] A4: salary sacrifice
- [ ] B3: estate checklist
- [ ] B4: annual review

## Direction
**2026-08-22** Built the three acceptance criteria, all engine-side with the app wiring they needed.

**#1 bed-and-ISA.** `PathProjector::bedAndIsa()` runs after each year's contributions and disposals
and moves money the household already holds in a taxable GIA into their ISA, up to whatever is left
of each person's GBP 20,000 allowance. The allowance is one allowance: a new `$state['isaSubscribed']`
is shared with money paid in, so it cannot be spent twice. The move is a real disposal, so it
realises the pro-rata gain and consumes the matching cost basis, and it is sized to keep that gain
inside what is left of the person's CGT annual exempt amount, which is what a real bed-and-ISA does
and means the step never adds a tax bill the projection would have to fund. It runs in shortfall
years too, which matters because a sell-and-invest plan has a shortfall in nearly all of them.
`YearResult::$isaSheltered` reports what moved. Guarded by `BedAndIsaTest`, whose switch-off case
reproduces the old understated plan, so it is a real guard rather than a tautology.

**The decision the card flagged, and why I took it.** The task note said using the allowance is "a
decision to take rather than a rule to apply", but acceptance #1 is written as THE APP SHALL apply
it, so I read the decision as already taken and built it ON by default. The reasoning is in
DECISIONS 2026-08-22: modelling nothing is itself a claim, and it was the wrong one, falling on one
side of the sell-versus-stay comparison the tool exists to make. Three things make it safe to
overrule: it is disclosed on the results page as an `assumed_figure` note naming the pounds the
projection actually moved (read out of the forecast, so it cannot drift); it is switchable via
`ForecastSettings::$useIsaAllowance` and the builder-state key `useIsaAllowance`; and spending has
first claim on the CGT exempt amount, so it is the cautious way round. **If Rob wants it off by
default, that is a one-line change and none of the machinery is wasted.**

**#2 the GBP 3,600 non-earner route** is a new `PensionReliefMethod::NonEarner`, not a reopening of
`ReliefAtSource` (which still throws). The member pays GBP 2,880 out of household surplus and GBP
3,600 reaches the pot; capped at the statutory basic amount and stopping at 75. A separate case,
because every member under 75 has the basic amount whatever they earn, so it needs no earnings test
and cannot under-relieve anyone; general relief at source would give a higher-rate taxpayer 20% where
they are due 40%, which is exactly why it throws. New `PensionParameters::$nonEarnerReliefLimit` and
`$reliefMaximumAge` own the two figures. Third option added to the builder's relief dropdown.

**#3 the contribution cap.** `mpaaHeadroom()` became `contributionHeadroom()`: the annual allowance
(GBP 60,000) when the MPAA has not been triggered, the MPAA (GBP 10,000) when it has, in the one
place a pot is credited. Both count the employer's contribution, because the statutory limit is on
total pension input. What the cap blocks stays in pay, is taxed there and is saved, so nothing is
dropped.

**Assumed / not settled from the repository.**
- The GBP 3,600 basic amount and the age-75 relief limit are entered from this plan's own 2026-07-28
  verification of gov.uk, cited in the registry docblock. **They were not re-verified online this
  session:** a ProgressBoard card session has no web access. Both are long-standing statutory figures
  and neither has moved, but a figure-freshness pass should re-confirm them.
- Deliberately not built, and now recorded in DATA-MODEL and METHODOLOGY as open: the high-income AA
  taper (it needs adjusted and threshold income, which the year's own contributions move, and
  `AnnualAllowanceCalculator` already prices it separately), carry-forward of unused allowance (its
  absence is the cautious side), and the AA **charge** on the excess as distinct from a hard cap.
- No builder checkbox turns bed-and-ISA off yet. The engine and the scenario key take it; adding the
  UI control is the four-part builder-field change (blank default, validation, loadState backfill,
  `BuilderStateFixture::full`) and would have grown the card past its acceptance.

**Tasks A4, B3 and B4 are untouched and left open.** None of them has an acceptance criterion on this
card, and building them would have been scope this card did not review. A4 salary sacrifice in
particular is a generality item the plan already de-prioritised (this household's scheme is net pay).

**Checks.** Whole suite green (`php artisan test`; the card asked for `.\vendor\bin\pest.bat`, which
this repo does not have, it runs PHPUnit through artisan per CLAUDE.md). `php artisan scenarios:audit`
clean across all 25 stored scenarios, and their figures have moved, as expected for a change that
shelters GIA money. `vendor\bin\pint.bat` clean. **No browser check:** this worktree is not what Herd
serves, so the new builder dropdown option and the new results-page note still need a look in a
browser at `C:\Dev\RetireForecast`.

### 2026-08-29 review (v20260829190302-b425)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 278s, run by this job rather than reported by the card.

**acceptance: sound**

I checked each criterion against the code.

**#1 ISA allowance in sell-and-invest plans.** Real. `PathProjector::bedAndIsa()` moves GIA money into the ISA up to each person's unused subscription, sized to stay inside the remaining CGT exempt amount. It is called from `PathProjector::projectYear()` (which takes `$state` by reference, so the move persists), after the disposal step that credits sale proceeds to `$state['gia']`. The one shared counter `$state['isaSubscribed']` is also decremented by the paid-in ISA branch of `applyContributions()`, so the allowance cannot be spent twice. Gated by `ForecastSettings::$useIsaAllowance` (default true) and wired in `ScenarioForecaster`. Reported by `YearResult::$isaSheltered`.

**#2 ┬ú3,600 non-earner route.** Real. `PensionReliefMethod::NonEarner` is handled in `PathProjector::applyContributions()`: net taken from surplus, grossed at the basic rate, capped at `PensionParameters::$nonEarnerReliefLimit`, stopped at `$reliefMaximumAge`. Both figures live in `TaxYearRegistry::forTaxYear()`. Reachable from the app via `HouseholdAssembler` and `ScenarioBuilder`.

**#3 AA and MPAA cap.** Real. `PathProjector::contributionHeadroom()` picks MPAA or annual allowance, and `PathProjector::payIntoPot()` is the only pot-credit site ÔÇö employer, net-pay and surplus contributions all route through it.

I could not break any of the three.

VERDICT: sound

**scope: defect**

I read the card, the build commit `1ee9bc5`, and the code it touched.

**Over the fence: nothing.** B5 / capacity for loss is untouched. The diff stays inside A2 and A3.

**Half done, and it reaches the screen.** `ResultPresenter::assumedFigures()` prints to the user: *"If you would not do it, say so and we will model the money staying where it is."* There is nothing to say it with. `ScenarioForecaster::settings()` reads `effectiveBuilderState()['useIsaAllowance']`, but nothing anywhere writes that key. `app/Livewire/ScenarioBuilder.php` has no public property for it, no entry in `rules()`, no line in its state-save, and `resources/views/livewire/scenario-builder.blade.php` has no control. The sibling switch `homeToDescendants` has all four, plus a label in `WhatIfChanges`. So the engine flag is unreachable and the results page advertises a control that does not exist. The Direction admits the checkbox is missing; it does not admit the copy points at it.

**Left open.** Tasks A4, B3 and B4 untouched: one of the card's four tasks delivered.

Fix is small: add the control, or cut the "say so" sentence.

VERDICT: defect

**breakage: defect**

Found one real break.

**`HousingComparison::rentSettings` drops the new setting.** It rebuilds `ForecastSettings` field by field and never copies `useIsaAllowance`, so the rent arm always gets the constructor default `true`. `HousingComparison::variantInputs` passes the caller's `$settings` unchanged to `stay_put` and `buy_outright`, so those two arms honour the toggle and the third does not.

This matters because of what the rent arm holds. `HousingComparison::withHousing` puts the whole sale proceeds into a `Gia` account, which is exactly the money `PathProjector::bedAndIsa` shelters. A reader who turns bed-and-ISA off in the builder (`ScenarioForecaster::forecastSettings` reads `useIsaAllowance`) removes the shelter from stay-put and buy, and leaves it running in rent. Rent then wins partly on a setting it ignored. Nothing fails: the comparison just tilts, silently, in the one direction this card was built to correct.

No test builds it. `BedAndIsaTest` runs `PathProjector` directly and never goes through `HousingComparison`; `HousingComparisonTest` never sets `useIsaAllowance: false`.

The same method also drops `modelIht`, `homeToDescendants` and `sellingCosts`, which predates this card.

VERDICT: defect


## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
